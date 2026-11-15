<?php
declare(strict_types=1);

namespace TuWhatsApp;

use DateTimeImmutable;
use PDO;
use RuntimeException;

/**
 * (14 nov 2026) Avisos NATIVOS en la app de Android «Tukán Mensajes» con Firebase Cloud Messaging (FCM).
 * El Web Push dentro de la app (TWA) depende de Chrome y en muchos celulares nunca se registra; con FCM el aviso lo muestra la
 * propia app, como WhatsApp, aunque esté cerrada.
 *
 * Protocolo con la app (qfcorp/tukan-apps):
 *  1. La app genera una vez: dispositivo = 32 hex y secreto = 64 hex (los guarda en el celular).
 *  2. POST /fcm.php  accion=registrar, dispositivo, secreto, token, modelo, version  → {"ok":true}. Un dispositivo ya registrado
 *     solo se cambia con su mismo secreto (en la base va el sha256). accion=quitar (dispositivo, secreto) lo borra.
 *  3. La app abre la página con «&disp=<dispositivo>». El servidor guarda la cookie tuwa_disp y, con la sesión abierta, vincula ese
 *     celular a la persona. La cookie va FIRMADA solo si la página la abrió la app (Referer android-app://com.tukansoftware.tuwhatsapp);
 *     si no (por ejemplo un enlace con ?disp= de otra persona), la cookie queda «pendiente» y hay que confirmarlo con un botón:
 *     así nadie puede mandarse a su celular los mensajes de otra cuenta con un enlace.
 *  4. Los avisos van por FCM HTTP v1 (solo datos: titulo, texto, url, tag) con la cuenta de servicio de Firebase, guardada cifrada.
 */
final class Fcm
{
    public const COOKIE = 'tuwa_disp';
    public const PAQUETE = 'com.tukansoftware.tuwhatsapp';
    public const DIAS_COOKIE = 400;
    public const REGISTROS_POR_HORA = 20;   // registros NUEVOS por IP por hora
    public const SEG_CONECTAR = 1;
    public const SEG_TOTAL = 3;
    public const TTL = '86400s';           // si el celular está apagado o sin internet, el aviso espera hasta 24 horas
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    // ---------------------------------------------------------------- registro del celular (public/fcm.php)

    public static function dispositivoValido(string $d): bool
    {
        return (bool) preg_match('/^[0-9a-f]{32}$/', $d);
    }

    public static function secretoValido(string $s): bool
    {
        return (bool) preg_match('/^[0-9a-f]{64}$/', $s);
    }

    private static function tokenValido(string $t): bool
    {
        return $t !== '' && strlen($t) <= 4096 && (bool) preg_match('/^[A-Za-z0-9_\-:.]+$/', $t);
    }

