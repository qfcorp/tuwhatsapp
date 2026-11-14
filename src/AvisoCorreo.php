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
 *    y nunca dos veces el mismo mensaje (tabla avisos_correo, con 'fin' cuando ya se avisó a todos). Solo mensajes de las últimas 12 horas
 *    y de después de instalar esto. Candado de PostgreSQL + reservas antes de mandar: dos corridas a la vez no repiten correos.
 *  - Una respuesta automática (IA, chatbot…) cuenta como contestado, salvo que la IA haya pasado el chat a una persona.
 */
final class AvisoCorreo
{
    public const MINUTOS = [0, 5, 15, 30, 60];
    public const ESPERA_ENTRE_CORREOS = 15;   // minutos

    /** Llave del candado de PostgreSQL: dos corridas del cron a la vez no mandan el mismo correo dos veces. */
    private const CANDADO = 7720141126;
    public const CHATS_POR_EMPRESA = 50;     // por corrida: una empresa con miles de chats sin leer no deja sin correo a las demás
    public const CHATS_POR_CORRIDA = 1000;

    /**
     * Mensaje respondido: si después del mensaje del cliente salió una respuesta de una persona (panel o celular) o una
     * respuesta automática (agente con IA, chatbot, respuesta automática, tienda). Si el agente con IA pasó el chat a una
     * persona (contactos.traspaso_en) y ninguna persona contestó después de eso, solo cuenta la respuesta de una persona.
     * Las campañas y los pendientes (para mandar a mano) no cuentan como respuesta.
     */
    public const SQL_RESPONDIDO = "EXISTS (SELECT 1 FROM mensajes r WHERE r.contacto_id = m.contacto_id AND r.direccion = 'sale'
              AND r.creado > m.creado AND r.estado <> 'fallido'
              AND (r.origen IN ('panel', 'celular')
                   OR (r.origen IN ('bot', 'flujo', 'auto', 'tienda')
                       AND NOT (c.traspaso_en IS NOT NULL AND NOT EXISTS (SELECT 1 FROM mensajes h WHERE h.contacto_id = m.contacto_id
                                AND h.direccion = 'sale' AND h.origen IN ('panel', 'celular') AND h.creado > c.traspaso_en)))))";

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
        if (!$db->query('SELECT pg_try_advisory_lock(' . self::CANDADO . ')')->fetchColumn()) {
            return $r;   // otra corrida del cron está mandando: esta no hace nada
        }
        try {
            self::limpiar($db, $ahora);
            return self::mandar($db, $ahora, $r);
        } finally {
            $db->query('SELECT pg_advisory_unlock(' . self::CANDADO . ')');
        }
    }

    /** Una vez al día: borra las marcas de avisos con más de 30 días. */
    public static function limpiar(PDO $db, DateTimeImmutable $ahora): int
    {
        $hoy = $ahora->format('Y-m-d');
        $st = $db->prepare("INSERT INTO ajustes (clave, valor) VALUES ('aviso_correo_limpieza', ?)
                            ON CONFLICT (clave) DO UPDATE SET valor = EXCLUDED.valor WHERE ajustes.valor IS DISTINCT FROM EXCLUDED.valor RETURNING clave");
        $st->execute([$hoy]);
        if (!$st->fetchColumn()) {
            return 0;
        }
        $b = $db->prepare('DELETE FROM avisos_correo WHERE creado < ?');
        $b->execute([$ahora->modify('-30 days')->format('c')]);
        return $b->rowCount();
    }

    /** @param array{correos:int, fallidos:int} $r */
    private static function mandar(PDO $db, DateTimeImmutable $ahora, array $r): array
    {
        $desde = new DateTimeImmutable((string) $db->query("SELECT valor FROM ajustes WHERE clave = 'aviso_correo_desde'")->fetchColumn());
        $hace12 = $ahora->modify('-12 hours');
        // Un renglón por chat (no por mensaje), del más nuevo al más viejo, con tope por empresa y por corrida.
        // Los mensajes que ya se avisaron a todos los que tocaba quedan marcados 'fin' y no vuelven a leerse.
        $st = $db->prepare("WITH pend AS (
                SELECT m.id, m.creado, left(m.texto, 300) AS texto, m.tipo, m.contacto_id, m.linea_id, l.empresa_id, l.nombre AS l_nombre
                FROM mensajes m
                JOIN contactos c ON c.id = m.contacto_id
                JOIN lineas l ON l.id = m.linea_id
                WHERE m.direccion = 'entra' AND m.origen = 'cliente' AND NOT m.leido
                  AND m.creado > ? AND m.creado <= ? AND c.tipo <> 'personal'
                  AND NOT EXISTS (SELECT 1 FROM avisos_correo a WHERE a.mensaje_id = m.id AND a.destino = 'fin')
                  AND NOT " . self::SQL_RESPONDIDO . "
            ), chats AS (
                SELECT contacto_id, linea_id, empresa_id, l_nombre, max(creado) AS ultimo,
                       json_agg(json_build_object('id', id, 'creado', creado, 'texto', texto, 'tipo', tipo) ORDER BY creado) AS mensajes,
                       row_number() OVER (PARTITION BY empresa_id ORDER BY max(creado) DESC) AS puesto
                FROM pend GROUP BY contacto_id, linea_id, empresa_id, l_nombre
            )
            SELECT * FROM chats WHERE puesto <= " . self::CHATS_POR_EMPRESA . ' ORDER BY ultimo DESC LIMIT ' . self::CHATS_POR_CORRIDA);
        $st->execute([max($desde, $hace12)->format('c'), $ahora->modify('-5 minutes')->format('c')]);
        $chats = $st->fetchAll();
        if (!$chats) {
            return $r;
        }
        foreach ($chats as $i => $ch) {
            $chats[$i]['mensajes'] = json_decode((string) $ch['mensajes'], true) ?: [];
        }
        // Qué ya se avisó a quién (una sola consulta para todos los mensajes de esta corrida)
        $ids = array_merge(...array_map(fn ($ch) => array_map(fn ($m) => (int) $m['id'], $ch['mensajes']), $chats));
        $avisados = [];   // mensaje_id => [destino => true]
        foreach (array_chunk($ids, 1000) as $parte) {
            $q = $db->query('SELECT mensaje_id, destino FROM avisos_correo WHERE mensaje_id IN (' . implode(',', $parte) . ')');
            foreach ($q->fetchAll() as $a) {
                $avisados[(int) $a['mensaje_id']][$a['destino']] = true;
            }
        }
        $empresas = $equipos = [];
        $grupos = [];    // clave => ['para', 'idioma', 'usuario_id'|null, 'empresa_id', 'destino', 'mensajes' => []]
        $destinos = [];  // mensaje_id => lista de destinos que le tocan (para saber cuándo queda 'fin')
        foreach ($chats as $ch) {
            $eid = (int) $ch['empresa_id'];
            $empresas[$eid] ??= Empresas::buscar($db, $eid);
            if (!Empresas::alDia($empresas[$eid])) {
                continue;
            }
            $equipos[$eid] ??= self::equipo($db, $eid);
            $contacto = $db->query('SELECT * FROM contactos WHERE id = ' . (int) $ch['contacto_id'])->fetch();
            $copia = trim((string) ($empresas[$eid]['aviso_copia'] ?? ''));
            $hayCopia = $copia !== '' && Correo::valido($copia);
            $quienes = array_values(array_filter(self::quienes($equipos[$eid], $contacto), fn ($u) => (int) $u['aviso_correo_min'] > 0));
            foreach ($ch['mensajes'] as $m) {
                $mid = (int) $m['id'];
                $m += ['contacto_id' => (int) $ch['contacto_id'], 'contacto' => $contacto, 'l_nombre' => $ch['l_nombre']];
                $minutos = ($ahora->getTimestamp() - (new DateTimeImmutable($m['creado']))->getTimestamp()) / 60;
                $destinos[$mid] = [];
                foreach ($quienes as $u) {
                    $destino = 'u' . $u['id'];
                    $destinos[$mid][] = $destino;
                    if ($minutos < (int) $u['aviso_correo_min'] || isset($avisados[$mid][$destino])) {
                        continue;
                    }
                    $grupos[$destino] ??= ['para' => self::correoDe($u), 'idioma' => (string) ($u['idioma'] ?: 'es'), 'usuario_id' => (int) $u['id'],
                                           'empresa_id' => $eid, 'destino' => $destino, 'mensajes' => []];
                    $grupos[$destino]['mensajes'][] = $m;
                }
                if ($hayCopia) {
                    $destinos[$mid][] = 'copia';
                    if ($minutos >= (int) $empresas[$eid]['aviso_copia_min'] && !isset($avisados[$mid]['copia'])) {
                        $grupos["copia$eid"] ??= ['para' => $copia, 'idioma' => 'es', 'usuario_id' => null, 'empresa_id' => $eid, 'destino' => 'copia', 'mensajes' => []];
                        $grupos["copia$eid"]['mensajes'][] = $m;
                    }
                }
            }
        }
        $limite = $ahora->modify('-' . self::ESPERA_ENTRE_CORREOS . ' minutes')->format('c');
        $marcar = $db->prepare('INSERT INTO avisos_correo (mensaje_id, destino, creado) VALUES (?, ?, ?) ON CONFLICT DO NOTHING RETURNING mensaje_id');
        foreach ($grupos as $g) {
            // 1) Reservar el turno de esa persona (o de la copia) ANTES de mandar: si otra corrida ya lo tomó, no se repite
            if ($g['usuario_id'] === null) {
                $res = $db->prepare('UPDATE empresas e SET aviso_copia_ultimo = ? FROM (SELECT id, aviso_copia_ultimo AS antes FROM empresas WHERE id = ? FOR UPDATE) p
                                     WHERE e.id = p.id AND (p.antes IS NULL OR p.antes <= ?) RETURNING p.id, p.antes');
                $res->execute([$ahora->format('c'), $g['empresa_id'], $limite]);
            } else {
                $res = $db->prepare('UPDATE usuarios u SET aviso_correo_ultimo = ? FROM (SELECT id, aviso_correo_ultimo AS antes FROM usuarios WHERE id = ? FOR UPDATE) p
                                     WHERE u.id = p.id AND (p.antes IS NULL OR p.antes <= ?) RETURNING p.id, p.antes');
                $res->execute([$ahora->format('c'), $g['usuario_id'], $limite]);
            }
            $turno = $res->fetch();
            if (!$turno) {
                continue;   // ya le llegó un correo hace poco: lo pendiente va en el siguiente
            }
            // 2) Reservar cada mensaje para ese destino; solo van en el correo los que esta corrida pudo reservar
            $mios = [];
            foreach ($g['mensajes'] as $m) {
                $marcar->execute([$m['id'], $g['destino'], $ahora->format('c')]);
                if ($marcar->fetchColumn() !== false) {
                    $mios[] = $m;
                    $avisados[(int) $m['id']][$g['destino']] = true;
                }
            }
            $volverTurno = function (?string $valor) use ($db, $g): void {
                if ($g['usuario_id'] === null) {
                    $db->prepare('UPDATE empresas SET aviso_copia_ultimo = ? WHERE id = ?')->execute([$valor, $g['empresa_id']]);
                } else {
                    $db->prepare('UPDATE usuarios SET aviso_correo_ultimo = ? WHERE id = ?')->execute([$valor, $g['usuario_id']]);
                }
            };
            if (!$mios) {
                $volverTurno($turno['antes']);
                continue;
            }
            // 3) Mandar
            [$asunto, $texto, $html] = self::armar($mios, $g['idioma'], $ahora, $g['usuario_id'] === null);
            try {
                Correo::enviar($g['para'], $asunto, $texto, $html);
                $r['correos']++;
            } catch (\Throwable $e) {
                error_log('tuwhatsapp aviso por correo a ' . $g['para'] . ': ' . $e->getMessage());
                $r['fallidos']++;
                // No se pierde: se sueltan los mensajes y se vuelve a intentar en unos 5 minutos (no cada minuto)
                $soltar = $db->prepare('DELETE FROM avisos_correo WHERE mensaje_id = ? AND destino = ?');
                foreach ($mios as $m) {
                    $soltar->execute([$m['id'], $g['destino']]);
                    unset($avisados[(int) $m['id']][$g['destino']]);
                }
                $volverTurno($ahora->modify('-' . (self::ESPERA_ENTRE_CORREOS - 5) . ' minutes')->format('c'));
            }
        }
        // 4) Mensajes ya avisados a todos los que tocaba (o que no le tocan a nadie): 'fin', ya no se vuelven a revisar
        $fin = $db->prepare("INSERT INTO avisos_correo (mensaje_id, destino, creado) VALUES (?, 'fin', ?) ON CONFLICT DO NOTHING");
        foreach ($destinos as $mid => $lista) {
            if (!array_filter($lista, fn ($d) => !isset($avisados[$mid][$d]))) {
                $fin->execute([$mid, $ahora->format('c')]);
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

    public const PRUEBAS_POR_HORA = 3;

    /**
     * Correo de prueba para la persona: solo a su propio correo (o al de respaldo que guardó) y máximo 3 por hora.
     * @throws \RuntimeException
     */
    public static function prueba(PDO $db, int $usuarioId, string $idioma = 'es', ?DateTimeImmutable $ahora = null): string
    {
        $ahora ??= new DateTimeImmutable();
        $st = $db->prepare('SELECT correo, aviso_correo, aviso_pruebas FROM usuarios WHERE id = ? FOR UPDATE');
        $db->beginTransaction();
        try {
            $st->execute([$usuarioId]);
            $u = $st->fetch() ?: ['correo' => '', 'aviso_correo' => '', 'aviso_pruebas' => '[]'];
            $hace1h = $ahora->getTimestamp() - 3600;
            $veces = array_values(array_filter(array_map('intval', json_decode((string) $u['aviso_pruebas'], true) ?: []), fn ($t) => $t > $hace1h));
            if (count($veces) >= self::PRUEBAS_POR_HORA) {
                throw new \RuntimeException('Ya mandaste 3 correos de prueba en la última hora. Intenta más tarde.');
            }
            $veces[] = $ahora->getTimestamp();
            $db->prepare('UPDATE usuarios SET aviso_pruebas = ? WHERE id = ?')->execute([json_encode($veces), $usuarioId]);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        $para = self::correoDe($u);
        $t = fn (string $s) => tukan_tr($s, $idioma);
        Correo::enviar($para, '✅ ' . $t('Así te llegarán los avisos de chats sin contestar'),
            $t('Correo de prueba: si nadie lee un mensaje de un cliente, te llegará un correo como este con el enlace para abrir el chat.') . "\n\n" . self::base() . '/?r=bandeja');
        return $para;
    }
}
