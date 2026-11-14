<?php
declare(strict_types=1);

namespace TuWhatsApp;

use DateTimeImmutable;
use PDO;

/**
 * Respaldo por correo de los avisos (14 nov 2026): que no se pierda ningún cliente aunque la app del celular esté cerrada,
 * los avisos estén apagados o el celular sin internet.
 *  - Lo corre bin/campanias.php cada minuto.
 *  - Mensajes de clientes (no contactos personales) que nadie leyó ni contestó en N minutos → correo a quien atiende el chat;
 *    si no lo atiende nadie, a todo el equipo que recibe chats (si nadie recibe chats, a los administradores).
 *  - Cada persona elige: apagado / 5 / 15 / 30 / 60 minutos (por defecto 5) y, si quiere, otro correo.
 *  - La empresa puede poner un correo extra que recibe copia de todo (p. ej. ventas@…).
 *  - Un solo correo por persona con todos sus chats pendientes; nunca dos correos a la misma persona en menos de 15 minutos
 *    y nunca dos veces el mismo mensaje (tabla avisos_correo). Solo mensajes de las últimas 12 horas y de después de instalar esto.
 */
final class AvisoCorreo
{
    public const MINUTOS = [0, 5, 15, 30, 60];
    public const ESPERA_ENTRE_CORREOS = 15;   // minutos

