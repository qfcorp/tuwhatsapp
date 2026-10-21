<?php
declare(strict_types=1);

namespace TuWhatsApp;

use DateTimeImmutable;
use PDO;

/**
 * Lo que Meta avisa a https://<tu dominio>/webhook.php:
 *  - messages: mensajes que llegan (se guardan y se decide si se contestan solos) y estados de lo enviado
 *    (enviado, entregado, leído, fallido) con lo que Meta realmente cobró.
 *  - smb_message_echoes: lo que mandaste a mano desde la app del celular (gratis); se guarda para ver toda la conversación
 *    y, si coincide con un pendiente, lo marca como enviado.
 */
final class Webhook
{
    private const ORDEN_ESTADOS = ['enviando' => 0, 'enviado' => 1, 'entregado' => 2, 'leido' => 3];
    private const ESTADOS_META = ['sent' => 'enviado', 'delivered' => 'entregado', 'read' => 'leido', 'failed' => 'fallido'];
    private const CON_RESPUESTA = ['text', 'interactive', 'button'];

    public function __construct(private PDO $db, private ?DateTimeImmutable $ahora = null) {}

    /** Verificación que pide Meta al guardar la URL (GET con hub.challenge). Devuelve el challenge o null. */
    public static function verificar(array $get): ?string
    {
        $token = (string) getenv('META_VERIFY_TOKEN');
        if ($token === '' || ($get['hub_mode'] ?? '') !== 'subscribe' || !hash_equals($token, (string) ($get['hub_verify_token'] ?? ''))) {
            return null;
        }
        $c = (string) ($get['hub_challenge'] ?? '');
        return preg_match('/^[A-Za-z0-9_\-]{1,200}$/', $c) ? $c : null;
    }

    /** Revisa que el aviso venga de verdad de Meta (firma con el App Secret). */
    public static function firmaValida(string $cuerpo, string $cabecera): bool
    {
        $secreto = (string) getenv('META_APP_SECRET');
        if ($secreto === '' || !str_starts_with($cabecera, 'sha256=')) {
            return false;
        }
        return hash_equals(hash_hmac('sha256', $cuerpo, $secreto), substr($cabecera, 7));
    }

    public function procesar(string $cuerpo): void
    {
        $this->db->prepare("DELETE FROM webhook_log WHERE recibido < now() - interval '30 days'")->execute();
        $st = $this->db->prepare('INSERT INTO webhook_log (cuerpo) VALUES (?) RETURNING id');
        $st->execute([mb_substr($cuerpo, 0, 100000)]);
        $logId = (int) $st->fetchColumn();
        $datos = json_decode($cuerpo, true);
        if (!is_array($datos)) {
            $this->error($logId, 'No es JSON');
            return;
        }
        $errores = [];
        foreach ((array) ($datos['entry'] ?? []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $cambio) {
                try {
                    $this->cambio((string) ($cambio['field'] ?? ''), (array) ($cambio['value'] ?? []));
                } catch (\Throwable $e) {
                    $errores[] = $e->getMessage();
                    if (!$e instanceof \RuntimeException || $e instanceof \PDOException) {
                        error_log('tuwhatsapp webhook: ' . $e);
                    }
                }
            }
        }
        if ($errores) {
            $this->error($logId, implode(' | ', $errores));
        }
    }

    private function cambio(string $campo, array $v): void
    {
        $pnid = (string) ($v['metadata']['phone_number_id'] ?? '');
        if ($pnid === '' && !in_array($campo, ['messages', 'smb_message_echoes', 'history', 'smb_app_state_sync'], true)) {
            return;   // avisos de la cuenta (account_update y otros): quedan guardados en webhook_log
        }
        $linea = $pnid !== '' ? Lineas::porPhoneNumberId($this->db, $pnid) : null;
        if ($linea === null) {
            throw new \RuntimeException("Número desconocido (phone_number_id $pnid): agrégalo en Números");
        }
        if ($campo === 'history') {
            foreach ((array) ($v['history'] ?? []) as $h) {
                $this->historial($linea, (array) $h);
            }
            return;
        }
        if ($campo === 'smb_app_state_sync') {
            foreach ((array) ($v['state_sync'] ?? []) as $s) {
                $this->contactoDelCelular($linea, (array) $s);
            }
            return;
        }
        if ($campo === 'messages') {
            $nombres = [];
            foreach ((array) ($v['contacts'] ?? []) as $c) {
                $nombres[(string) ($c['wa_id'] ?? '')] = (string) ($c['profile']['name'] ?? '');
            }
            foreach ((array) ($v['messages'] ?? []) as $m) {
                $this->entrante($linea, $m, $nombres[(string) ($m['from'] ?? '')] ?? '');
            }
            foreach ((array) ($v['statuses'] ?? []) as $s) {
                $this->estado($s);
            }
        } elseif ($campo === 'smb_message_echoes') {
            foreach ((array) ($v['message_echoes'] ?? []) as $m) {
                $this->eco($linea, $m);
            }
        }
    }

