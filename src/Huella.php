<?php
declare(strict_types=1);

namespace TuWhatsApp;

use PDO;
use RuntimeException;

/**
 * Entrar con huella (14 nov 2026): WebAuthn / llaves de acceso (passkeys) sin librerías de afuera.
 *  - Registro con attestation "none": se guarda la llave pública del celular (ES256 o RS256) y su id.
 *  - Entrar: el celular firma el desafío después de pedir la huella (o la cara / el PIN del celular); aquí se revisa la firma.
 *  - El desafío vive en la sesión, sirve una sola vez y vence a los 5 minutos.
 *  - La huella nunca sale del celular: aquí solo llega una firma.
 */
final class Huella
{
    public const VENCE = 300;
    private const PREFIJO_SPKI_EC = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    // ------------------------------------------------------------------ base64url

    public static function b64u(string $b): string
    {
        return rtrim(strtr(base64_encode($b), '+/', '-_'), '=');
    }

    public static function deB64u(string $t): string
    {
        $t = trim($t);
        if ($t === '' || !preg_match('/^[A-Za-z0-9_\-+\/=]+$/', $t)) {
            return '';
        }
        $b = base64_decode(strtr(rtrim($t, '='), '-_', '+/') . str_repeat('=', (4 - strlen(rtrim($t, '=')) % 4) % 4), true);
        return $b === false ? '' : $b;
    }

    // ------------------------------------------------------------------ CBOR (lo justo para WebAuthn)

    /** Lee un valor CBOR desde $pos (y deja $pos después de él). */
    public static function cborLeer(string $b, int &$pos, int $profundidad = 0): mixed
    {
        if ($profundidad > 16 || $pos >= strlen($b)) {
            throw new RuntimeException('Datos de la huella incompletos.');
        }
        $ini = ord($b[$pos++]);
        $tipo = $ini >> 5;
        $info = $ini & 0x1f;
        if ($tipo === 7) {
            return match ($info) {
                20 => false, 21 => true, 22, 23 => null,
                default => throw new RuntimeException('Dato de la huella no soportado.'),
            };
        }
        if ($info < 24) {
            $n = $info;
        } elseif ($info <= 27) {
            $largo = 1 << ($info - 24);
            if ($pos + $largo > strlen($b)) {
                throw new RuntimeException('Datos de la huella incompletos.');
            }
            $n = 0;
            for ($i = 0; $i < $largo; $i++) {
                $n = ($n << 8) | ord($b[$pos + $i]);
            }
            $pos += $largo;
            if ($n < 0) {
                throw new RuntimeException('Dato de la huella demasiado grande.');
            }
        } else {
            throw new RuntimeException('Dato de la huella no soportado.');
        }
        switch ($tipo) {
            case 0:
                return $n;
            case 1:
                return -1 - $n;
            case 2:
            case 3:
                if ($pos + $n > strlen($b)) {
                    throw new RuntimeException('Datos de la huella incompletos.');
                }
                $v = substr($b, $pos, $n);
                $pos += $n;
                return $v;
            case 4:
                if ($n > 1000) {
                    throw new RuntimeException('Datos de la huella no válidos.');
                }
                $a = [];
                for ($i = 0; $i < $n; $i++) {
                    $a[] = self::cborLeer($b, $pos, $profundidad + 1);
                }
                return $a;
            case 5:
                if ($n > 1000) {
                    throw new RuntimeException('Datos de la huella no válidos.');
                }
                $m = [];
                for ($i = 0; $i < $n; $i++) {
                    $k = self::cborLeer($b, $pos, $profundidad + 1);
                    if (!is_int($k) && !is_string($k)) {
                        throw new RuntimeException('Datos de la huella no válidos.');
                    }
                    $m[$k] = self::cborLeer($b, $pos, $profundidad + 1);
                }
                return $m;
        }
        throw new RuntimeException('Dato de la huella no soportado.');
    }

    public static function cbor(string $b): mixed
    {
        $pos = 0;
        return self::cborLeer($b, $pos);
    }

