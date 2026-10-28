<?php
declare(strict_types=1);

namespace TuWhatsApp;

use DateTimeImmutable;
use PDO;
use RuntimeException;

/**
 * Llamadas de WhatsApp desde el panel (Calling API de Meta). El audio va directo entre el navegador de quien atiende
 * y Meta (WebRTC); este servidor solo pasa la "señalización" (las ofertas y respuestas SDP) y guarda el historial.
 *
 *  Entrante: Meta avisa (webhook "calls", event connect, con la oferta SDP) -> suena en el panel -> alguien la toma
 *            (la primera persona que toca Contestar) -> el navegador arma su respuesta SDP -> pre_accept y accept.
 *  Saliente: solo si el cliente dio permiso (botón de Meta "permiso para llamar"; 7 días o permanente) ->
 *            el navegador arma la oferta -> connect -> Meta manda la respuesta SDP por webhook.
 *  Requisitos de Meta: número en la API (no solo en la app del celular), límite de 2.000 mensajes al día o más,
 *  y el campo "calls" suscrito en el webhook de la app. Las llamadas que hace el cliente son gratis; las que hace el
 *  negocio las cobra Meta por minuto.
 */
final class Llamadas
{
    public const ESTADOS = ['sonando' => 'Sonando', 'contestando' => 'Conectando', 'en_curso' => 'En curso', 'terminada' => 'Terminada',
                            'perdida' => 'Perdida', 'rechazada' => 'Rechazada', 'fallida' => 'Falló'];
    public const SEGUNDOS_PARA_CONTESTAR = 55;

