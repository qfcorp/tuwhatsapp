<?php
declare(strict_types=1);

namespace TuWhatsApp;

use DateTimeImmutable;
use PDO;

/**
 * Avisos por Telegram (14 nov 2026): cada persona del equipo conecta su Telegram con el bot PROPIO de Tukán Mensajes y le llegan
 * los mensajes de sus clientes, aunque la app esté cerrada o los avisos del celular fallen.
 *  - El bot se configura en el servidor con deploy/configurar_telegram.sh (config/.env: TUWA_TELEGRAM_TOKEN, TUWA_TELEGRAM_SECRETO,
 *    TUWA_TELEGRAM_BOT). No usa TELEGRAM_BOT_TOKEN/TELEGRAM_CHAT_ID, que son las alertas internas de Tukán.
 *  - Vincular: App en el celular → "Conectar mi Telegram" crea un código de un solo uso (15 min; en la base solo su sha256) y abre
 *    t.me/<bot>?start=<código>. El bot recibe "/start <código>" en public/telegram.php y guarda el chat en esa persona.
 *  - Cuándo avisa (cada persona elige): -1 = cada mensaje nuevo al momento (por defecto) · 5/15/30/60 = solo si nadie contesta en esos
 *    minutos (un solo mensaje con la lista de chats, como el respaldo por correo) · 0 = apagado.
 *  - Al momento: máximo 1 aviso por chat cada 2 minutos por persona (las ráfagas de mensajes no llenan el Telegram).
 *  - Si la persona bloqueó el bot (Telegram responde 403), se desconecta sola y no se vuelve a intentar.
 */
final class Telegram
{
    public const MODOS = [-1, 0, 5, 15, 30, 60];
    public const MINUTOS_RAFAGA = 2;
    public const ESPERA_RESUMEN = 10;   // minutos entre dos resúmenes de "nadie ha contestado" a la misma persona
    public const MINUTOS_CODIGO = 15;
    private const CANDADO = 7720141127;

    public static function token(): string
    {
        return trim((string) getenv('TUWA_TELEGRAM_TOKEN'));
    }

    public static function bot(): string
    {
        return ltrim(trim((string) getenv('TUWA_TELEGRAM_BOT')), '@');
    }

    public static function secreto(): string
    {
        return trim((string) getenv('TUWA_TELEGRAM_SECRETO'));
    }

    /** ¿Tukán ya activó el bot en este servidor? */
    public static function configurado(): bool
    {
        return self::token() !== '' && self::bot() !== '' && strlen(self::secreto()) >= 16;
    }

    private static function urlApi(string $metodo, ?string $token = null): string
    {
        $base = rtrim((string) (getenv('TUWA_TELEGRAM_API_URL') ?: 'https://api.telegram.org'), '/');
        return $base . '/bot' . ($token ?? self::token()) . '/' . $metodo;
    }

    /**
     * Llama a la API de Telegram. Tiempo corto: nunca frena el webhook de WhatsApp.
     * @return array{codigo:int, json:?array}
     */
    public static function api(string $metodo, array $datos = [], int $tiempo = 5, ?string $token = null): array
    {
        $ch = curl_init(self::urlApi($metodo, $token));
        curl_setopt_array($ch, self::opcionesCurl($datos, $tiempo));
        $r = curl_exec($ch);
        $codigo = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['codigo' => $codigo, 'json' => is_string($r) ? (json_decode($r, true) ?: null) : null];
    }