    /**
     * Escribe CBOR (solo lo usan las pruebas para simular un celular). Textos: ['t' => '...']; bytes: string normal.
     */
    public static function cborEscribir(mixed $v): string
    {
        $cabeza = function (int $tipo, int $n): string {
            if ($n < 24) {
                return chr(($tipo << 5) | $n);
            }
            if ($n < 256) {
                return chr(($tipo << 5) | 24) . chr($n);
            }
            if ($n < 65536) {
                return chr(($tipo << 5) | 25) . pack('n', $n);
            }
            return chr(($tipo << 5) | 26) . pack('N', $n);
        };
        if (is_bool($v)) {
            return chr($v ? 0xf5 : 0xf4);
        }
        if ($v === null) {
            return chr(0xf6);
        }
        if (is_int($v)) {
            return $v >= 0 ? $cabeza(0, $v) : $cabeza(1, -1 - $v);
        }
        if (is_string($v)) {
            return $cabeza(2, strlen($v)) . $v;
        }
        if (is_array($v) && count($v) === 1 && array_key_exists('t', $v) && is_string($v['t'])) {
            return $cabeza(3, strlen($v['t'])) . $v['t'];
        }
        if (is_array($v) && array_is_list($v)) {
            $s = $cabeza(4, count($v));
            foreach ($v as $x) {
                $s .= self::cborEscribir($x);
            }
            return $s;
        }
        if (is_array($v)) {
            $s = $cabeza(5, count($v));
            foreach ($v as $k => $x) {
                $s .= is_int($k) ? self::cborEscribir($k) : $cabeza(3, strlen($k)) . $k;
                $s .= self::cborEscribir($x);
            }
            return $s;
        }
        throw new RuntimeException('Tipo no soportado.');
    }

    // ------------------------------------------------------------------ llaves COSE -> PEM

    private static function derLargo(int $n): string
    {
        if ($n < 128) {
            return chr($n);
        }
        $s = ltrim(pack('N', $n), "\0");
        return chr(0x80 | strlen($s)) . $s;
    }

    private static function der(int $etiqueta, string $contenido): string
    {
        return chr($etiqueta) . self::derLargo(strlen($contenido)) . $contenido;
    }

    private static function derEntero(string $bytes): string
    {
        $bytes = ltrim($bytes, "\0");
        if ($bytes === '' || (ord($bytes[0]) & 0x80)) {
            $bytes = "\0" . $bytes;
        }
        return self::der(0x02, $bytes);
    }

    /** @return array{0:string,1:int} PEM y algoritmo (-7 o -257) */
    public static function coseAPem(array $cose): array
    {
        $kty = $cose[1] ?? null;
        $alg = $cose[3] ?? null;
        if ($kty === 2 && $alg === -7) {
            $x = $cose[-2] ?? '';
            $y = $cose[-3] ?? '';
            if (($cose[-1] ?? null) !== 1 || !is_string($x) || !is_string($y) || strlen($x) !== 32 || strlen($y) !== 32) {
                throw new RuntimeException('Llave de la huella no válida.');
            }
            $der = hex2bin(self::PREFIJO_SPKI_EC) . "\x04" . $x . $y;
        } elseif ($kty === 3 && $alg === -257) {
            $n = $cose[-1] ?? '';
            $e = $cose[-2] ?? '';
            if (!is_string($n) || !is_string($e) || strlen($n) < 256 || strlen($e) < 1 || strlen($e) > 8) {
                throw new RuntimeException('Llave de la huella no válida.');
            }
            $rsa = self::der(0x30, self::derEntero($n) . self::derEntero($e));
            $algId = self::der(0x30, self::der(0x06, hex2bin('2a864886f70d010101')) . "\x05\x00");
            $der = self::der(0x30, $algId . self::der(0x03, "\0" . $rsa));
        } else {
            throw new RuntimeException('Este celular usa un tipo de huella que todavía no aceptamos.');
        }
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
        if (openssl_pkey_get_public($pem) === false) {
            throw new RuntimeException('Llave de la huella no válida.');
        }
        return [$pem, $alg];
    }

    // ------------------------------------------------------------------ revisiones comunes

    /** Revisa clientDataJSON: tipo, desafío y origen. */
    public static function revisarCliente(string $clientDataJSON, string $tipo, string $desafio, string $origen): void
    {
        $c = json_decode($clientDataJSON, true);
        if (!is_array($c)) {
            throw new RuntimeException('Respuesta de la huella no válida.');
        }
        if (($c['type'] ?? '') !== $tipo) {
            throw new RuntimeException('Respuesta de la huella no válida.');
        }
        if (!is_string($c['challenge'] ?? null) || $desafio === '' || !hash_equals(self::b64u($desafio), rtrim($c['challenge'], '='))) {
            throw new RuntimeException('La solicitud de huella venció. Intenta de nuevo.');
        }
        if (($c['origin'] ?? '') !== $origen) {
            throw new RuntimeException('La huella se pidió desde otra página.');
        }
    }