    public static function buscar(PDO $db, int $lineaId, int $id): ?array
    {
        $st = $db->prepare('SELECT l.*, c.nombre AS c_nombre, c.nombre_whatsapp AS c_nombre_whatsapp, c.telefono AS c_telefono, u.nombre AS u_nombre
                            FROM llamadas l LEFT JOIN contactos c ON c.id = l.contacto_id LEFT JOIN usuarios u ON u.id = l.usuario_id
                            WHERE l.id = ? AND l.linea_id = ?');
        $st->execute([$id, $lineaId]);
        return $st->fetch() ?: null;
    }

    public static function nombre(array $l): string
    {
        return Contactos::nombre(['nombre' => $l['c_nombre'] ?? '', 'nombre_whatsapp' => $l['c_nombre_whatsapp'] ?? '', 'telefono' => (string) ($l['c_telefono'] ?? '')]);
    }

    /** @return list<array> últimas llamadas del número (o de un contacto) */
    public static function lista(PDO $db, int $lineaId, ?int $contactoId = null, int $limite = 100): array
    {
        $st = $db->prepare('SELECT l.*, c.nombre AS c_nombre, c.nombre_whatsapp AS c_nombre_whatsapp, c.telefono AS c_telefono, u.nombre AS u_nombre
                            FROM llamadas l LEFT JOIN contactos c ON c.id = l.contacto_id LEFT JOIN usuarios u ON u.id = l.usuario_id
                            WHERE l.linea_id = ?' . ($contactoId !== null ? ' AND l.contacto_id = ' . $contactoId : '') . ' ORDER BY l.creado DESC LIMIT ' . max(1, min(500, $limite)));
        $st->execute([$lineaId]);
        return $st->fetchAll();
    }

    /** La llamada entrante que está sonando ahora (para que suene en el panel). */
    public static function sonando(PDO $db, int $lineaId, ?DateTimeImmutable $ahora = null): ?array
    {
        $ahora ??= new DateTimeImmutable();
        $st = $db->prepare("SELECT l.id, l.contacto_id, c.nombre AS c_nombre, c.nombre_whatsapp AS c_nombre_whatsapp, c.telefono AS c_telefono
                            FROM llamadas l LEFT JOIN contactos c ON c.id = l.contacto_id
                            WHERE l.linea_id = ? AND l.direccion = 'entra' AND l.estado = 'sonando' AND l.creado > ? ORDER BY l.id DESC LIMIT 1");
        $st->execute([$lineaId, $ahora->modify('-' . self::SEGUNDOS_PARA_CONTESTAR . ' seconds')->format('c')]);
        $l = $st->fetch();
        return $l ? ['id' => (int) $l['id'], 'contacto_id' => $l['contacto_id'] !== null ? (int) $l['contacto_id'] : null, 'nombre' => self::nombre($l)] : null;
    }

    /** ¿Se puede llamar a este cliente ahora? */
    public static function puedeLlamar(array $contacto, ?DateTimeImmutable $ahora = null): bool
    {
        $ahora ??= new DateTimeImmutable();
        return ($contacto['llamar_permiso'] ?? '') === 'permanente'
            || (($contacto['llamar_permiso'] ?? '') === 'temporal' && !empty($contacto['llamar_hasta']) && new DateTimeImmutable((string) $contacto['llamar_hasta']) > $ahora);
    }

    public static function sdpValido(string $sdp): bool
    {
        return strlen($sdp) >= 50 && strlen($sdp) <= 20000 && str_starts_with(ltrim($sdp), 'v=0') && str_contains($sdp, 'm=audio');
    }

    // ---------------------------------------------------------------- webhook "calls"

    /** Lo que avisa Meta en el campo "calls" (llamadas y sus estados). */
    public static function webhook(PDO $db, array $linea, array $v, ?DateTimeImmutable $ahora = null): void
    {
        $ahora ??= new DateTimeImmutable();
        $nombres = [];
        foreach ((array) ($v['contacts'] ?? []) as $c) {
            $nombres[(string) ($c['wa_id'] ?? '')] = (string) ($c['profile']['name'] ?? '');
        }
        foreach ((array) ($v['calls'] ?? []) as $c) {
            $evento = (string) ($c['event'] ?? '');
            $callId = (string) ($c['id'] ?? '');
            if ($callId === '') {
                continue;
            }
            $dir = (string) ($c['direction'] ?? '') === 'BUSINESS_INITIATED' ? 'sale' : 'entra';
            if ($evento === 'connect' && $dir === 'entra') {
                self::entrante($db, $linea, $c, $nombres, $ahora);
            } elseif ($evento === 'connect') {   // nuestra llamada: llegó la respuesta SDP de Meta
                $db->prepare("UPDATE llamadas SET sdp_respuesta = ? WHERE linea_id = ? AND call_id = ?")
                   ->execute([mb_substr((string) ($c['session']['sdp'] ?? ''), 0, 20000), (int) $linea['id'], $callId]);
            } elseif ($evento === 'terminate') {
                self::terminada($db, $linea, $c, $ahora);
            }
        }
        foreach ((array) ($v['statuses'] ?? []) as $s) {
            if (($s['type'] ?? '') !== 'call') {
                continue;
            }
            $nuevo = ['RINGING' => 'sonando', 'ACCEPTED' => 'en_curso', 'REJECTED' => 'rechazada'][strtoupper((string) ($s['status'] ?? ''))] ?? null;
            if ($nuevo !== null) {
                $db->prepare("UPDATE llamadas SET estado = ?, contestada = CASE WHEN ? = 'en_curso' THEN COALESCE(contestada, now()) ELSE contestada END
                              WHERE linea_id = ? AND call_id = ? AND estado NOT IN ('terminada', 'perdida', 'fallida', 'rechazada')")
                   ->execute([$nuevo, $nuevo, (int) $linea['id'], (string) ($s['id'] ?? '')]);
            }
        }
    }

    private static function entrante(PDO $db, array $linea, array $c, array $nombres, DateTimeImmutable $ahora): void
    {
        $tel = preg_replace('/\D+/', '', (string) ($c['from'] ?? ''));
        $contacto = $tel !== '' ? Contactos::obtener($db, (int) $linea['id'], $tel, $nombres[$tel] ?? '') : null;
        $st = $db->prepare("INSERT INTO llamadas (linea_id, contacto_id, call_id, direccion, estado, sdp_oferta, creado) VALUES (?, ?, ?, 'entra', 'sonando', ?, ?)
                            ON CONFLICT (linea_id, call_id) DO NOTHING RETURNING id");
        $st->execute([(int) $linea['id'], $contacto ? (int) $contacto['id'] : null, (string) $c['id'], mb_substr((string) ($c['session']['sdp'] ?? ''), 0, 20000), $ahora->format('c')]);
        if ($st->fetchColumn() === false || $contacto === null) {
            return;
        }
        // Llamar al negocio también le da permiso de que lo llamen de vuelta (Meta lo marca solo)
        try {
            Push::enviar($db, self::quienes($db, $linea, $contacto), ['titulo' => '📞 Llamada de ' . Contactos::nombre($contacto), 'texto' => 'Toca para contestar en el panel',
                                                                     'url' => '/?r=chat&c=' . (int) $contacto['id'], 'tag' => 'tuwa-llamada-' . $c['id']]);
        } catch (\Throwable $e) {
            error_log('tuwhatsapp llamada aviso: ' . $e->getMessage());
        }
    }

    private static function terminada(PDO $db, array $linea, array $c, DateTimeImmutable $ahora): void
    {
        $st = $db->prepare('SELECT * FROM llamadas WHERE linea_id = ? AND call_id = ?');
        $st->execute([(int) $linea['id'], (string) $c['id']]);
        $l = $st->fetch();
        if (!$l) {
            return;
        }
        $dur = isset($c['duration']) ? (int) $c['duration'] : null;
        $error = '';
        foreach ((array) ($c['errors'] ?? []) as $e) {
            $error = mb_substr((string) ($e['error_data']['details'] ?? $e['message'] ?? ''), 0, 300);
        }
        $falla = strtoupper((string) ($c['status'] ?? '')) === 'FAILED';
        $estado = match (true) {
            $l['estado'] === 'rechazada' => 'rechazada',
            $falla && $l['contestada'] === null && $dur === null => $l['direccion'] === 'entra' ? 'perdida' : 'fallida',
            $falla => 'fallida',
            $dur === null && $l['contestada'] === null => $l['direccion'] === 'entra' ? 'perdida' : 'terminada',
            default => 'terminada',
        };
        $db->prepare('UPDATE llamadas SET estado = ?, duracion = ?, terminada = ?, error = ?, contestada = COALESCE(contestada, CASE WHEN ?::int IS NOT NULL THEN ?::timestamptz END) WHERE id = ?')
           ->execute([$estado, $dur, $ahora->format('c'), $error, $dur, $ahora->modify('-' . (int) $dur . ' seconds')->format('c'), (int) $l['id']]);
        if ($estado !== 'perdida' || $l['contacto_id'] === null) {
            return;
        }
        $st = $db->prepare('SELECT * FROM contactos WHERE id = ?');
        $st->execute([(int) $l['contacto_id']]);
        $contacto = $st->fetch();
        if (!$contacto) {
            return;
        }
        try {
            Push::enviar($db, self::quienes($db, $linea, $contacto), ['titulo' => '📵 Llamada perdida de ' . Contactos::nombre($contacto), 'texto' => 'Toca para devolverle la llamada o escribirle',
                                                                     'url' => '/?r=chat&c=' . (int) $contacto['id'], 'tag' => 'tuwa-perdida-' . $c['id']]);
        } catch (\Throwable $e) {
            error_log('tuwhatsapp llamada perdida aviso: ' . $e->getMessage());
        }
        $texto = trim((string) ($linea['llamadas_perdida'] ?? ''));
        if ($texto !== '' && ($contacto['tipo'] ?? '') !== 'personal' && Gasto::ventanaAbierta($contacto, $ahora)) {
            (new Mensajeria($db))->texto($linea, $contacto, $texto, 'auto');
        }
    }

    /** A quién le suena: a quien atiende ese chat; si nadie, a todo el equipo que recibe chats. */
    private static function quienes(PDO $db, array $linea, array $contacto): array
    {
        if (!empty($contacto['asignado_a'])) {
            return [(int) $contacto['asignado_a']];
        }
        $st = $db->prepare('SELECT id FROM usuarios WHERE empresa_id = ? AND activo AND recibe_chats');
        $st->execute([(int) $linea['empresa_id']]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }

    /** El cliente contestó el pedido de permiso (o lo dio solo al llamar). */
    public static function permiso(PDO $db, int $contactoId, array $respuesta): string
    {
        $acepta = ($respuesta['response'] ?? '') === 'accept';
        $perm = !empty($respuesta['is_permanent']);
        $hasta = isset($respuesta['expiration_timestamp']) && ctype_digit((string) $respuesta['expiration_timestamp'])
            ? (new DateTimeImmutable('@' . (int) $respuesta['expiration_timestamp']))->format('c') : (new DateTimeImmutable('+7 days'))->format('c');
        $db->prepare('UPDATE contactos SET llamar_permiso = ?, llamar_hasta = ? WHERE id = ?')
           ->execute([$acepta ? ($perm ? 'permanente' : 'temporal') : 'rechazado', $acepta && !$perm ? $hasta : null, $contactoId]);
        return $acepta ? ($perm ? '📞 Aceptó que lo llamen (siempre)' : '📞 Aceptó que lo llamen (7 días)') : '📵 No aceptó que lo llamen';
    }

    // ---------------------------------------------------------------- desde el panel

    /** Alguien del equipo toca Contestar: se la lleva la primera persona. Devuelve la oferta SDP de Meta. */
    public static function tomar(PDO $db, int $lineaId, int $id, int $usuarioId): array
    {
        $st = $db->prepare("UPDATE llamadas SET estado = 'contestando', usuario_id = ? WHERE id = ? AND linea_id = ? AND direccion = 'entra' AND estado = 'sonando'
                            AND creado > now() - make_interval(secs => ?) RETURNING sdp_oferta");
        $st->execute([$usuarioId, $id, $lineaId, self::SEGUNDOS_PARA_CONTESTAR + 5]);
        $sdp = $st->fetchColumn();
        if ($sdp === false) {
            $l = self::buscar($db, $lineaId, $id);
            throw new RuntimeException($l && $l['estado'] !== 'sonando' && $l['usuario_id'] !== null && (int) $l['usuario_id'] !== $usuarioId
                ? 'Ya la contestó ' . ($l['u_nombre'] ?: 'otra persona del equipo') . '.' : 'Esa llamada ya no está sonando.');
        }
        return ['oferta' => (string) $sdp];
    }

    /** pre_accept (el audio se prepara) con la respuesta SDP del navegador. */
    public static function contestar(PDO $db, array $linea, int $id, int $usuarioId, string $sdp): void
    {
        $l = self::buscar($db, (int) $linea['id'], $id);
        if (!$l || $l['estado'] !== 'contestando' || (int) $l['usuario_id'] !== $usuarioId) {
            throw new RuntimeException('Esa llamada ya no está sonando.');
        }
        if (!self::sdpValido($sdp)) {
            throw new RuntimeException('El navegador no pudo preparar el audio. Revisa el permiso del micrófono.');
        }
        $db->prepare('UPDATE llamadas SET sdp_respuesta = ? WHERE id = ?')->execute([$sdp, $id]);
        $r = Meta::deLinea($linea)->llamadaAccion((string) $l['call_id'], 'pre_accept', $sdp);
        if (!$r['ok']) {
            $db->prepare("UPDATE llamadas SET estado = 'fallida', error = ? WHERE id = ?")->execute([mb_substr($r['error'], 0, 300), $id]);
            throw new RuntimeException($r['error']);
        }
    }

    /** accept: la llamada queda en curso. */
    public static function aceptar(PDO $db, array $linea, int $id, int $usuarioId): void
    {
        $l = self::buscar($db, (int) $linea['id'], $id);
        if (!$l || $l['estado'] !== 'contestando' || (int) $l['usuario_id'] !== $usuarioId) {
            throw new RuntimeException('Esa llamada ya no está sonando.');
        }
        $r = Meta::deLinea($linea)->llamadaAccion((string) $l['call_id'], 'accept', (string) $l['sdp_respuesta']);
        if (!$r['ok']) {
            $db->prepare("UPDATE llamadas SET estado = 'fallida', error = ? WHERE id = ?")->execute([mb_substr($r['error'], 0, 300), $id]);
            throw new RuntimeException($r['error']);
        }
        $db->prepare("UPDATE llamadas SET estado = 'en_curso', contestada = now() WHERE id = ? AND estado = 'contestando'")->execute([$id]);
    }

    public static function rechazar(PDO $db, array $linea, int $id): void
    {
        $l = self::buscar($db, (int) $linea['id'], $id);
        if (!$l || !in_array($l['estado'], ['sonando', 'contestando'], true) || $l['direccion'] !== 'entra') {
            return;
        }
        Meta::deLinea($linea)->llamadaAccion((string) $l['call_id'], 'reject');
        $db->prepare("UPDATE llamadas SET estado = 'rechazada', terminada = now() WHERE id = ?")->execute([$id]);
    }

    public static function colgar(PDO $db, array $linea, int $id): void
    {
        $l = self::buscar($db, (int) $linea['id'], $id);
        if (!$l || in_array($l['estado'], ['terminada', 'perdida', 'rechazada', 'fallida'], true)) {
            return;
        }
        Meta::deLinea($linea)->llamadaAccion((string) $l['call_id'], 'terminate');
        $db->prepare("UPDATE llamadas SET estado = 'terminada', terminada = now(),
                      duracion = COALESCE(duracion, CASE WHEN contestada IS NOT NULL THEN EXTRACT(EPOCH FROM now() - contestada)::int END) WHERE id = ?")->execute([$id]);
    }

    /** Llamar al cliente con la oferta SDP del navegador. Devuelve el id de la llamada. */
    public static function llamar(PDO $db, array $linea, array $contacto, int $usuarioId, string $sdp): int
    {
        if (empty($linea['llamadas_activo'])) {
            throw new RuntimeException('Las llamadas no están encendidas en este número (Más → Llamadas).');
        }
        if (!self::puedeLlamar($contacto)) {
            throw new RuntimeException('Este cliente todavía no te dio permiso para llamarlo. Toca "Pedir permiso para llamar".');
        }
        if (!self::sdpValido($sdp)) {
            throw new RuntimeException('El navegador no pudo preparar el audio. Revisa el permiso del micrófono.');
        }
        $st = $db->prepare("INSERT INTO llamadas (linea_id, contacto_id, call_id, direccion, estado, sdp_oferta, usuario_id) VALUES (?, ?, ?, 'sale', 'sonando', ?, ?) RETURNING id");
        $st->execute([(int) $linea['id'], (int) $contacto['id'], 'pendiente-' . bin2hex(random_bytes(8)), $sdp, $usuarioId]);
        $id = (int) $st->fetchColumn();
        $r = Meta::deLinea($linea)->llamar((string) $contacto['telefono'], $sdp, 'tuwa-' . $id);
        if (!$r['ok'] || $r['call_id'] === '') {
            if (($r['codigo'] ?? 0) === 138006) {
                $db->prepare("UPDATE contactos SET llamar_permiso = '', llamar_hasta = NULL WHERE id = ?")->execute([(int) $contacto['id']]);
            }
            $db->prepare("UPDATE llamadas SET estado = 'fallida', terminada = now(), error = ? WHERE id = ?")->execute([mb_substr($r['error'] ?? 'Meta no devolvió la llamada.', 0, 300), $id]);
            throw new RuntimeException($r['error'] ?? 'Meta no inició la llamada.');
        }
        $db->prepare('UPDATE llamadas SET call_id = ? WHERE id = ?')->execute([$r['call_id'], $id]);
        return $id;
    }

    /** Mandar el botón de Meta para pedir permiso de llamar (máximo 1 cada 24 horas por cliente). */
    public static function pedirPermiso(PDO $db, array $linea, array $contacto, int $usuarioId, ?DateTimeImmutable $ahora = null): array
    {
        $ahora ??= new DateTimeImmutable();
        if (!empty($contacto['llamar_pedido_en']) && new DateTimeImmutable((string) $contacto['llamar_pedido_en']) > $ahora->modify('-24 hours')) {
            return ['ok' => false, 'error' => 'Ya le pediste permiso en las últimas 24 horas (Meta deja 1 por día y 2 por semana). Espera su respuesta.'];
        }
        $texto = 'Nos gustaría llamarte por WhatsApp para atenderte mejor. ¿Nos das permiso?';
        $r = (new Mensajeria($db, $ahora))->permisoLlamada($linea, $contacto, $texto, 'panel', $usuarioId);
        if ($r['ok']) {
            $db->prepare('UPDATE contactos SET llamar_pedido_en = ? WHERE id = ?')->execute([$ahora->format('c'), (int) $contacto['id']]);
        }
        return $r;
    }

    /** Encender o apagar las llamadas en Meta (con el horario de atención del número). */
    public static function encender(PDO $db, array $linea, bool $si): array
    {
        $calling = ['status' => $si ? 'ENABLED' : 'DISABLED'];
        if ($si) {
            $calling += ['call_icon_visibility' => 'DEFAULT', 'callback_permission_status' => 'ENABLED'];
            $dias = [1 => 'MONDAY', 2 => 'TUESDAY', 3 => 'WEDNESDAY', 4 => 'THURSDAY', 5 => 'FRIDAY', 6 => 'SATURDAY', 7 => 'SUNDAY'];
            $horas = [];
            foreach (Lineas::horario($linea) as $d => $h) {
                if ($h !== null) {
                    $horas[] = ['day_of_week' => $dias[$d], 'open_time' => str_replace(':', '', $h[0]), 'close_time' => str_replace(':', '', $h[1])];
                }
            }
            if ($horas) {
                $calling['call_hours'] = ['status' => 'ENABLED', 'timezone_id' => 'America/Guayaquil', 'weekly_operating_hours' => $horas, 'holiday_schedule' => []];
            }
        }
        $r = Meta::deLinea($linea)->ajustesLlamadas($calling);
        $db->prepare('UPDATE lineas SET llamadas_activo = ?, llamadas_error = ? WHERE id = ?')
           ->execute([$r['ok'] ? ($si ? 't' : 'f') : ($linea['llamadas_activo'] ? 't' : 'f'), $r['ok'] ? '' : mb_substr($r['error'], 0, 300), (int) $linea['id']]);
        return $r;
    }

    public static function duracion(?int $s): string
    {
        if ($s === null) {
            return '';
        }
        return intdiv($s, 60) . ':' . str_pad((string) ($s % 60), 2, '0', STR_PAD_LEFT);
    }
}