    public static function textoDe(array $m): string
    {
        $tipo = (string) ($m['type'] ?? '');
        $pie = fn (string $k) => trim((string) ($m[$k]['caption'] ?? ''));
        return match ($tipo) {
            'text' => (string) ($m['text']['body'] ?? ''),
            'interactive' => (string) ($m['interactive']['button_reply']['title'] ?? $m['interactive']['list_reply']['title'] ?? '[Respuesta]'),
            'button' => (string) ($m['button']['text'] ?? '[Botón]'),
            'image' => '[Imagen]' . ($pie('image') !== '' ? ' ' . $pie('image') : ''),
            'video' => '[Video]' . ($pie('video') !== '' ? ' ' . $pie('video') : ''),
            'document' => '[Documento] ' . trim((string) ($m['document']['filename'] ?? '') . ' ' . $pie('document')),
            'audio' => '[Audio]',
            'sticker' => '[Sticker]',
            'location' => '[Ubicación] ' . trim((string) ($m['location']['name'] ?? '') . ' ' . ($m['location']['address'] ?? '')),
            'contacts' => '[Contacto compartido]',
            'reaction' => '[Reacción ' . ($m['reaction']['emoji'] ?? '') . ']',
            'media_placeholder' => '[Archivo del historial]',
            default => '[Mensaje no compatible]',
        };
    }