    /** @return array{flags:int, contador:int} */
    public static function revisarAuthData(string $authData, string $rpId): array
    {
        if (strlen($authData) < 37) {
            throw new RuntimeException('Respuesta de la huella no válida.');
        }
        if (!hash_equals(hash('sha256', $rpId, true), substr($authData, 0, 32))) {
            throw new RuntimeException('La huella es de otra página.');
        }
        $flags = ord($authData[32]);
        if (!($flags & 0x01)) {
            throw new RuntimeException('Falta tocar el sensor del celular.');
        }
        if (!($flags & 0x04)) {
            throw new RuntimeException('El celular no comprobó tu huella. Activa el bloqueo de pantalla con huella.');
        }
        return ['flags' => $flags, 'contador' => unpack('N', substr($authData, 33, 4))[1]];
    }

    // ------------------------------------------------------------------ desafíos (en la sesión)

    public static function nuevoDesafio(string $para): string
    {
        $d = random_bytes(32);
        $_SESSION['huella_desafio'] = ['d' => self::b64u($d), 'para' => $para, 'hasta' => time() + self::VENCE];
        return $d;
    }

    /** Toma el desafío guardado (una sola vez). */
    public static function tomarDesafio(string $para): string
    {
        $g = $_SESSION['huella_desafio'] ?? null;
        unset($_SESSION['huella_desafio']);
        if (!is_array($g) || ($g['para'] ?? '') !== $para || (int) ($g['hasta'] ?? 0) < time()) {
            return '';
        }
        return self::deB64u((string) $g['d']);
    }

    /** Identificador fijo de la persona para el celular (no revela el id ni el correo). */
    public static function idUsuario(int $usuarioId): string
    {
        return substr(hash_hmac('sha256', 'tuwa-huella:' . $usuarioId, (string) (getenv('TUWA_LLAVE') ?: 'tuwa')), 0, 32);
    }

    // ------------------------------------------------------------------ registro

    public static function opcionesRegistro(PDO $db, array $usuario, string $rpId, string $desafio): array
    {
        $st = $db->prepare('SELECT credencial_id FROM huellas WHERE usuario_id = ?');
        $st->execute([(int) $usuario['id']]);
        $excluir = array_map(fn ($c) => ['type' => 'public-key', 'id' => $c], $st->fetchAll(PDO::FETCH_COLUMN));
        return [
            'challenge' => self::b64u($desafio),
            'rp' => ['id' => $rpId, 'name' => en_app_tienda_seguro() ? 'Tukán Mensajes' : 'WhatsApp Tukán'],
            'user' => ['id' => self::b64u(self::idUsuario((int) $usuario['id'])), 'name' => (string) $usuario['correo'], 'displayName' => (string) $usuario['nombre']],
            'pubKeyCredParams' => [['type' => 'public-key', 'alg' => -7], ['type' => 'public-key', 'alg' => -257]],
            'authenticatorSelection' => ['authenticatorAttachment' => 'platform', 'residentKey' => 'required', 'requireResidentKey' => true, 'userVerification' => 'required'],
            'attestation' => 'none',
            'timeout' => 60000,
            'excludeCredentials' => $excluir,
        ];
    }