    /** @return array{correos:int, fallidos:int} */
    public static function procesar(PDO $db, ?DateTimeImmutable $ahora = null): array
    {
        $r = ['correos' => 0, 'fallidos' => 0];
        $ahora ??= new DateTimeImmutable();
        // Desde cuándo: al instalar esto no se manda nada de los mensajes viejos sin leer
        $db->prepare("INSERT INTO ajustes (clave, valor) VALUES ('aviso_correo_desde', ?) ON CONFLICT (clave) DO NOTHING")->execute([$ahora->format('c')]);
        if (!Correo::configurado()) {
            return $r;
        }
        $desde = new DateTimeImmutable((string) $db->query("SELECT valor FROM ajustes WHERE clave = 'aviso_correo_desde'")->fetchColumn());
        $hace12 = $ahora->modify('-12 hours');
        $st = $db->prepare("SELECT m.id, m.creado, m.texto, m.tipo, m.contacto_id, m.linea_id, l.empresa_id, l.nombre AS l_nombre
            FROM mensajes m
            JOIN contactos c ON c.id = m.contacto_id
            JOIN lineas l ON l.id = m.linea_id
            WHERE m.direccion = 'entra' AND m.origen = 'cliente' AND NOT m.leido
              AND m.creado > ? AND m.creado <= ? AND c.tipo <> 'personal'
              AND NOT EXISTS (SELECT 1 FROM mensajes r WHERE r.contacto_id = m.contacto_id AND r.direccion = 'sale'
                              AND r.origen IN ('panel', 'celular') AND r.creado > m.creado)
            ORDER BY m.creado LIMIT 1000");
        $st->execute([max($desde, $hace12)->format('c'), $ahora->modify('-5 minutes')->format('c')]);
        $mensajes = $st->fetchAll();
        if (!$mensajes) {
            return $r;
        }
        $ya = $db->prepare('SELECT destino FROM avisos_correo WHERE mensaje_id = ?');
        $empresas = $equipos = $contactos = [];
        $grupos = [];   // destino => ['para', 'idioma', 'nombre', 'usuario_id'|null, 'empresa_id', 'mensajes' => []]
        foreach ($mensajes as $m) {
            $eid = (int) $m['empresa_id'];
            $empresas[$eid] ??= Empresas::buscar($db, $eid);
            if (!Empresas::alDia($empresas[$eid])) {
                continue;
            }
            $equipos[$eid] ??= self::equipo($db, $eid);
            $contactos[(int) $m['contacto_id']] ??= $db->query('SELECT * FROM contactos WHERE id = ' . (int) $m['contacto_id'])->fetch();
            $m['contacto'] = $contactos[(int) $m['contacto_id']];
            $minutos = ($ahora->getTimestamp() - (new DateTimeImmutable($m['creado']))->getTimestamp()) / 60;
            $ya->execute([$m['id']]);
            $avisados = $ya->fetchAll(PDO::FETCH_COLUMN);
            foreach (self::quienes($equipos[$eid], $m['contacto']) as $u) {
                $destino = 'u' . $u['id'];
                $min = (int) $u['aviso_correo_min'];
                if ($min <= 0 || $minutos < $min || in_array($destino, $avisados, true)) {
                    continue;
                }
                $grupos[$destino] ??= ['para' => self::correoDe($u), 'idioma' => (string) ($u['idioma'] ?: 'es'), 'usuario_id' => (int) $u['id'],
                                       'ultimo' => $u['aviso_correo_ultimo'], 'empresa_id' => $eid, 'mensajes' => []];
                $grupos[$destino]['mensajes'][] = $m;
            }
            $copia = trim((string) ($empresas[$eid]['aviso_copia'] ?? ''));
            $destino = 'copia';
            if ($copia !== '' && Correo::valido($copia) && $minutos >= (int) $empresas[$eid]['aviso_copia_min'] && !in_array($destino, $avisados, true)) {
                $grupos["copia$eid"] ??= ['para' => $copia, 'idioma' => 'es', 'usuario_id' => null, 'ultimo' => $empresas[$eid]['aviso_copia_ultimo'],
                                          'empresa_id' => $eid, 'mensajes' => []];
                $grupos["copia$eid"]['mensajes'][] = $m;
            }
        }
        $marcar = $db->prepare('INSERT INTO avisos_correo (mensaje_id, destino) VALUES (?, ?) ON CONFLICT DO NOTHING');
        foreach ($grupos as $clave => $g) {
            if ($g['ultimo'] !== null && (new DateTimeImmutable($g['ultimo'])) > $ahora->modify('-' . self::ESPERA_ENTRE_CORREOS . ' minutes')) {
                continue;   // ya le llegó un correo hace poco: lo pendiente va en el siguiente
            }
            $destino = $g['usuario_id'] === null ? 'copia' : 'u' . $g['usuario_id'];
            [$asunto, $texto, $html] = self::armar($g['mensajes'], $g['idioma'], $ahora, $g['usuario_id'] === null);
            try {
                Correo::enviar($g['para'], $asunto, $texto, $html);
                $ok = true;
            } catch (\Throwable $e) {
                $ok = false;
                error_log('tuwhatsapp aviso por correo a ' . $g['para'] . ': ' . $e->getMessage());
            }
            // Si falló, se vuelve a intentar en unos 5 minutos (no cada minuto)
            $cuando = $ok ? $ahora : $ahora->modify('-' . (self::ESPERA_ENTRE_CORREOS - 5) . ' minutes');
            if ($g['usuario_id'] === null) {
                $db->prepare('UPDATE empresas SET aviso_copia_ultimo = ? WHERE id = ?')->execute([$cuando->format('c'), $g['empresa_id']]);
            } else {
                $db->prepare('UPDATE usuarios SET aviso_correo_ultimo = ? WHERE id = ?')->execute([$cuando->format('c'), $g['usuario_id']]);
            }
            if ($ok) {
                foreach ($g['mensajes'] as $m) {
                    $marcar->execute([$m['id'], $destino]);
                }
                $r['correos']++;
            } else {
                $r['fallidos']++;
            }
        }
        return $r;
    }

    /** Equipo activo de la empresa con sus ajustes de aviso. */
    private static function equipo(PDO $db, int $empresaId): array
    {
        $st = $db->prepare('SELECT id, nombre, correo, idioma, rol, recibe_chats, aviso_correo_min, aviso_correo, aviso_correo_ultimo
                            FROM usuarios WHERE empresa_id = ? AND activo ORDER BY id');
        $st->execute([$empresaId]);
        $r = [];
        foreach ($st->fetchAll() as $u) {
            $r[(int) $u['id']] = $u;
        }
        return $r;
    }

    /** A quién se avisa de ese chat (igual que Push::mensajeNuevo). */
    private static function quienes(array $equipo, array $contacto): array
    {
        $a = (int) ($contacto['asignado_a'] ?? 0);
        if ($a > 0 && isset($equipo[$a])) {
            return [$equipo[$a]];
        }
        $r = array_values(array_filter($equipo, fn ($u) => !empty($u['recibe_chats'])));
        return $r ?: array_values(array_filter($equipo, fn ($u) => $u['rol'] === 'admin'));
    }

    private static function correoDe(array $u): string
    {
        $otro = trim((string) ($u['aviso_correo'] ?? ''));
        return $otro !== '' && Correo::valido($otro) ? $otro : (string) $u['correo'];
    }

    /** Dirección del programa para los enlaces del correo. */
    public static function base(): string
    {
        return rtrim((string) (getenv('TUWA_URL') ?: 'https://whatsapp.tukanec.com'), '/');
    }

    /**
     * Asunto, texto y HTML del correo con los chats pendientes (uno por chat, con su último mensaje).
     * @return array{0:string, 1:string, 2:string}
     */
    public static function armar(array $mensajes, string $idioma, DateTimeImmutable $ahora, bool $copia = false): array
    {
        $t = fn (string $s) => tukan_tr($s, $idioma);
        $chats = [];
        foreach ($mensajes as $m) {
            $cid = (int) $m['contacto_id'];
            $chats[$cid] ??= ['nombre' => Contactos::nombre($m['contacto']), 'linea' => (string) $m['l_nombre'], 'cuantos' => 0, 'primero' => $m['creado'], 'ultimo' => ''];
            $chats[$cid]['cuantos']++;
            $chats[$cid]['ultimo'] = $m['tipo'] === 'texto' && trim((string) $m['texto']) !== '' ? (string) $m['texto'] : $t('(foto, audio u otro archivo)');
        }
        $n = count($chats);
        $primero = reset($chats);
        $asunto = $n === 1 ? '💬 ' . $primero['nombre'] . ' — ' . $t('te escribió y nadie ha contestado')
                           : '💬 ' . $t('Tienes ' . $n . ' chats sin contestar');
        $intro = $copia ? $t('Copia para la empresa: estos clientes escribieron y nadie del equipo ha contestado todavía.')
                        : $t('Estos clientes te escribieron y todavía no han tenido respuesta. Contesta pronto para no perder la venta.');
        $base = self::base();
        $texto = $intro . "\n\n";
        $filas = '';
        foreach ($chats as $cid => $c) {
            $hace = max(1, (int) floor(($ahora->getTimestamp() - (new DateTimeImmutable($c['primero']))->getTimestamp()) / 60));
            $haceTxt = $hace < 60 ? $t('hace ' . $hace . ' min') : $t('hace ' . intdiv($hace, 60) . ' h');
            $enlace = $base . '/?r=chat&c=' . $cid;
            $extracto = mb_strimwidth(preg_replace('/\s+/u', ' ', $c['ultimo']), 0, 140, '…');
            $cuantos = $c['cuantos'] > 1 ? ' (' . $t($c['cuantos'] . ' mensajes') . ')' : '';
            $texto .= '• ' . $c['nombre'] . $cuantos . ' · ' . $c['linea'] . ' · ' . $haceTxt . "\n  «" . $extracto . "»\n  " . $enlace . "\n\n";
            $h = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
            $filas .= '<tr><td style="padding:12px 0;border-bottom:1px solid #eee">'
                . '<b style="font-size:16px">' . $h($c['nombre']) . '</b>' . $h($cuantos)
                . '<div style="color:#667;font-size:13px">' . $h($c['linea']) . ' · ' . $h($haceTxt) . '</div>'
                . '<div style="margin:6px 0 8px;color:#222">«' . $h($extracto) . '»</div>'
                . '<a href="' . $h($enlace) . '" style="display:inline-block;background:#F28C28;color:#fff;text-decoration:none;padding:9px 16px;border-radius:8px;font-weight:bold">'
                . $h($t('Abrir el chat')) . '</a></td></tr>';
        }
        $pie = $t('Te llega este correo porque nadie leyó estos mensajes. Para cambiar cuándo te llega (o apagarlo): App en el celular → Respaldo por correo.');
        $texto .= $pie . "\n" . $base . "/?r=app\n";
        $html = '<!doctype html><html><body style="margin:0;background:#f4f4f4;font-family:Arial,Helvetica,sans-serif">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:16px">'
            . '<table role="presentation" width="100%" style="max-width:560px;background:#fff;border-radius:12px;padding:20px" cellpadding="0" cellspacing="0">'
            . '<tr><td style="font-size:20px;font-weight:bold;color:#14211F;padding-bottom:6px">' . htmlspecialchars($asunto, ENT_QUOTES, 'UTF-8') . '</td></tr>'
            . '<tr><td style="color:#333;padding-bottom:6px">' . htmlspecialchars($intro, ENT_QUOTES, 'UTF-8') . '</td></tr>'
            . $filas
            . '<tr><td style="color:#889;font-size:12px;padding-top:14px">' . htmlspecialchars($pie, ENT_QUOTES, 'UTF-8')
            . ' <a href="' . htmlspecialchars($base . '/?r=app', ENT_QUOTES, 'UTF-8') . '" style="color:#889">' . htmlspecialchars($t('Cambiar'), ENT_QUOTES, 'UTF-8') . '</a></td></tr>'
            . '</table></td></tr></table></body></html>';
        return [$asunto, $texto, $html];
    }

    /** Ajustes de aviso por correo de una persona. @return array{min:int, correo:string, de_entrar:string} */
    public static function ajustes(PDO $db, int $usuarioId): array
    {
        $st = $db->prepare('SELECT correo, aviso_correo_min, aviso_correo FROM usuarios WHERE id = ?');
        $st->execute([$usuarioId]);
        $u = $st->fetch() ?: ['correo' => '', 'aviso_correo_min' => 0, 'aviso_correo' => ''];
        return ['min' => (int) $u['aviso_correo_min'], 'correo' => (string) $u['aviso_correo'], 'de_entrar' => (string) $u['correo']];
    }

    /** @throws \RuntimeException si el correo no es válido */
    public static function guardar(PDO $db, int $usuarioId, int $min, string $correo): void
    {
        $correo = trim($correo);
        if (!in_array($min, self::MINUTOS, true)) {
            $min = 5;
        }
        if ($correo !== '' && !Correo::valido($correo)) {
            throw new \RuntimeException('Ese correo no es válido.');
        }
        $db->prepare('UPDATE usuarios SET aviso_correo_min = ?, aviso_correo = ? WHERE id = ?')->execute([$min, $correo, $usuarioId]);
    }

    /** Correo extra de la empresa (solo el administrador). @throws \RuntimeException */
    public static function guardarCopia(PDO $db, int $empresaId, string $correo, int $min): void
    {
        $correo = trim($correo);
        if ($correo !== '' && !Correo::valido($correo)) {
            throw new \RuntimeException('Ese correo no es válido.');
        }
        if (!in_array($min, [5, 15, 30, 60], true)) {
            $min = 15;
        }
        $db->prepare('UPDATE empresas SET aviso_copia = ?, aviso_copia_min = ? WHERE id = ?')->execute([$correo, $min, $empresaId]);
    }

    /** ¿A esa persona le llega el respaldo por correo? (encendido y el servidor puede mandar correos) */
    public static function activoPara(PDO $db, int $usuarioId): bool
    {
        return Correo::configurado() && self::ajustes($db, $usuarioId)['min'] > 0;
    }

    /** Correo de prueba para la persona. @throws \RuntimeException */
    public static function prueba(PDO $db, int $usuarioId, string $idioma = 'es'): string
    {
        $st = $db->prepare('SELECT correo, aviso_correo FROM usuarios WHERE id = ?');
        $st->execute([$usuarioId]);
        $para = self::correoDe($st->fetch() ?: ['correo' => '', 'aviso_correo' => '']);
        $t = fn (string $s) => tukan_tr($s, $idioma);
        Correo::enviar($para, '✅ ' . $t('Así te llegarán los avisos de chats sin contestar'),
            $t('Correo de prueba: si nadie lee un mensaje de un cliente, te llegará un correo como este con el enlace para abrir el chat.') . "\n\n" . self::base() . '/?r=bandeja');
        return $para;
    }
}
