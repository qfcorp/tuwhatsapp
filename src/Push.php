<?php
declare(strict_types=1);

namespace TuWhatsApp;

use PDO;
use RuntimeException;

/**
 * App en el celular: avisos de mensajes nuevos aunque el navegador esté cerrado (Web Push estándar, sin servicios pagados).
 *  - Las claves VAPID del servidor se crean solas la primera vez (ajustes vapid_publica y vapid_privada, esta cifrada).
 *  - Cada navegador que activa los avisos guarda su suscripción (endpoint + claves p256dh y auth).
 *  - El aviso va cifrado de punta a punta (RFC 8291, aes128gcm) y firmado con VAPID (RFC 8292, ES256).
 * Solo se mandan avisos a servidores de push conocidos (Google, Mozilla, Apple, Microsoft); TUWA_PUSH_PRUEBA agrega uno para pruebas.
 */
final class Push
{
    private const PREFIJO_SPKI = '3059301306072a8648ce3d020106082a8648ce3d030107034200';
    private const HOSTS = ['fcm.googleapis.com', 'android.googleapis.com', 'updates.push.services.mozilla.com', 'push.services.mozilla.com',
                           'web.push.apple.com', 'notify.windows.com'];

    public static function b64u(string $b): string
    {
        return rtrim(strtr(base64_encode($b), '+/', '-_'), '=');
    }

    public static function deB64u(string $t): string
    {
        $b = base64_decode(strtr($t, '-_', '+/') . str_repeat('=', (4 - strlen($t) % 4) % 4), true);
        return $b === false ? '' : $b;
    }

    /** Clave pública VAPID (base64url, 65 bytes) para que el navegador se suscriba. La crea si no existe. */
    public static function clavePublica(PDO $db): string
    {
        return self::claves($db)[0];
    }

    /** @return array{0:string, 1:string} pública base64url y privada PEM */
    private static function claves(PDO $db): array
    {
        $st = $db->query("SELECT clave, valor FROM ajustes WHERE clave IN ('vapid_publica', 'vapid_privada')");
        $a = $st->fetchAll(PDO::FETCH_KEY_PAIR);
        if (!empty($a['vapid_publica']) && !empty($a['vapid_privada'])) {
            return [(string) $a['vapid_publica'], Cifrado::descifrar((string) $a['vapid_privada'])];
        }
        $k = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if ($k === false) {
            throw new RuntimeException('No se pudo crear la clave de los avisos (falta OpenSSL).');
        }
        openssl_pkey_export($k, $pem);
        $pub = self::publicaCruda($k);
        $db->prepare("INSERT INTO ajustes (clave, valor) VALUES ('vapid_publica', ?), ('vapid_privada', ?) ON CONFLICT (clave) DO NOTHING")
           ->execute([self::b64u($pub), Cifrado::cifrar($pem)]);
        return self::claves($db);   // si otro proceso las creó al mismo tiempo, gana la que quedó guardada
    }

    /** Punto público sin comprimir (0x04 || X || Y, 65 bytes) de una clave EC. */
    public static function publicaCruda(\OpenSSLAsymmetricKey $k): string
    {
        $d = openssl_pkey_get_details($k);
        return "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
    }