    private static function textoCorto(string $t, int $max): string
    {
        $t = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $t));
        return mb_substr($t, 0, $max);
    }

    /**
     * Registra o actualiza el token de un celular. Devuelve [código HTTP, respuesta JSON].
     * @param array<string, mixed> $p datos del formulario
     * @return array{0:int, 1:array}
     */
    public static function registrar(PDO $db, array $p, string $ip, ?DateTimeImmutable $ahora = null): array
    {
        $ahora ??= new DateTimeImmutable();
        $disp = (string) ($p['dispositivo'] ?? '');
        $secreto = (string) ($p['secreto'] ?? '');
        $token = (string) ($p['token'] ?? '');
        $modeloCrudo = (string) ($p['modelo'] ?? '');
        $versionCruda = (string) ($p['version'] ?? '');
        if (!self::dispositivoValido($disp) || !self::secretoValido($secreto)) {
            return [400, ['ok' => false, 'error' => 'dispositivo o secreto con forma incorrecta']];
        }
        if (!self::tokenValido($token)) {
            return [400, ['ok' => false, 'error' => 'token con forma incorrecta']];
        }
        if (mb_strlen($modeloCrudo) > 80 || mb_strlen($versionCruda) > 40) {
            return [400, ['ok' => false, 'error' => 'modelo o version demasiado largos']];
        }
        $modelo = self::textoCorto($modeloCrudo, 80);
        $version = self::textoCorto($versionCruda, 40);
        $hash = hash('sha256', $secreto);

        $st = $db->prepare('SELECT id, secreto_hash FROM fcm_dispositivos WHERE dispositivo = ?');
        $st->execute([$disp]);
        $fila = $st->fetch();
        if (!$fila) {
            $c = $db->prepare('SELECT count(*) FROM fcm_registros_ip WHERE ip = ? AND creado > ?');
            $c->execute([$ip, $ahora->modify('-1 hour')->format('c')]);
            if ((int) $c->fetchColumn() >= self::REGISTROS_POR_HORA) {
                return [429, ['ok' => false, 'error' => 'demasiados registros desde esta conexion; intenta en una hora']];
            }
            $ins = $db->prepare("INSERT INTO fcm_dispositivos (dispositivo, secreto_hash, token, modelo, version, creado, actualizado)
                                 VALUES (?, ?, ?, ?, ?, ?, ?) ON CONFLICT (dispositivo) DO NOTHING RETURNING id");
            $ins->execute([$disp, $hash, $token, $modelo, $version, $ahora->format('c'), $ahora->format('c')]);
            if ($ins->fetchColumn() !== false) {
                $db->prepare('INSERT INTO fcm_registros_ip (ip, creado) VALUES (?, ?)')->execute([$ip, $ahora->format('c')]);
                return [200, ['ok' => true]];
            }
            // Otro pedido lo creó en este mismo instante: se sigue como si ya existiera
            $st->execute([$disp]);
            $fila = $st->fetch();
            if (!$fila) {
                return [500, ['ok' => false, 'error' => 'no se pudo registrar']];
            }
        }
        if (!hash_equals((string) $fila['secreto_hash'], $hash)) {
            return [403, ['ok' => false, 'error' => 'secreto incorrecto']];
        }
        $db->prepare("UPDATE fcm_dispositivos SET token = ?, modelo = ?, version = ?, actualizado = ?, errores = 0, error_texto = '' WHERE id = ?")
           ->execute([$token, $modelo, $version, $ahora->format('c'), (int) $fila['id']]);
        return [200, ['ok' => true]];
    }

    /** accion=quitar: borra el celular (con su secreto). @return array{0:int, 1:array} */
    public static function quitarDesdeApp(PDO $db, array $p): array
    {
        $disp = (string) ($p['dispositivo'] ?? '');
        $secreto = (string) ($p['secreto'] ?? '');
        if (!self::dispositivoValido($disp) || !self::secretoValido($secreto)) {
            return [400, ['ok' => false, 'error' => 'dispositivo o secreto con forma incorrecta']];
        }
        $st = $db->prepare('SELECT id, secreto_hash FROM fcm_dispositivos WHERE dispositivo = ?');
        $st->execute([$disp]);
        $fila = $st->fetch();
        if (!$fila) {
            return [200, ['ok' => true]];
        }
        if (!hash_equals((string) $fila['secreto_hash'], hash('sha256', $secreto))) {
            return [403, ['ok' => false, 'error' => 'secreto incorrecto']];
        }
        $db->prepare('DELETE FROM fcm_dispositivos WHERE id = ?')->execute([(int) $fila['id']]);
        return [200, ['ok' => true]];
    }

    /** Limpieza diaria: registros por IP de más de 1 día. */
    public static function limpiar(PDO $db, ?DateTimeImmutable $ahora = null): int
    {
        $ahora ??= new DateTimeImmutable();
        $st = $db->prepare('DELETE FROM fcm_registros_ip WHERE creado < ?');
        $st->execute([$ahora->modify('-1 day')->format('c')]);
        return $st->rowCount();
    }

    // ---------------------------------------------------------------- cookie tuwa_disp y vínculo con la persona

    private static function firma(string $disp): string
    {
        return substr(hash_hmac('sha256', 'tuwa-disp:' . $disp, (string) (getenv('TUWA_LLAVE') ?: 'tuwa')), 0, 32);
    }

    /** @return array{disp:string, firmada:bool}|null */
    public static function leerCookie(?string $valor = null): ?array
    {
        $valor ??= is_string($_COOKIE[self::COOKIE] ?? null) ? (string) $_COOKIE[self::COOKIE] : '';
        if (preg_match('/^([0-9a-f]{32})(?:\.([0-9a-f]{32}))?$/', $valor, $m)) {
            $firmada = isset($m[2]) && hash_equals(self::firma($m[1]), $m[2]);
            return ['disp' => $m[1], 'firmada' => $firmada];
        }
        return null;
    }

    /** Dispositivo de la cookie de este navegador (o null). */
    public static function dispositivoCookie(): ?string
    {
        return self::leerCookie()['disp'] ?? null;
    }

    /** ¿Esta página la abrió la app de Android? (Chrome manda Referer android-app://<paquete> al abrir una TWA). */
    public static function abiertoPorLaApp(string $referer): bool
    {
        return str_starts_with($referer, 'android-app://' . self::PAQUETE);
    }

    private static function ponerCookie(string $valor): void
    {
        if (!headers_sent()) {
            $https = (($_SERVER['HTTPS'] ?? '') === 'on') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
            setcookie(self::COOKIE, $valor, ['expires' => time() + 60 * 60 * 24 * self::DIAS_COOKIE, 'path' => '/', 'secure' => $https,
                                             'httponly' => true, 'samesite' => 'Lax']);
        }
        $_COOKIE[self::COOKIE] = $valor;
    }

    /**
     * La app abre la página con ?disp=<dispositivo>: se guarda en la cookie (firmada si la abrió la app; si no, pendiente de confirmar).
     * Una cookie pendiente nunca reemplaza a una firmada de otro celular.
     */
    public static function recordarDeUrl(?string $disp, string $referer): void
    {
        if ($disp === null || !self::dispositivoValido($disp)) {
            return;
        }
        $actual = self::leerCookie();
        if (self::abiertoPorLaApp($referer)) {
            if (!($actual && $actual['disp'] === $disp && $actual['firmada'])) {
                self::ponerCookie($disp . '.' . self::firma($disp));
            }
            return;
        }
        if ($actual && ($actual['firmada'] || $actual['disp'] === $disp)) {
            return;   // ya está (o hay otro celular firmado): no se toca
        }
        self::ponerCookie($disp);
    }

    /**
     * En cada página con sesión: si la cookie es de un celular registrado, se vincula (si está firmada). Devuelve el estado de ESTE celular:
     * 'sin_cookie' (no viene de la app con avisos nativos), 'sin_registro' (la app todavía no mandó su token), 'pendiente' (hay que
     * confirmarlo con el botón), 'apagado' (la persona dijo que no o lo quitó) o 'vinculado'.
     */
    public static function alEntrar(PDO $db, int $usuarioId): string
    {
        $c = self::leerCookie();
        if (!$c) {
            return 'sin_cookie';
        }
        $st = $db->prepare('SELECT id, usuario_id, rechazado_por, token FROM fcm_dispositivos WHERE dispositivo = ?');
        $st->execute([$c['disp']]);
        $f = $st->fetch();
        if (!$f || (string) $f['token'] === '') {
            return 'sin_registro';
        }
        if ((int) $f['usuario_id'] === $usuarioId) {
            return 'vinculado';
        }
        if ((int) $f['rechazado_por'] === $usuarioId) {
            return 'apagado';
        }
        if (!$c['firmada']) {
            return 'pendiente';
        }
        $db->prepare('UPDATE fcm_dispositivos SET usuario_id = ?, vinculado = now(), rechazado_por = NULL WHERE id = ?')
           ->execute([$usuarioId, (int) $f['id']]);
        return 'vinculado';
    }

    /** «Sí, recibir los avisos en este celular» (botón, con CSRF). Deja la cookie firmada. */
    public static function confirmar(PDO $db, int $usuarioId): bool
    {
        $c = self::leerCookie();
        if (!$c) {
            return false;
        }
        $st = $db->prepare("UPDATE fcm_dispositivos SET usuario_id = ?, vinculado = now(), rechazado_por = NULL WHERE dispositivo = ? AND token <> ''");
        $st->execute([$usuarioId, $c['disp']]);
        if ($st->rowCount() === 0) {
            return false;
        }
        if (!$c['firmada']) {
            self::ponerCookie($c['disp'] . '.' . self::firma($c['disp']));
        }
        return true;
    }

    /** «No» / «Apagar en este celular»: no se vincula solo hasta que la persona lo vuelva a activar. */
    public static function rechazar(PDO $db, int $usuarioId): void
    {
        $c = self::leerCookie();
        if (!$c) {
            return;
        }
        $db->prepare('UPDATE fcm_dispositivos SET usuario_id = CASE WHEN usuario_id = ? THEN NULL ELSE usuario_id END, rechazado_por = ? WHERE dispositivo = ?')
           ->execute([$usuarioId, $usuarioId, $c['disp']]);
    }

    /** Salir desde este celular: deja de recibir los avisos de esa persona. */
    public static function alSalir(PDO $db, int $usuarioId): void
    {
        $c = self::leerCookie();
        if ($c) {
            $db->prepare('UPDATE fcm_dispositivos SET usuario_id = NULL WHERE dispositivo = ? AND usuario_id = ?')->execute([$c['disp'], $usuarioId]);
        }
    }

    /**
     * Contraseña cambiada, usuario desactivado o «cerrar en todos los demás»: sus celulares dejan de recibir avisos ($excepto: el de
     * este navegador, que se vuelve a vincular solo en su próximo clic si sigue con la sesión abierta). Devuelve cuántos desvinculó.
     */
    public static function desvincularUsuario(PDO $db, int $usuarioId, ?string $excepto = null): int
    {
        $st = $db->prepare('UPDATE fcm_dispositivos SET usuario_id = NULL WHERE usuario_id = ? AND dispositivo <> ?');
        $st->execute([$usuarioId, $excepto ?? '']);
        return $st->rowCount();
    }

    /** Quitar un celular desde App en el celular (solo los propios). */
    public static function quitarId(PDO $db, int $usuarioId, int $id): bool
    {
        $st = $db->prepare('UPDATE fcm_dispositivos SET usuario_id = NULL, rechazado_por = ? WHERE id = ? AND usuario_id = ?');
        $st->execute([$usuarioId, $id, $usuarioId]);
        return $st->rowCount() > 0;
    }

    /** Celulares con la app que reciben los avisos de esa persona. @return list<array> */
    public static function dispositivos(PDO $db, int $usuarioId): array
    {
        $st = $db->prepare("SELECT id, dispositivo, modelo, version, vinculado, ultimo_ok, errores, ultimo_error, error_texto
                            FROM fcm_dispositivos WHERE usuario_id = ? AND token <> '' ORDER BY vinculado DESC NULLS LAST, id DESC");
        $st->execute([$usuarioId]);
        return array_map(fn ($f) => $f + ['errores' => (int) $f['errores']], $st->fetchAll());
    }

    /** Condición de "se le puede mandar" (las fallas temporales se reintentan al día siguiente). */
    private const SQL_USABLE = "token <> '' AND (errores < 5 OR ultimo_error IS NULL OR ultimo_error < now() - interval '1 day')";

    /** ¿Cuántos celulares con la app le funcionan a esa persona? */
    public static function funcionando(PDO $db, int $usuarioId): int
    {
        $st = $db->prepare("SELECT count(*) FROM fcm_dispositivos WHERE usuario_id = ? AND token <> '' AND errores < 5");
        $st->execute([$usuarioId]);
        return (int) $st->fetchColumn();
    }

    /** ¿A alguna de esas personas le llegan avisos nativos? */
    public static function algunoFunciona(PDO $db, array $usuarios): bool
    {
        $usuarios = array_values(array_map('intval', $usuarios));
        if (!$usuarios) {
            return false;
        }
        $st = $db->prepare("SELECT 1 FROM fcm_dispositivos WHERE usuario_id IN (" . implode(',', array_fill(0, count($usuarios), '?')) . ") AND token <> '' AND errores < 5 LIMIT 1");
        $st->execute($usuarios);
        return (bool) $st->fetchColumn();
    }

    // ---------------------------------------------------------------- clave de Firebase (cuenta de servicio), guardada cifrada

    /**
     * Revisa el archivo .json de la cuenta de servicio y devuelve solo lo que hace falta.
     * @return array{project_id:string, client_email:string, private_key:string, private_key_id:string, token_uri:string}
     */
    public static function validarClave(string $json): array
    {
        if (strlen($json) > 20000) {
            throw new RuntimeException('Ese archivo es demasiado grande para ser una clave de Firebase.');
        }
        $d = json_decode($json, true);
        if (!is_array($d)) {
            throw new RuntimeException('Ese archivo no es una clave de Firebase (no es un archivo .json válido).');
        }
        if (($d['type'] ?? '') !== 'service_account') {
            throw new RuntimeException('Ese archivo no es una clave de cuenta de servicio de Firebase (falta "service_account").');
        }
        foreach (['project_id', 'client_email', 'private_key', 'token_uri'] as $k) {
            if (!is_string($d[$k] ?? null) || $d[$k] === '') {
                throw new RuntimeException('A la clave de Firebase le falta el dato ' . $k . '.');
            }
        }
        if (!preg_match('/^[a-z0-9][a-z0-9\-]{3,40}$/', $d['project_id'])) {
            throw new RuntimeException('El project_id de la clave de Firebase tiene una forma rara.');
        }
        if (!filter_var($d['client_email'], FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('El client_email de la clave de Firebase no es un correo.');
        }
        if (!preg_match('#^https://#', $d['token_uri']) && !preg_match('#^http://(127\.0\.0\.1|localhost)(:\d+)?/#', $d['token_uri'])) {
            throw new RuntimeException('El token_uri de la clave de Firebase debe empezar con https://.');
        }
        if (openssl_pkey_get_private($d['private_key']) === false) {
            throw new RuntimeException('La llave privada de la clave de Firebase no se puede leer.');
        }
        return ['project_id' => $d['project_id'], 'client_email' => $d['client_email'], 'private_key' => $d['private_key'],
                'private_key_id' => (string) ($d['private_key_id'] ?? ''), 'token_uri' => $d['token_uri']];
    }

    public static function guardarClave(PDO $db, string $json): array
    {
        $c = self::validarClave($json);
        $db->prepare('INSERT INTO ajustes (clave, valor) VALUES (?, ?) ON CONFLICT (clave) DO UPDATE SET valor = EXCLUDED.valor')
           ->execute(['fcm_clave', Cifrado::cifrar(json_encode($c, JSON_UNESCAPED_SLASHES))]);
        $db->prepare("DELETE FROM ajustes WHERE clave = 'fcm_acceso'")->execute();
        return ['project_id' => $c['project_id'], 'client_email' => $c['client_email']];
    }

    public static function quitarClave(PDO $db): void
    {
        $db->prepare("DELETE FROM ajustes WHERE clave IN ('fcm_clave', 'fcm_acceso')")->execute();
    }

    /** La clave guardada (descifrada) o null. */
    public static function clave(PDO $db): ?array
    {
        $st = $db->prepare("SELECT valor FROM ajustes WHERE clave = 'fcm_clave'");
        $st->execute();
        $v = $st->fetchColumn();
        if ($v === false) {
            return null;
        }
        try {
            $c = json_decode(Cifrado::descifrar((string) $v), true);
        } catch (\Throwable $e) {
            error_log('tuwhatsapp fcm: ' . $e->getMessage());
            return null;
        }
        return is_array($c) && !empty($c['private_key']) ? $c : null;
    }

    public static function configurado(PDO $db): bool
    {
        return self::clave($db) !== null;
    }

    /** Lo que se puede mostrar en pantalla (nunca la llave). */
    public static function estadoClave(PDO $db): array
    {
        $c = self::clave($db);
        $n = (int) $db->query("SELECT count(*) FROM fcm_dispositivos WHERE usuario_id IS NOT NULL AND token <> ''")->fetchColumn();
        return ['configurado' => $c !== null, 'project_id' => $c['project_id'] ?? '', 'client_email' => $c['client_email'] ?? '', 'celulares' => $n];
    }

    // ---------------------------------------------------------------- envío (FCM HTTP v1)

    private static function b64u(string $b): string
    {
        return rtrim(strtr(base64_encode($b), '+/', '-_'), '=');
    }

    /** JWT firmado con la cuenta de servicio (para pedir el token de acceso de Google). */
    public static function jwt(array $clave, int $ahora): string
    {
        $cab = self::b64u(json_encode(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => (string) ($clave['private_key_id'] ?? '')]));
        $datos = self::b64u(json_encode(['iss' => $clave['client_email'], 'scope' => self::SCOPE, 'aud' => self::urlToken($clave),
                                         'iat' => $ahora, 'exp' => $ahora + 3600], JSON_UNESCAPED_SLASHES));
        $firma = '';
        if (!openssl_sign($cab . '.' . $datos, $firma, $clave['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('No se pudo firmar con la clave de Firebase.');
        }
        return $cab . '.' . $datos . '.' . self::b64u($firma);
    }

    private static function urlToken(array $clave): string
    {
        return (string) (getenv('TUWA_FCM_TOKEN_URL') ?: $clave['token_uri']);
    }

    private static function urlEnvio(string $proyecto): string
    {
        return rtrim((string) (getenv('TUWA_FCM_URL') ?: 'https://fcm.googleapis.com'), '/') . '/v1/projects/' . rawurlencode($proyecto) . '/messages:send';
    }

    /** Token de acceso de Google (se guarda cifrado ~50 minutos para no pedirlo en cada aviso). */
    public static function acceso(PDO $db, array $clave, ?int $ahora = null): ?string
    {
        $ahora ??= time();
        $st = $db->prepare("SELECT valor FROM ajustes WHERE clave = 'fcm_acceso'");
        $st->execute();
        $v = $st->fetchColumn();
        if ($v !== false) {
            $c = json_decode(Cifrado::descifrar((string) $v), true);
            if (is_array($c) && ($c['proyecto'] ?? '') === $clave['project_id'] && (int) ($c['vence'] ?? 0) > $ahora + 60 && !empty($c['token'])) {
                return (string) $c['token'];
            }
        }
        $ch = curl_init(self::urlToken($clave));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_CONNECTTIMEOUT => self::SEG_CONECTAR, CURLOPT_TIMEOUT => self::SEG_TOTAL,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_POSTFIELDS => http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => self::jwt($clave, $ahora)]),
        ]);
        $r = curl_exec($ch);
        $codigo = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $j = is_string($r) ? json_decode($r, true) : null;
        if ($codigo !== 200 || !is_array($j) || empty($j['access_token'])) {
            error_log('tuwhatsapp fcm: Google no dio el token de acceso (respuesta ' . $codigo . ')');
            return null;
        }
        $vence = $ahora + max(120, min(3600, (int) ($j['expires_in'] ?? 3600)) - 600);   // ~50 minutos
        $db->prepare('INSERT INTO ajustes (clave, valor) VALUES (?, ?) ON CONFLICT (clave) DO UPDATE SET valor = EXCLUDED.valor')
           ->execute(['fcm_acceso', Cifrado::cifrar(json_encode(['token' => $j['access_token'], 'vence' => $vence, 'proyecto' => $clave['project_id']]))]);
        return (string) $j['access_token'];
    }

    /** El mensaje que va a Google: solo datos (la app arma el aviso con el ícono y el nombre de Tukán Mensajes). */
    public static function mensaje(string $token, array $aviso): array
    {
        $tag = (string) ($aviso['tag'] ?? 'tuwa');
        return ['message' => [
            'token' => $token,
            'data' => ['titulo' => mb_substr((string) $aviso['titulo'], 0, 80), 'texto' => mb_substr((string) $aviso['texto'], 0, 160),
                       'url' => (string) $aviso['url'], 'tag' => $tag],
            'android' => ['priority' => 'HIGH', 'ttl' => self::TTL, 'collapse_key' => mb_substr($tag, 0, 100)],
        ]];
    }

    /** ¿La respuesta de Google dice que ese token ya no sirve? (se borra el celular) */
    public static function tokenMuerto(int $codigo, ?array $json): bool
    {
        $err = (array) ($json['error'] ?? []);
        $estado = (string) ($err['status'] ?? '');
        $codigosFcm = array_map(fn ($d) => (string) ($d['errorCode'] ?? ''), (array) ($err['details'] ?? []));
        if ($codigo === 404 || $estado === 'NOT_FOUND' || in_array('UNREGISTERED', $codigosFcm, true)) {
            return true;
        }
        if ($codigo === 400 && ($estado === 'INVALID_ARGUMENT' || in_array('INVALID_ARGUMENT', $codigosFcm, true))
            && stripos((string) ($err['message'] ?? ''), 'token') !== false) {
            return true;
        }
        return false;
    }

    /**
     * Manda el aviso a los celulares con la app de esas personas (en paralelo, máximo 3 s). Devuelve cuántos se entregaron.
     * Sin clave de Firebase no hace nada.
     * @param list<int> $usuarios
     * @param array{titulo:string, texto:string, url:string, tag?:string} $aviso
     */
    public static function enviar(PDO $db, array $usuarios, array $aviso): int
    {
        $usuarios = array_values(array_unique(array_map('intval', $usuarios)));
        if (!$usuarios) {
            return 0;
        }
        $st = $db->prepare('SELECT id, token FROM fcm_dispositivos WHERE usuario_id IN (' . implode(',', array_fill(0, count($usuarios), '?')) . ') AND ' . self::SQL_USABLE . ' LIMIT 50');
        $st->execute($usuarios);
        $celulares = $st->fetchAll();
        if (!$celulares) {
            return 0;
        }
        $clave = self::clave($db);
        if (!$clave) {
            return 0;
        }
        try {
            $acceso = self::acceso($db, $clave);
        } catch (\Throwable $e) {
            error_log('tuwhatsapp fcm: ' . $e->getMessage());
            $acceso = null;
        }
        if ($acceso === null) {
            return 0;
        }
        $multi = curl_multi_init();
        $manijas = [];
        foreach ($celulares as $c) {
            $ch = curl_init(self::urlEnvio($clave['project_id']));
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_CONNECTTIMEOUT => self::SEG_CONECTAR, CURLOPT_TIMEOUT => self::SEG_TOTAL,
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $acceso, 'Content-Type: application/json; charset=utf-8'],
                CURLOPT_POSTFIELDS => json_encode(self::mensaje((string) $c['token'], $aviso), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
            curl_multi_add_handle($multi, $ch);
            $manijas[(int) $c['id']] = $ch;
        }
        do {
            $s = curl_multi_exec($multi, $activos);
            if ($activos) {
                curl_multi_select($multi, 0.5);
            }
        } while ($activos && $s === CURLM_OK);
        $ok = 0;
        $vencido = false;
        foreach ($manijas as $id => $ch) {
            $cuerpo = curl_multi_getcontent($ch);
            $codigo = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $json = is_string($cuerpo) && $cuerpo !== '' ? (json_decode($cuerpo, true) ?: null) : null;
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
            if ($codigo >= 200 && $codigo < 300) {
                $ok++;
                $db->prepare("UPDATE fcm_dispositivos SET ultimo_ok = now(), errores = 0, ultimo_error = NULL, error_texto = '' WHERE id = ?")->execute([$id]);
            } elseif (self::tokenMuerto($codigo, $json)) {   // la app se desinstaló o el token cambió
                $db->prepare('DELETE FROM fcm_dispositivos WHERE id = ?')->execute([$id]);
            } else {
                if ($codigo === 401) {
                    $vencido = true;
                }
                $texto = $codigo === 0 ? 'sin conexión' : 'respuesta ' . $codigo . (isset($json['error']['status']) ? ' ' . $json['error']['status'] : '');
                $db->prepare('UPDATE fcm_dispositivos SET errores = errores + 1, ultimo_error = now(), error_texto = ? WHERE id = ?')
                   ->execute([mb_substr($texto, 0, 200), $id]);
            }
        }
        curl_multi_close($multi);
        if ($vencido) {   // el token de acceso ya no sirve: el próximo aviso pide otro
            $db->prepare("DELETE FROM ajustes WHERE clave = 'fcm_acceso'")->execute();
        }
        return $ok;
    }

    /** Mensaje nuevo de un cliente (al final del webhook, después de la respuesta automática): mismos destinatarios que el Web Push. */
    public static function mensajeNuevo(PDO $db, array $linea, array $contacto, string $texto, int $mensajeId): int
    {
        if (($contacto['tipo'] ?? '') === 'personal') {
            return 0;
        }
        $usuarios = Push::destinatarios($db, $linea, $contacto);
        return self::enviar($db, $usuarios, Push::avisoMensaje($contacto, $texto, $mensajeId));
    }

    /** Aviso de prueba a los celulares con la app de esa persona. */
    public static function prueba(PDO $db, int $usuarioId): int
    {
        return self::enviar($db, [$usuarioId], ['titulo' => 'Tukán Mensajes', 'texto' => tukan_tr('Así te llegarán los mensajes nuevos.'),
                                                'url' => '/?r=bandeja', 'tag' => 'tuwa-prueba']);
    }
}