    private static function opcionesCurl(array $datos, int $tiempo): array
    {
        return [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_CONNECTTIMEOUT => min(3, $tiempo), CURLOPT_TIMEOUT => $tiempo,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    /**
     * Manda varios mensajes a la vez (en paralelo): con 10 personas no se esperan 10 llamadas una tras otra.
     * @param array<int, array> $mensajes usuario_id => datos de sendMessage
     * @return array<int, int> usuario_id => código HTTP (0 = sin conexión)
     */
    private static function mandarVarios(array $mensajes, int $tiempo = 4): array
    {
        if (!$mensajes) {
            return [];
        }
        $multi = curl_multi_init();
        $manijas = [];
        foreach ($mensajes as $uid => $datos) {
            $ch = curl_init(self::urlApi('sendMessage'));
            curl_setopt_array($ch, self::opcionesCurl($datos, $tiempo));
            curl_multi_add_handle($multi, $ch);
            $manijas[$uid] = $ch;
        }
        do {
            $st = curl_multi_exec($multi, $activos);
            if ($activos) {
                curl_multi_select($multi, 0.5);
            }
        } while ($activos && $st === CURLM_OK);
        $r = [];
        foreach ($manijas as $uid => $ch) {
            $r[$uid] = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
        }
        curl_multi_close($multi);
        return $r;
    }

    /** Enlace del programa (para el botón "Abrir el chat"). */
    public static function base(): string
    {
        return AvisoCorreo::base();
    }

    private static function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    // ------------------------------------------------------------------
    // Vincular
    // ------------------------------------------------------------------

    /** Código de un solo uso (15 min) para "Conectar mi Telegram". Devuelve el enlace t.me para abrir. */
    public static function crearCodigo(PDO $db, int $usuarioId, ?DateTimeImmutable $ahora = null): string
    {
        if (!self::configurado()) {
            throw new \RuntimeException('Tukán todavía no activó los avisos por Telegram.');
        }
        $ahora ??= new DateTimeImmutable();
        $codigo = bin2hex(random_bytes(16));
        $db->prepare('UPDATE usuarios SET tg_codigo = ?, tg_codigo_vence = ? WHERE id = ?')
           ->execute([hash('sha256', $codigo), $ahora->modify('+' . self::MINUTOS_CODIGO . ' minutes')->format('c'), $usuarioId]);
        return 'https://t.me/' . rawurlencode(self::bot()) . '?start=' . $codigo;
    }

    /**
     * Vincula el chat de Telegram con la persona dueña del código. Un chat de Telegram solo puede estar en una persona a la vez.
     * @return array|null la persona (id, nombre, idioma) o null si el código no sirve
     */
    public static function vincular(PDO $db, string $codigo, int $chatId, string $usuarioTelegram = '', ?DateTimeImmutable $ahora = null): ?array
    {
        $ahora ??= new DateTimeImmutable();
        if (!preg_match('/^[0-9a-f]{32}$/', $codigo) || $chatId === 0) {
            return null;
        }
        $db->beginTransaction();
        try {
            $st = $db->prepare('SELECT id, nombre, idioma FROM usuarios WHERE tg_codigo = ? AND tg_codigo_vence > ? AND activo FOR UPDATE');
            $st->execute([hash('sha256', $codigo), $ahora->format('c')]);
            $u = $st->fetch();
            if (!$u) {
                $db->rollBack();
                return null;
            }
            $db->prepare('UPDATE usuarios SET tg_chat_id = NULL WHERE tg_chat_id = ? AND id <> ?')->execute([$chatId, $u['id']]);
            $db->prepare('UPDATE usuarios SET tg_chat_id = ?, tg_nombre = ?, tg_codigo = NULL, tg_codigo_vence = NULL,
                                 tg_modo = CASE WHEN tg_modo = 0 THEN -1 ELSE tg_modo END WHERE id = ?')
               ->execute([$chatId, mb_substr($usuarioTelegram, 0, 64), $u['id']]);
            $db->commit();
            return $u;
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public static function desvincularChat(PDO $db, int $chatId): int
    {
        $st = $db->prepare("UPDATE usuarios SET tg_chat_id = NULL, tg_nombre = '' WHERE tg_chat_id = ?");
        $st->execute([$chatId]);
        return $st->rowCount();
    }

    public static function desvincular(PDO $db, int $usuarioId): void
    {
        $db->prepare("UPDATE usuarios SET tg_chat_id = NULL, tg_nombre = '', tg_codigo = NULL, tg_codigo_vence = NULL WHERE id = ?")->execute([$usuarioId]);
    }

    /** @return array{configurado:bool, bot:string, conectado:bool, nombre:string, modo:int} */
    public static function estado(PDO $db, int $usuarioId): array
    {
        $st = $db->prepare('SELECT tg_chat_id, tg_nombre, tg_modo FROM usuarios WHERE id = ?');
        $st->execute([$usuarioId]);
        $u = $st->fetch() ?: ['tg_chat_id' => null, 'tg_nombre' => '', 'tg_modo' => -1];
        return ['configurado' => self::configurado(), 'bot' => self::bot(), 'conectado' => $u['tg_chat_id'] !== null,
                'nombre' => (string) $u['tg_nombre'], 'modo' => (int) $u['tg_modo']];
    }

    public static function guardarModo(PDO $db, int $usuarioId, int $modo): void
    {
        if (!in_array($modo, self::MODOS, true)) {
            $modo = -1;
        }
        $db->prepare('UPDATE usuarios SET tg_modo = ? WHERE id = ?')->execute([$modo, $usuarioId]);
    }

    /** ¿A esa persona le llegan avisos por Telegram? (para la cinta amarilla) */
    public static function activoPara(PDO $db, int $usuarioId): bool
    {
        if (!self::configurado()) {
            return false;
        }
        $e = self::estado($db, $usuarioId);
        return $e['conectado'] && $e['modo'] !== 0;
    }

    /**
     * Lo que llega del bot (webhook). Devuelve la respuesta para mandar en la misma respuesta HTTP (method sendMessage)
     * o null si no hay que contestar.
     */
    public static function procesarUpdate(PDO $db, array $u, ?DateTimeImmutable $ahora = null): ?array
    {
        $m = $u['message'] ?? null;
        if (!is_array($m) || ($m['chat']['type'] ?? '') !== 'private' || !isset($m['chat']['id'])) {
            return null;   // grupos, canales, ediciones…: nada
        }
        $chat = (int) $m['chat']['id'];
        $texto = trim((string) ($m['text'] ?? ''));
        $responder = fn (string $t) => ['method' => 'sendMessage', 'chat_id' => $chat, 'text' => $t];
        if (preg_match('/^\/start(?:@\w+)?\s+(\S+)$/', $texto, $p)) {
            $persona = self::vincular($db, $p[1], $chat, (string) ($m['from']['username'] ?? ''), $ahora);
            if ($persona === null) {
                return $responder('Ese enlace ya no sirve (dura 15 minutos y se usa una sola vez). Vuelve a tocar «Conectar mi Telegram» en WhatsApp Tukán → App en el celular.');
            }
            $idioma = (string) ($persona['idioma'] ?: 'es');
            return $responder('✅ ' . tukan_tr('Listo', $idioma) . ', ' . $persona['nombre'] . ': '
                . tukan_tr('aquí te llegarán los mensajes de tus clientes de WhatsApp Tukán.', $idioma) . "\n\n"
                . tukan_tr('Para dejar de recibirlos escribe /parar.', $idioma));
        }
        if (preg_match('/^\/(parar|stop)(?:@\w+)?$/i', $texto)) {
            return self::desvincularChat($db, $chat) > 0
                ? $responder('Listo: ya no te llegarán avisos aquí. Para volver a conectarte: WhatsApp Tukán → App en el celular → Conectar mi Telegram.')
                : $responder('Este Telegram no estaba conectado a WhatsApp Tukán.');
        }
        return $responder('Soy el bot de avisos de WhatsApp Tukán. Para conectarte: entra a WhatsApp Tukán → App en el celular → Avisos por Telegram → Conectar mi Telegram.');
    }

    // ------------------------------------------------------------------
    // Avisar
    // ------------------------------------------------------------------

    /** Texto del aviso (HTML de Telegram, todo escapado) y su botón "Abrir el chat". */
    public static function aviso(array $contacto, string $linea, string $texto, string $idioma = 'es'): array
    {
        $extracto = trim(preg_replace('/\s+/u', ' ', $texto));
        $extracto = $extracto === '' ? tukan_tr('(foto, audio u otro archivo)', $idioma) : mb_strimwidth($extracto, 0, 300, '…');
        $html = '💬 <b>' . self::h(Contactos::nombre($contacto)) . '</b>' . "\n" . '📱 ' . self::h($linea) . "\n\n" . '«' . self::h($extracto) . '»';
        return ['text' => $html, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true,
                'reply_markup' => ['inline_keyboard' => [[['text' => tukan_tr('Abrir el chat', $idioma), 'url' => self::base() . '/?r=chat&c=' . (int) $contacto['id']]]]]];
    }

    /**
     * Mensaje nuevo de un cliente: aviso al momento a quien lo atiende (o al equipo que recibe chats), igual que Push::mensajeNuevo.
     * Solo a quien eligió "cada mensaje nuevo al momento". Máximo 1 aviso por chat cada 2 minutos por persona. Devuelve cuántos salieron.
     */
    public static function mensajeNuevo(PDO $db, array $linea, array $contacto, string $texto, int $mensajeId, ?DateTimeImmutable $ahora = null): int
    {
        if (!self::configurado() || ($contacto['tipo'] ?? '') === 'personal') {
            return 0;
        }
        $ahora ??= new DateTimeImmutable();
        if (!empty($contacto['asignado_a'])) {
            $st = $db->prepare('SELECT id, idioma, tg_chat_id FROM usuarios WHERE id = ? AND empresa_id = ? AND activo AND tg_chat_id IS NOT NULL AND tg_modo = -1');
            $st->execute([(int) $contacto['asignado_a'], (int) $linea['empresa_id']]);
        } else {
            $st = $db->prepare('SELECT id, idioma, tg_chat_id FROM usuarios WHERE empresa_id = ? AND activo AND recibe_chats AND tg_chat_id IS NOT NULL AND tg_modo = -1');
            $st->execute([(int) $linea['empresa_id']]);
        }
        $personas = $st->fetchAll();
        if (!$personas) {
            return 0;
        }
        $rafaga = $db->prepare('INSERT INTO tg_rafagas (usuario_id, contacto_id, ultimo) VALUES (?, ?, ?)
                                ON CONFLICT (usuario_id, contacto_id) DO UPDATE SET ultimo = EXCLUDED.ultimo
                                WHERE tg_rafagas.ultimo <= ? RETURNING usuario_id');
        $limite = $ahora->modify('-' . self::MINUTOS_RAFAGA . ' minutes')->format('c');
        $mensajes = [];
        foreach ($personas as $p) {
            $rafaga->execute([(int) $p['id'], (int) $contacto['id'], $ahora->format('c'), $limite]);
            if ($rafaga->fetchColumn() === false) {
                continue;   // ya se le avisó de este chat hace menos de 2 minutos
            }
            $mensajes[(int) $p['id']] = ['chat_id' => (int) $p['tg_chat_id']] + self::aviso($contacto, (string) $linea['nombre'], $texto, (string) ($p['idioma'] ?: 'es'));
        }
        return self::entregar($db, $mensajes);
    }

    /** Manda y procesa las respuestas: 403 (bloqueó el bot o borró el chat) → se desconecta sola. */
    private static function entregar(PDO $db, array $mensajes): int
    {
        $ok = 0;
        foreach (self::mandarVarios($mensajes) as $uid => $codigo) {
            if ($codigo >= 200 && $codigo < 300) {
                $ok++;
            } elseif ($codigo === 403) {
                self::desvincular($db, $uid);
            } else {
                error_log('tuwhatsapp telegram: respuesta ' . $codigo . ' para el usuario ' . $uid);
            }
        }
        return $ok;
    }

    /** Aviso de prueba a la propia persona. */
    public static function prueba(PDO $db, int $usuarioId, string $idioma = 'es'): bool
    {
        $st = $db->prepare('SELECT tg_chat_id FROM usuarios WHERE id = ? AND tg_chat_id IS NOT NULL');
        $st->execute([$usuarioId]);
        $chat = $st->fetchColumn();
        if ($chat === false) {
            throw new \RuntimeException('Primero conecta tu Telegram.');
        }
        $r = self::entregar($db, [$usuarioId => ['chat_id' => (int) $chat, 'text' => '✅ ' . tukan_tr('Así te llegarán los mensajes de tus clientes.', $idioma),
                                                 'reply_markup' => ['inline_keyboard' => [[['text' => tukan_tr('Abrir WhatsApp Tukán', $idioma), 'url' => self::base() . '/?r=bandeja']]]]]]);
        return $r > 0;
    }

    /**
     * "Solo si nadie contesta en N minutos": un solo mensaje por persona con la lista de chats pendientes (mismas reglas de
     * "contestado" que el respaldo por correo). Lo corre bin/campanias.php cada minuto. Marcas en avisos_correo con destino 't<id>'
     * y 'tfin' cuando el mensaje ya se avisó a todos los que tocaba. Candado + reserva del turno: dos corridas a la vez no repiten.
     * @return array{mensajes:int, fallidos:int}
     */
    public static function procesarPendientes(PDO $db, ?DateTimeImmutable $ahora = null): array
    {
        $r = ['mensajes' => 0, 'fallidos' => 0];
        $ahora ??= new DateTimeImmutable();
        $db->prepare("INSERT INTO ajustes (clave, valor) VALUES ('aviso_tg_desde', ?) ON CONFLICT (clave) DO NOTHING")->execute([$ahora->format('c')]);
        if (!self::configurado()) {
            return $r;
        }
        $hay = (int) $db->query('SELECT count(*) FROM usuarios WHERE activo AND tg_chat_id IS NOT NULL AND tg_modo > 0')->fetchColumn();
        if ($hay === 0 || !$db->query('SELECT pg_try_advisory_lock(' . self::CANDADO . ')')->fetchColumn()) {
            return $r;
        }
        try {
            return self::mandarPendientes($db, $ahora, $r);
        } finally {
            $db->query('SELECT pg_advisory_unlock(' . self::CANDADO . ')');
        }
    }

    private static function mandarPendientes(PDO $db, DateTimeImmutable $ahora, array $r): array
    {
        $desde = new DateTimeImmutable((string) $db->query("SELECT valor FROM ajustes WHERE clave = 'aviso_tg_desde'")->fetchColumn());
        $st = $db->prepare("WITH pend AS (
                SELECT m.id, m.creado, left(m.texto, 300) AS texto, m.tipo, m.contacto_id, l.empresa_id, l.nombre AS l_nombre
                FROM mensajes m
                JOIN contactos c ON c.id = m.contacto_id
                JOIN lineas l ON l.id = m.linea_id
                WHERE m.direccion = 'entra' AND m.origen = 'cliente' AND NOT m.leido
                  AND m.creado > ? AND m.creado <= ? AND c.tipo <> 'personal'
                  AND l.empresa_id IN (SELECT empresa_id FROM usuarios WHERE activo AND tg_chat_id IS NOT NULL AND tg_modo > 0)
                  AND NOT EXISTS (SELECT 1 FROM avisos_correo a WHERE a.mensaje_id = m.id AND a.destino = 'tfin')
                  AND NOT " . AvisoCorreo::SQL_RESPONDIDO . "
            ), chats AS (
                SELECT contacto_id, empresa_id, l_nombre, max(creado) AS ultimo,
                       json_agg(json_build_object('id', id, 'creado', creado, 'texto', texto, 'tipo', tipo) ORDER BY creado) AS mensajes,
                       row_number() OVER (PARTITION BY empresa_id ORDER BY max(creado) DESC) AS puesto
                FROM pend GROUP BY contacto_id, empresa_id, l_nombre
            )
            SELECT * FROM chats WHERE puesto <= " . AvisoCorreo::CHATS_POR_EMPRESA . ' ORDER BY ultimo DESC LIMIT ' . AvisoCorreo::CHATS_POR_CORRIDA);
        $st->execute([max($desde, $ahora->modify('-12 hours'))->format('c'), $ahora->modify('-5 minutes')->format('c')]);
        $chats = $st->fetchAll();
        if (!$chats) {
            return $r;
        }
        $equipos = $empresas = $grupos = $destinos = $avisados = [];
        $ids = [];
        foreach ($chats as $i => $ch) {
            $chats[$i]['mensajes'] = json_decode((string) $ch['mensajes'], true) ?: [];
            foreach ($chats[$i]['mensajes'] as $m) {
                $ids[] = (int) $m['id'];
            }
        }
        foreach (array_chunk($ids, 1000) as $parte) {
            foreach ($db->query("SELECT mensaje_id, destino FROM avisos_correo WHERE destino LIKE 't%' AND mensaje_id IN (" . implode(',', $parte) . ')')->fetchAll() as $a) {
                $avisados[(int) $a['mensaje_id']][$a['destino']] = true;
            }
        }
        foreach ($chats as $ch) {
            $eid = (int) $ch['empresa_id'];
            $empresas[$eid] ??= Empresas::buscar($db, $eid);
            if (!$empresas[$eid] || !Empresas::alDia($empresas[$eid])) {
                continue;
            }
            $equipos[$eid] ??= self::equipo($db, $eid);
            $contacto = $db->query('SELECT * FROM contactos WHERE id = ' . (int) $ch['contacto_id'])->fetch();
            $quienes = array_values(array_filter(self::quienes($equipos[$eid], $contacto), fn ($u) => $u['tg_chat_id'] !== null && (int) $u['tg_modo'] > 0));
            foreach ($ch['mensajes'] as $m) {
                $mid = (int) $m['id'];
                $m += ['contacto' => $contacto, 'l_nombre' => $ch['l_nombre']];
                $minutos = ($ahora->getTimestamp() - (new DateTimeImmutable($m['creado']))->getTimestamp()) / 60;
                $destinos[$mid] = [];
                foreach ($quienes as $u) {
                    $destino = 't' . $u['id'];
                    $destinos[$mid][] = $destino;
                    if ($minutos < (int) $u['tg_modo'] || isset($avisados[$mid][$destino])) {
                        continue;
                    }
                    $grupos[$destino] ??= ['u' => $u, 'destino' => $destino, 'mensajes' => []];
                    $grupos[$destino]['mensajes'][] = $m;
                }
            }
        }
        $limite = $ahora->modify('-' . self::ESPERA_RESUMEN . ' minutes')->format('c');
        $marcar = $db->prepare('INSERT INTO avisos_correo (mensaje_id, destino, creado) VALUES (?, ?, ?) ON CONFLICT DO NOTHING RETURNING mensaje_id');
        $soltar = $db->prepare('DELETE FROM avisos_correo WHERE mensaje_id = ? AND destino = ?');
        foreach ($grupos as $g) {
            $uid = (int) $g['u']['id'];
            // Reservar el turno ANTES de mandar (si otra corrida lo tomó, no se repite)
            $res = $db->prepare('UPDATE usuarios u SET tg_ultimo = ? FROM (SELECT id, tg_ultimo AS antes FROM usuarios WHERE id = ? FOR UPDATE) p
                                 WHERE u.id = p.id AND (p.antes IS NULL OR p.antes <= ?) RETURNING p.antes');
            $res->execute([$ahora->format('c'), $uid, $limite]);
            $turno = $res->fetch();
            if (!$turno) {
                continue;
            }
            $mios = [];
            foreach ($g['mensajes'] as $m) {
                $marcar->execute([$m['id'], $g['destino'], $ahora->format('c')]);
                if ($marcar->fetchColumn() !== false) {
                    $mios[] = $m;
                    $avisados[(int) $m['id']][$g['destino']] = true;
                }
            }
            if (!$mios) {
                $db->prepare('UPDATE usuarios SET tg_ultimo = ? WHERE id = ?')->execute([$turno['antes'], $uid]);
                continue;
            }
            $codigo = self::mandarVarios([$uid => ['chat_id' => (int) $g['u']['tg_chat_id']] + self::resumen($mios, (string) ($g['u']['idioma'] ?: 'es'), $ahora)])[$uid] ?? 0;
            if ($codigo >= 200 && $codigo < 300) {
                $r['mensajes']++;
                continue;
            }
            $r['fallidos']++;
            if ($codigo === 403) {   // bloqueó el bot: se desconecta y no se insiste
                self::desvincular($db, $uid);
                continue;
            }
            // Falla temporal: se sueltan los mensajes y se vuelve a intentar en unos 5 minutos
            foreach ($mios as $m) {
                $soltar->execute([$m['id'], $g['destino']]);
                unset($avisados[(int) $m['id']][$g['destino']]);
            }
            $db->prepare('UPDATE usuarios SET tg_ultimo = ? WHERE id = ?')->execute([$ahora->modify('-' . (self::ESPERA_RESUMEN - 5) . ' minutes')->format('c'), $uid]);
        }
        $fin = $db->prepare("INSERT INTO avisos_correo (mensaje_id, destino, creado) VALUES (?, 'tfin', ?) ON CONFLICT DO NOTHING");
        foreach ($destinos as $mid => $lista) {
            if (!array_filter($lista, fn ($d) => !isset($avisados[$mid][$d]))) {
                $fin->execute([$mid, $ahora->format('c')]);
            }
        }
        return $r;
    }

    /** Mensaje con la lista de chats sin contestar (uno por chat, con su último mensaje). */
    public static function resumen(array $mensajes, string $idioma, DateTimeImmutable $ahora): array
    {
        $t = fn (string $s) => tukan_tr($s, $idioma);
        $chats = [];
        foreach ($mensajes as $m) {
            $cid = (int) $m['contacto']['id'];
            $chats[$cid] ??= ['contacto' => $m['contacto'], 'linea' => (string) $m['l_nombre'], 'primero' => $m['creado'], 'ultimo' => '', 'cuantos' => 0];
            $chats[$cid]['cuantos']++;
            $chats[$cid]['ultimo'] = $m['tipo'] === 'texto' && trim((string) $m['texto']) !== '' ? (string) $m['texto'] : $t('(foto, audio u otro archivo)');
        }
        $n = count($chats);
        $html = '⏰ <b>' . self::h($n === 1 ? $t('Un cliente te escribió y nadie ha contestado') : $t('Tienes ' . $n . ' chats sin contestar')) . '</b>' . "\n";
        $botones = [];
        foreach ($chats as $cid => $c) {
            $hace = max(1, (int) floor(($ahora->getTimestamp() - (new DateTimeImmutable($c['primero']))->getTimestamp()) / 60));
            $haceTxt = $hace < 60 ? $t('hace ' . $hace . ' min') : $t('hace ' . intdiv($hace, 60) . ' h');
            $nombre = Contactos::nombre($c['contacto']);
            $html .= "\n" . '💬 <b>' . self::h($nombre) . '</b>' . ($c['cuantos'] > 1 ? ' (' . self::h($t($c['cuantos'] . ' mensajes')) . ')' : '')
                . ' · ' . self::h($c['linea']) . ' · ' . self::h($haceTxt) . "\n" . '«' . self::h(mb_strimwidth(preg_replace('/\s+/u', ' ', $c['ultimo']), 0, 140, '…')) . '»' . "\n";
            if (count($botones) < 8) {
                $botones[] = [['text' => mb_strimwidth($t('Abrir el chat') . ': ' . $nombre, 0, 60, '…'), 'url' => self::base() . '/?r=chat&c=' . $cid]];
            }
        }
        if (mb_strlen($html) > 3900) {
            $html = mb_substr($html, 0, 3800);
            $html = mb_substr($html, 0, (int) mb_strrpos($html, "\n💬")) . "\n…";
        }
        return ['text' => $html, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true, 'reply_markup' => ['inline_keyboard' => $botones]];
    }

    private static function equipo(PDO $db, int $empresaId): array
    {
        $st = $db->prepare('SELECT id, nombre, idioma, rol, recibe_chats, tg_chat_id, tg_modo FROM usuarios WHERE empresa_id = ? AND activo ORDER BY id');
        $st->execute([$empresaId]);
        $r = [];
        foreach ($st->fetchAll() as $u) {
            $r[(int) $u['id']] = $u;
        }
        return $r;
    }

    /** A quién le toca ese chat (igual que el respaldo por correo). */
    private static function quienes(array $equipo, array $contacto): array
    {
        $a = (int) ($contacto['asignado_a'] ?? 0);
        if ($a > 0 && isset($equipo[$a])) {
            return [$equipo[$a]];
        }
        $r = array_values(array_filter($equipo, fn ($u) => !empty($u['recibe_chats'])));
        return $r ?: array_values(array_filter($equipo, fn ($u) => $u['rol'] === 'admin'));
    }

    /**
     * Para deploy/configurar_telegram.sh: revisa el token con getMe y registra el webhook. Devuelve el nombre del bot.
     * @throws \RuntimeException
     */
    public static function conectarBot(string $token, string $secreto, string $url): string
    {
        if (!preg_match('/^\d{5,}:[A-Za-z0-9_-]{30,}$/', $token)) {
            throw new \RuntimeException('Ese token no parece de un bot de Telegram (se ve como 123456789:ABC...).');
        }
        if (!preg_match('/^[A-Za-z0-9_-]{16,256}$/', $secreto)) {
            throw new \RuntimeException('Falta el secreto del webhook.');
        }
        $yo = self::api('getMe', [], 10, $token);
        $nombre = (string) ($yo['json']['result']['username'] ?? '');
        if ($yo['codigo'] !== 200 || $nombre === '' || empty($yo['json']['result']['is_bot'])) {
            throw new \RuntimeException('Telegram no aceptó ese token (respuesta ' . $yo['codigo'] . '). Revisa que lo copiaste completo de @BotFather.');
        }
        $w = self::api('setWebhook', ['url' => rtrim($url, '/') . '/telegram.php', 'secret_token' => $secreto, 'allowed_updates' => ['message'],
                                      'drop_pending_updates' => true], 10, $token);
        if ($w['codigo'] !== 200 || empty($w['json']['ok'])) {
            throw new \RuntimeException('Telegram no aceptó la dirección del webhook (' . (string) ($w['json']['description'] ?? $w['codigo']) . ').');
        }
        self::api('setMyCommands', ['commands' => [['command' => 'parar', 'description' => 'Dejar de recibir avisos de WhatsApp Tukán']]], 10, $token);
        return $nombre;
    }
}