    /** Clave pública OpenSSL a partir de los 65 bytes. */
    public static function publicaDeCruda(string $cruda): \OpenSSLAsymmetricKey
    {
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode(hex2bin(self::PREFIJO_SPKI) . $cruda), 64, "\n") . "-----END PUBLIC KEY-----\n";
        $k = openssl_pkey_get_public($pem);
        if ($k === false) {
            throw new RuntimeException('Clave del navegador no válida.');
        }
        return $k;
    }

    public static function endpointValido(string $url): bool
    {
        $p = parse_url($url);
        if (!is_array($p) || empty($p['host'])) {
            return false;
        }
        $prueba = (string) getenv('TUWA_PUSH_PRUEBA');
        if ($prueba !== '' && str_starts_with($url, $prueba)) {
            return true;
        }
        if (($p['scheme'] ?? '') !== 'https') {
            return false;
        }
        foreach (self::HOSTS as $h) {
            if ($p['host'] === $h || str_ends_with($p['host'], '.' . $h)) {
                return true;
            }
        }
        return false;
    }

    public static function suscribir(PDO $db, int $usuarioId, array $s): void
    {
        $endpoint = (string) ($s['endpoint'] ?? '');
        $p256 = self::deB64u((string) ($s['keys']['p256dh'] ?? ''));
        $auth = self::deB64u((string) ($s['keys']['auth'] ?? ''));
        if (!self::endpointValido($endpoint) || strlen($endpoint) > 1000) {
            throw new RuntimeException('Ese navegador no es compatible con los avisos.');
        }
        if (strlen($p256) !== 65 || $p256[0] !== "\x04" || strlen($auth) < 16) {
            throw new RuntimeException('Claves del navegador no válidas.');
        }
        self::publicaDeCruda($p256);   // que sea un punto válido de la curva
        $db->prepare('INSERT INTO push_suscripciones (usuario_id, endpoint, p256dh, auth) VALUES (?, ?, ?, ?)
                      ON CONFLICT (endpoint) DO UPDATE SET usuario_id = EXCLUDED.usuario_id, p256dh = EXCLUDED.p256dh, auth = EXCLUDED.auth, errores = 0')
           ->execute([$usuarioId, $endpoint, self::b64u($p256), self::b64u($auth)]);
    }

    public static function quitar(PDO $db, int $usuarioId, string $endpoint): void
    {
        $db->prepare('DELETE FROM push_suscripciones WHERE usuario_id = ? AND endpoint = ?')->execute([$usuarioId, $endpoint]);
    }

    public static function cuantas(PDO $db, int $usuarioId): int
    {
        $st = $db->prepare('SELECT count(*) FROM push_suscripciones WHERE usuario_id = ?');
        $st->execute([$usuarioId]);
        return (int) $st->fetchColumn();
    }

    /**
     * Cifra el aviso para un navegador (RFC 8291 / RFC 8188, un solo registro).
     * $privadaEfimera y $sal solo se pasan en las pruebas.
     */
    public static function cifrar(string $mensaje, string $uaPublica, string $authSecreto, ?\OpenSSLAsymmetricKey $privadaEfimera = null, ?string $sal = null): string
    {
        $eph = $privadaEfimera ?? openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $asPublica = self::publicaCruda($eph);
        $secreto = openssl_pkey_derive(self::publicaDeCruda($uaPublica), $eph, 32);
        if ($secreto === false) {
            throw new RuntimeException('No se pudo cifrar el aviso.');
        }
        $sal ??= random_bytes(16);
        $prkClave = hash_hmac('sha256', $secreto, $authSecreto, true);
        $ikm = hash_hmac('sha256', "WebPush: info\0" . $uaPublica . $asPublica . "\x01", $prkClave, true);
        $prk = hash_hmac('sha256', $ikm, $sal, true);
        $cek = substr(hash_hmac('sha256', "Content-Encoding: aes128gcm\0\x01", $prk, true), 0, 16);
        $nonce = substr(hash_hmac('sha256', "Content-Encoding: nonce\0\x01", $prk, true), 0, 12);
        $tag = '';
        $cifrado = openssl_encrypt($mensaje . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        return $sal . pack('N', 4096) . chr(65) . $asPublica . $cifrado . $tag;
    }

    /** Firma VAPID (JWT ES256) para el servidor de push del endpoint. */
    public static function vapid(string $endpoint, string $publica, string $privadaPem, int $ahora): string
    {
        $p = parse_url($endpoint);
        $aud = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
        $entrada = self::b64u('{"typ":"JWT","alg":"ES256"}') . '.' . self::b64u(json_encode(['aud' => $aud, 'exp' => $ahora + 12 * 3600, 'sub' => 'mailto:info@tukansoftware.com']));
        openssl_sign($entrada, $der, openssl_pkey_get_private($privadaPem), OPENSSL_ALGO_SHA256);
        return 'vapid t=' . $entrada . '.' . self::b64u(self::derACrudo($der)) . ', k=' . $publica;
    }

    /** Firma ECDSA DER -> r||s de 64 bytes (lo que pide JWT). */
    public static function derACrudo(string $der): string
    {
        $pos = 2;
        $leer = function () use ($der, &$pos): string {
            $pos++;                       // 0x02
            $largo = ord($der[$pos++]);
            $v = substr($der, $pos, $largo);
            $pos += $largo;
            return str_pad(ltrim($v, "\0"), 32, "\0", STR_PAD_LEFT);
        };
        return $leer() . $leer();
    }

    /**
     * Manda un aviso a los navegadores de esas personas. Devuelve cuántos se entregaron.
     * @param list<int> $usuarios
     * @param array{titulo:string, texto:string, url:string, tag?:string} $aviso
     */
    public static function enviar(PDO $db, array $usuarios, array $aviso): int
    {
        $usuarios = array_values(array_unique(array_map('intval', $usuarios)));
        if (!$usuarios) {
            return 0;
        }
        $st = $db->prepare('SELECT * FROM push_suscripciones WHERE usuario_id IN (' . implode(',', array_fill(0, count($usuarios), '?')) . ') AND errores < 5 LIMIT 50');
        $st->execute($usuarios);
        $subs = $st->fetchAll();
        if (!$subs) {
            return 0;
        }
        [$publica, $privada] = self::claves($db);
        $cuerpo = json_encode(['titulo' => mb_substr($aviso['titulo'], 0, 80), 'texto' => mb_substr($aviso['texto'], 0, 160), 'url' => $aviso['url'],
                               'tag' => $aviso['tag'] ?? 'tuwa'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $ok = 0;
        foreach ($subs as $s) {
            try {
                $datos = self::cifrar($cuerpo, self::deB64u($s['p256dh']), self::deB64u($s['auth']));
                $ch = curl_init($s['endpoint']);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 5, CURLOPT_POSTFIELDS => $datos,
                    CURLOPT_HTTPHEADER => ['Authorization: ' . self::vapid($s['endpoint'], $publica, $privada, time()), 'Content-Encoding: aes128gcm',
                                           'Content-Type: application/octet-stream', 'TTL: 3600', 'Urgency: high'],
                ]);
                curl_exec($ch);
                $codigo = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                curl_close($ch);
            } catch (\Throwable $e) {
                $codigo = 0;
                error_log('tuwhatsapp push: ' . $e->getMessage());
            }
            if ($codigo >= 200 && $codigo < 300) {
                $ok++;
                $db->prepare('UPDATE push_suscripciones SET ultimo_ok = now(), errores = 0 WHERE id = ?')->execute([$s['id']]);
            } elseif ($codigo === 404 || $codigo === 410) {   // el navegador ya no la usa
                $db->prepare('DELETE FROM push_suscripciones WHERE id = ?')->execute([$s['id']]);
            } else {
                $db->prepare('UPDATE push_suscripciones SET errores = errores + 1 WHERE id = ?')->execute([$s['id']]);
            }
        }
        return $ok;
    }

    /**
     * Aviso de mensaje nuevo: a quien atiende la conversación; si no tiene a nadie, a todo el equipo que recibe chats.
     * No avisa de contactos personales.
     */
    public static function mensajeNuevo(PDO $db, array $linea, array $contacto, string $texto, int $mensajeId): void
    {
        if (($contacto['tipo'] ?? '') === 'personal') {
            return;
        }
        if (!empty($contacto['asignado_a'])) {
            $usuarios = [(int) $contacto['asignado_a']];
        } else {
            $st = $db->prepare('SELECT id FROM usuarios WHERE empresa_id = ? AND activo AND recibe_chats');
            $st->execute([(int) $linea['empresa_id']]);
            $usuarios = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        }
        self::enviar($db, $usuarios, ['titulo' => Contactos::nombre($contacto), 'texto' => mb_strimwidth($texto, 0, 120, '…'),
                                      'url' => '/?r=chat&c=' . (int) $contacto['id'], 'tag' => 'tuwa-c' . (int) $contacto['id'] . '-' . $mensajeId]);
    }
}