    /**
     * Guarda la huella de este celular.
     * @param array{id:string, clientDataJSON:string, attestationObject:string} $r (base64url, como lo manda el navegador)
     * @return int id de la huella guardada
     */
    public static function registrar(PDO $db, int $usuarioId, array $r, string $desafio, string $rpId, string $origen, string $nombre = ''): int
    {
        $cliente = self::deB64u((string) ($r['clientDataJSON'] ?? ''));
        self::revisarCliente($cliente, 'webauthn.create', $desafio, $origen);
        $att = self::cbor(self::deB64u((string) ($r['attestationObject'] ?? '')));
        if (!is_array($att) || !is_string($att['authData'] ?? null)) {
            throw new RuntimeException('Respuesta de la huella no válida.');
        }
        $auth = $att['authData'];
        $info = self::revisarAuthData($auth, $rpId);
        if (!($info['flags'] & 0x40) || strlen($auth) < 55) {
            throw new RuntimeException('El celular no mandó la llave de la huella.');
        }
        $largoId = unpack('n', substr($auth, 53, 2))[1];
        $credId = substr($auth, 55, $largoId);
        if ($largoId < 16 || $largoId > 1023 || strlen($credId) !== $largoId) {
            throw new RuntimeException('Respuesta de la huella no válida.');
        }
        $pos = 55 + $largoId;
        $cose = self::cborLeer($auth, $pos);
        if (!is_array($cose)) {
            throw new RuntimeException('Llave de la huella no válida.');
        }
        [$pem, $alg] = self::coseAPem($cose);
        if (!hash_equals(self::b64u($credId), rtrim((string) ($r['id'] ?? ''), '='))) {
            throw new RuntimeException('Respuesta de la huella no válida.');
        }
        $st = $db->prepare('SELECT usuario_id FROM huellas WHERE credencial_id = ?');
        $st->execute([self::b64u($credId)]);
        $ya = $st->fetchColumn();
        if ($ya !== false && (int) $ya !== $usuarioId) {
            throw new RuntimeException('Esa huella ya está guardada en otra cuenta.');
        }
        $st = $db->prepare('INSERT INTO huellas (usuario_id, credencial_id, llave_pem, alg, contador, nombre) VALUES (?, ?, ?, ?, ?, ?)
                            ON CONFLICT (credencial_id) DO UPDATE SET llave_pem = EXCLUDED.llave_pem, alg = EXCLUDED.alg, contador = EXCLUDED.contador, nombre = EXCLUDED.nombre
                            RETURNING id');
        $st->execute([$usuarioId, self::b64u($credId), $pem, $alg, $info['contador'], mb_substr($nombre !== '' ? $nombre : 'Celular', 0, 60)]);
        return (int) $st->fetchColumn();
    }

    // ------------------------------------------------------------------ entrar

    public static function opcionesEntrar(string $rpId, string $desafio): array
    {
        return ['challenge' => self::b64u($desafio), 'rpId' => $rpId, 'userVerification' => 'required', 'timeout' => 60000, 'allowCredentials' => []];
    }

    /**
     * Revisa la firma del celular. Devuelve el id del usuario (activo).
     * @param array{id:string, clientDataJSON:string, authenticatorData:string, signature:string} $r (base64url)
     */
    public static function entrar(PDO $db, array $r, string $desafio, string $rpId, string $origen): int
    {
        $cliente = self::deB64u((string) ($r['clientDataJSON'] ?? ''));
        self::revisarCliente($cliente, 'webauthn.get', $desafio, $origen);
        $auth = self::deB64u((string) ($r['authenticatorData'] ?? ''));
        $info = self::revisarAuthData($auth, $rpId);
        $st = $db->prepare('SELECT h.*, u.activo FROM huellas h JOIN usuarios u ON u.id = h.usuario_id WHERE h.credencial_id = ?');
        $st->execute([rtrim((string) ($r['id'] ?? ''), '=')]);
        $h = $st->fetch();
        if (!$h) {
            throw new RuntimeException('Esta huella no está guardada en WhatsApp Tukán. Entra con tu contraseña y actívala de nuevo en Mi cuenta.');
        }
        if (!$h['activo']) {
            throw new RuntimeException('Tu usuario está desactivado. Habla con el administrador de tu cuenta.');
        }
        $firma = self::deB64u((string) ($r['signature'] ?? ''));
        if ($firma === '' || openssl_verify($auth . hash('sha256', $cliente, true), $firma, (string) $h['llave_pem'], OPENSSL_ALGO_SHA256) !== 1) {
            throw new RuntimeException('La huella no coincide. Intenta de nuevo.');
        }
        $antes = (int) $h['contador'];
        if ($antes > 0 && $info['contador'] <= $antes) {
            throw new RuntimeException('Esta huella parece copiada. Entra con tu contraseña.');
        }
        $db->prepare('UPDATE huellas SET contador = ?, ultimo_uso = now() WHERE id = ?')->execute([$info['contador'], $h['id']]);
        return (int) $h['usuario_id'];
    }

    // ------------------------------------------------------------------ Mi cuenta

    /** @return list<array> */
    public static function lista(PDO $db, int $usuarioId): array
    {
        $st = $db->prepare('SELECT id, nombre, creado, ultimo_uso FROM huellas WHERE usuario_id = ? ORDER BY creado');
        $st->execute([$usuarioId]);
        return $st->fetchAll();
    }

    public static function cuantas(PDO $db, int $usuarioId): int
    {
        $st = $db->prepare('SELECT count(*) FROM huellas WHERE usuario_id = ?');
        $st->execute([$usuarioId]);
        return (int) $st->fetchColumn();
    }

    public static function quitar(PDO $db, int $usuarioId, int $id): bool
    {
        $st = $db->prepare('DELETE FROM huellas WHERE id = ? AND usuario_id = ?');
        $st->execute([$id, $usuarioId]);
        return $st->rowCount() > 0;
    }
}

/** Nombre del sitio para el celular (en las apps de tienda no se usa la marca WhatsApp). */
function en_app_tienda_seguro(): bool
{
    return function_exists('en_app_tienda') && \en_app_tienda();
}