    private function entrante(array $linea, array $m, string $nombre): void
    {
        $tel = preg_replace('/\D+/', '', (string) ($m['from'] ?? ''));
        $wamid = (string) ($m['id'] ?? '');
        if ($tel === '' || $wamid === '') {
            return;
        }
        $ahora = $this->ahora ?? new DateTimeImmutable();
        $cuando = isset($m['timestamp']) ? (new DateTimeImmutable('@' . (int) $m['timestamp']))->setTimezone($ahora->getTimezone()) : $ahora;
        if ($cuando > $ahora) {
            $cuando = $ahora;
        }
        $contacto = Contactos::obtener($this->db, (int) $linea['id'], $tel, $nombre);
        $texto = self::textoDe($m);
        $st = $this->db->prepare("INSERT INTO mensajes (linea_id, contacto_id, direccion, origen, tipo, texto, wamid, estado, leido, creado)
                                  VALUES (?, ?, 'entra', 'cliente', ?, ?, ?, 'recibido', false, ?) ON CONFLICT (wamid) DO NOTHING RETURNING id");
        $st->execute([$linea['id'], $contacto['id'], ($m['type'] ?? '') === 'text' ? 'texto' : 'otro', mb_substr($texto, 0, 4096), $wamid, $cuando->format('c')]);
        $id = $st->fetchColumn();
        if ($id === false) {
            return;   // Meta lo mandó dos veces
        }
        $this->db->prepare('UPDATE contactos SET ultimo_entrante = GREATEST(COALESCE(ultimo_entrante, ?), ?) WHERE id = ?')
           ->execute([$cuando->format('c'), $cuando->format('c'), $contacto['id']]);
        if (!empty($m['referral'])) {   // llegó desde un anuncio "Enviar WhatsApp": 72 horas gratis
            $this->db->prepare('UPDATE contactos SET gratis_hasta = ? WHERE id = ?')
               ->execute([$cuando->modify('+72 hours')->format('c'), $contacto['id']]);
        }
        $contacto = Contactos::buscar($this->db, (int) $linea['id'], (int) $contacto['id']);

        if (!in_array((string) ($m['type'] ?? ''), self::CON_RESPUESTA, true)) {
            $this->motivo((int) $id, 'No es texto: contestas tú');
            return;
        }
        if (Campanias::pideBaja($texto)) {
            Campanias::marcarMarketing($this->db, (int) $linea['id'], (int) $contacto['id'], 'baja');
            $this->motivo((int) $id, 'Pidió no recibir más promociones: ya no entra en campañas');
            return;
        }
        $st = $this->db->prepare("SELECT count(*) FROM mensajes WHERE contacto_id = ? AND origen = 'auto' AND estado NOT IN ('fallido', 'bloqueado') AND creado > ?");
        $st->execute([$contacto['id'], $ahora->modify('-' . (int) $linea['horas_silencio'] . ' hours')->format('c')]);
        $d = Empresas::alDiaPorLinea($this->db, $linea)
            ? Reglas::decidir($linea, $contacto, $texto, $ahora, (int) $st->fetchColumn())
            : ['responder' => false, 'motivo' => 'La cuenta no está al día: no se contesta solo', 'texto' => null, 'humano' => false];
        if (!$d['responder']) {
            $this->motivo((int) $id, $d['motivo']);
            return;
        }
        $r = (new Mensajeria($this->db, $this->ahora))->texto($linea, $contacto, (string) $d['texto'], 'auto');
        if ($r['ok']) {
            $this->motivo((int) $id, 'Contestado solo: ' . $d['motivo'] . ($r['costo'] > 0 ? ' (' . '$' . number_format($r['costo'], 4, ',', '.') . ')' : ' (gratis)'));
            if ($d['humano']) {
                $this->db->prepare('UPDATE contactos SET silencio_hasta = ? WHERE id = ?')
                   ->execute([$ahora->modify('+' . (int) $linea['horas_silencio'] . ' hours')->format('c'), $contacto['id']]);
            }
        } else {
            $this->motivo((int) $id, !empty($r['bloqueado']) ? 'No se contestó: llegaste al tope del mes' : 'No se pudo contestar: ' . $r['error']);
        }
    }

    private function estado(array $s): void
    {
        $wamid = (string) ($s['id'] ?? '');
        $nuevo = self::ESTADOS_META[(string) ($s['status'] ?? '')] ?? null;
        if ($wamid === '' || $nuevo === null) {
            return;
        }
        $st = $this->db->prepare("SELECT id, estado, costo, categoria FROM mensajes WHERE wamid = ? AND direccion = 'sale'");
        $st->execute([$wamid]);
        $msg = $st->fetch();
        if (!$msg) {
            return;
        }
        if ($nuevo === 'fallido') {
            $err = (array) ($s['errors'][0] ?? []);
            $this->db->prepare("UPDATE mensajes SET estado = 'fallido', costo = 0, error = ? WHERE id = ?")
               ->execute([Meta::explicar($err), $msg['id']]);
            return;
        }
        $antes = self::ORDEN_ESTADOS[$msg['estado']] ?? 99;
        if (self::ORDEN_ESTADOS[$nuevo] > $antes) {
            $this->db->prepare('UPDATE mensajes SET estado = ? WHERE id = ?')->execute([$nuevo, $msg['id']]);
        }
        $p = $s['pricing'] ?? null;
        if (is_array($p) && array_key_exists('billable', $p)) {
            $categoria = Gasto::DE_META[strtolower((string) ($p['category'] ?? ''))] ?? $msg['categoria'];
            if ($p['billable'] === false) {
                $costo = 0.0;
            } elseif ((float) $msg['costo'] > 0) {
                $costo = (float) $msg['costo'];
            } else {
                $costo = Gasto::precios($this->db)[$categoria] ?? 0.0;
            }
            $this->db->prepare('UPDATE mensajes SET costo = ?, categoria = ? WHERE id = ?')->execute([$costo, $categoria, $msg['id']]);
        }
    }

    private function eco(array $linea, array $m): void
    {
        $tel = preg_replace('/\D+/', '', (string) ($m['to'] ?? ''));
        $wamid = (string) ($m['id'] ?? '');
        if ($tel === '' || $wamid === '') {
            return;
        }
        $ahora = $this->ahora ?? new DateTimeImmutable();
        $cuando = isset($m['timestamp']) ? (new DateTimeImmutable('@' . (int) $m['timestamp']))->setTimezone($ahora->getTimezone()) : $ahora;
        $contacto = Contactos::obtener($this->db, (int) $linea['id'], $tel);
        $texto = self::textoDe($m);
        $st = $this->db->prepare("INSERT INTO mensajes (linea_id, contacto_id, direccion, origen, tipo, texto, wamid, estado, costo, creado)
                                  VALUES (?, ?, 'sale', 'celular', ?, ?, ?, 'enviado', 0, ?) ON CONFLICT (wamid) DO NOTHING RETURNING id");
        $st->execute([$linea['id'], $contacto['id'], ($m['type'] ?? '') === 'text' ? 'texto' : 'otro', mb_substr($texto, 0, 4096), $wamid, $cuando->format('c')]);
        if ($st->fetchColumn() !== false) {
            Pendientes::marcarPorEco($this->db, (int) $linea['id'], $tel, $texto);
        }
    }

    /**
     * Historial de la app del celular (coexistencia). Llega por partes; cada mensaje se guarda una sola vez.
     * Nunca se contesta solo a un mensaje del historial.
     */
    private function historial(array $linea, array $h): void
    {
        if (!empty($h['errors'])) {
            $this->db->prepare("UPDATE lineas SET historial_estado = 'rechazado' WHERE id = ?")->execute([$linea['id']]);
            return;
        }
        $ahora = $this->ahora ?? new DateTimeImmutable();
        $propio = preg_replace('/\D+/', '', (string) $linea['telefono']);
        $estados = ['READ' => 'leido', 'DELIVERED' => 'entregado', 'SENT' => 'enviado', 'PLAYED' => 'leido', 'PENDING' => 'enviado', 'ERROR' => 'fallido'];
        foreach ((array) ($h['threads'] ?? []) as $hilo) {
            $cliente = preg_replace('/\D+/', '', (string) ($hilo['id'] ?? ''));
            if ($cliente === '') {
                continue;
            }
            $contacto = Contactos::obtener($this->db, (int) $linea['id'], $cliente);
            foreach ((array) ($hilo['messages'] ?? []) as $m) {
                $wamid = (string) ($m['id'] ?? '');
                if ($wamid === '') {
                    continue;
                }
                $de = preg_replace('/\D+/', '', (string) ($m['from'] ?? ''));
                $sale = isset($m['to']) || ($de !== '' && $de !== $cliente) || ($propio !== '' && $de === $propio);
                $cuando = isset($m['timestamp']) ? (new DateTimeImmutable('@' . (int) $m['timestamp']))->setTimezone($ahora->getTimezone()) : $ahora;
                $texto = self::textoDe($m);
                $estado = $sale ? ($estados[strtoupper((string) ($m['history_context']['status'] ?? ''))] ?? 'enviado') : 'recibido';
                $st = $this->db->prepare("INSERT INTO mensajes (linea_id, contacto_id, direccion, origen, tipo, texto, wamid, estado, costo, leido, motivo, creado)
                                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, true, ?, ?) ON CONFLICT (wamid) DO NOTHING RETURNING id");
                $st->execute([$linea['id'], $contacto['id'], $sale ? 'sale' : 'entra', $sale ? 'celular' : 'cliente',
                              ($m['type'] ?? '') === 'text' ? 'texto' : 'otro', mb_substr($texto, 0, 4096), $wamid, $estado,
                              $sale ? null : 'Del historial del celular', $cuando->format('c')]);
                if (!$sale && $st->fetchColumn() !== false) {
                    $this->db->prepare('UPDATE contactos SET ultimo_entrante = GREATEST(COALESCE(ultimo_entrante, ?), ?) WHERE id = ?')
                       ->execute([$cuando->format('c'), $cuando->format('c'), $contacto['id']]);
                }
            }
        }
        $progreso = max(0, min(100, (int) ($h['metadata']['progress'] ?? 0)));
        $this->db->prepare("UPDATE lineas SET historial_progreso = GREATEST(historial_progreso, ?),
                            historial_estado = CASE WHEN GREATEST(historial_progreso, ?) >= 100 THEN 'completo' ELSE 'recibiendo' END WHERE id = ?")
           ->execute([$progreso, $progreso, $linea['id']]);
    }

    /**
     * Contactos de la agenda del celular (coexistencia). Los nuevos entran como "Desconocido" con su nombre;
     * los que ya existen no cambian de tipo. Si se borran del celular, aquí se quedan (no se pierde la conversación).
     */
    private function contactoDelCelular(array $linea, array $s): void
    {
        if (($s['type'] ?? '') !== 'contact' || ($s['action'] ?? '') !== 'add') {
            return;
        }
        $c = (array) ($s['contact'] ?? []);
        $tel = Telefono::normalizar((string) ($c['phone_number'] ?? ''));
        if ($tel === null) {
            return;
        }
        $nombre = trim((string) ($c['full_name'] ?? $c['first_name'] ?? ''));
        $contacto = Contactos::obtener($this->db, (int) $linea['id'], $tel);
        if ($nombre !== '' && (string) $contacto['nombre'] === '') {
            $this->db->prepare('UPDATE contactos SET nombre = ? WHERE id = ?')->execute([mb_substr($nombre, 0, 100), $contacto['id']]);
        }
    }

    private function motivo(int $id, string $m): void
    {
        $this->db->prepare('UPDATE mensajes SET motivo = ? WHERE id = ?')->execute([$m, $id]);
    }

    private function error(int $logId, string $e): void
    {
        $this->db->prepare('UPDATE webhook_log SET error = ? WHERE id = ?')->execute([mb_substr($e, 0, 2000), $logId]);
    }
}
