<?php
declare(strict_types=1);

namespace TuWhatsApp;

use DateTimeImmutable;
use PDO;

/**
 * Conector con el Panel Tukán Central (panel.tukanec.com). Esta clase es la parte del protocolo v1 (docs/PROTOCOLO.md del Central):
 *   /tukan_central.php?accion=sso&t=<token>     entra como personal de Tukán y abre "Clientes de Tukán"
 *                                               (con "ir" en el token: abre esa pantalla, ej. /?r=cliente&id=3)
 *   /tukan_central.php?accion=estado&t=<token>  cifras en JSON (solo nombres de negocios, sin datos personales)
 * El protocolo v2 (crear y manejar demos, clientes, módulos) está en TukanCentralV2; usa el mismo secreto, los mismos nonces
 * y el mismo límite por IP (120 pedidos y 30 rechazados por minuto). Todos los errores traen "codigo" (TukanCentralError).
 * Configuración en config/.env: TUKAN_CENTRAL_ID, TUKAN_CENTRAL_SECRET, TUKAN_CENTRAL_URL y TUKAN_SOLO_CENTRAL (0/1).
 * Token: b64url(json) . "." . b64url(HMAC-SHA256(b64url(json), secreto)); dura máximo 60 s y sirve una sola vez.
 */
final class TukanCentral
{
    public const PRODUCTO = 'tuwhatsapp';
    public const NOMBRE = 'WhatsApp Tukán';
    public const URL_POR_DEFECTO = 'https://panel.tukanec.com';
    public const TOLERANCIA = 30;
    public const VIDA_MAXIMA = 60;
    public const LIMITE_POR_MINUTO = 120;     // pedidos por minuto por IP (protocolo v2 §3.1), v1 y v2 juntos
    public const RECHAZOS_POR_MINUTO = 30;    // pedidos rechazados (401) por minuto por IP
    public const DIAS_POR_VENCER = 7;         // §4.2: pagado y vence en 0 a 7 días
    public const MAX_LISTA = 20;
    /** A dónde puede llevar el enlace directo "ir" del SSO (solo pantallas del personal de Tukán). */
    public const RUTAS_SSO = ['/?r=cliente'];

    public function __construct(private PDO $db) {}

    public static function config(): ?array
    {
        $id = trim((string) getenv('TUKAN_CENTRAL_ID'));
        $secreto = trim((string) getenv('TUKAN_CENTRAL_SECRET'));
        if (!preg_match('/^[a-z0-9_-]{2,40}$/', $id) || strlen($secreto) < 32) {
            return null;
        }
        return ['id' => $id, 'secreto' => $secreto];
    }

    public static function urlCentral(): string
    {
        $url = rtrim(trim((string) getenv('TUKAN_CENTRAL_URL')), '/');
        return preg_match('#^https?://[^\s"\'<>]+$#i', $url) ? $url : self::URL_POR_DEFECTO;
    }

    /** TUKAN_SOLO_CENTRAL=1: el personal de Tukán solo entra desde el panel central (los clientes entran igual que siempre). */
    public static function soloCentral(): bool
    {
        return trim((string) getenv('TUKAN_SOLO_CENTRAL')) === '1' && self::config() !== null;
    }

    public static function b64(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    public static function unb64(string $s): string|false
    {
        return base64_decode(strtr($s, '-_', '+/'), true);
    }

    public static function firmar(array $p, string $secreto): string
    {
        $a = self::b64(json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $a . '.' . self::b64(hash_hmac('sha256', $a, $secreto, true));
    }

    /** Revisa el token v1 en el orden del protocolo. Devuelve los datos o lanza TukanCentralError (con "codigo"; HTTP en getCode). */
    public function validar(string $token, string $accion, ?int $ahora = null): array
    {
        $conf = self::config();
        if (!$conf) {
            throw new TukanCentralError('sin_configurar', 'El conector no está configurado.', 404);
        }
        $ahora ??= time();
        $partes = explode('.', $token);
        if (count($partes) !== 2 || $partes[0] === '' || $partes[1] === '') {
            throw new TukanCentralError('firma', 'El enlace no es válido.', 401);
        }
        if (!hash_equals(self::b64(hash_hmac('sha256', $partes[0], $conf['secreto'], true)), $partes[1])) {
            throw new TukanCentralError('firma', 'La firma del enlace no es correcta.', 401);
        }
        $json = self::unb64($partes[0]);
        $p = $json === false ? null : json_decode($json, true);
        if (!is_array($p)) {
            throw new TukanCentralError('firma', 'El enlace no es válido.', 401);
        }
        if (($p['v'] ?? null) !== 1) {
            throw new TukanCentralError('version', 'Versión del protocolo no soportada.', 401);
        }
        if (($p['tipo'] ?? null) !== $accion) {
            throw new TukanCentralError('tipo', 'El enlace no es para esta acción.', 401);
        }
        if (!is_string($p['inst'] ?? null) || !hash_equals($conf['id'], $p['inst'])) {
            throw new TukanCentralError('inst', 'El enlace es de otra instalación.', 401);
        }
        $iat = $p['iat'] ?? null;
        $exp = $p['exp'] ?? null;
        if (!is_int($iat) || !is_int($exp)) {
            throw new TukanCentralError('invalido', 'El enlace no tiene fecha válida.', 401);
        }
        if ($ahora > $exp + self::TOLERANCIA) {
            throw new TukanCentralError('vencido', 'El enlace ya venció. Vuelve a intentar desde el Panel Tukán.', 401);
        }
        if ($iat > $ahora + self::TOLERANCIA) {
            throw new TukanCentralError('futuro', 'El enlace tiene una fecha futura. Revisa la hora del servidor.', 401);
        }
        if ($exp < $iat || $exp - $iat > self::VIDA_MAXIMA) {
            throw new TukanCentralError('invalido', 'El enlace dura más de lo permitido.', 401);
        }
        $nonce = $p['nonce'] ?? null;
        if (!is_string($nonce) || !preg_match('/^[0-9a-f]{32}$/', $nonce)) {
            throw new TukanCentralError('nonce', 'El enlace no es válido (nonce).', 401);
        }
        if (!$this->usarNonce($nonce, $exp, $ahora)) {
            throw new TukanCentralError('nonce', 'Este enlace ya se usó. Vuelve a intentar desde el Panel Tukán.', 401);
        }
        $email = $p['email'] ?? null;
        if (!is_string($email) || mb_strlen($email) > 120 || !filter_var(trim($email), FILTER_VALIDATE_EMAIL)) {
            throw new TukanCentralError('invalido', 'El correo del enlace no es válido.', 401);
        }
        $p['email'] = mb_strtolower(trim($email));
        return $p;
    }

    /** Gasta un nonce (32 hex): true la primera vez, false si ya se usó o no es válido. Se guarda hasta exp + 30 s. */
    public function usarNonce(mixed $nonce, int $exp, ?int $ahora = null): bool
    {
        $ahora ??= time();
        if (!is_string($nonce) || !preg_match('/^[0-9a-f]{32}$/', $nonce)) {
            return false;
        }
        $this->db->prepare('DELETE FROM tukan_central_nonces WHERE vence < ?')->execute([date('c', $ahora)]);
        $st = $this->db->prepare('INSERT INTO tukan_central_nonces (nonce, vence) VALUES (?, ?) ON CONFLICT DO NOTHING');
        $st->execute([$nonce, date('c', $exp + self::TOLERANCIA)]);
        return $st->rowCount() === 1;
    }

    /** Cuenta este pedido. false si la IP ya pasó de 120 pedidos o de 30 rechazados (401) en este minuto. */
    public function dentroDelLimite(string $ip, ?int $ahora = null): bool
    {
        $ahora ??= time();
        $minuto = date('c', $ahora - $ahora % 60);
        $this->db->prepare("DELETE FROM tukan_central_pedidos WHERE minuto < ?")->execute([date('c', $ahora - 600)]);
        $st = $this->db->prepare('INSERT INTO tukan_central_pedidos (ip, minuto) VALUES (?, ?)
                                  ON CONFLICT (ip, minuto) DO UPDATE SET n = tukan_central_pedidos.n + 1 RETURNING n, rechazados');
        $st->execute([mb_substr($ip, 0, 45), $minuto]);
        $f = $st->fetch();
        return (int) $f['n'] <= self::LIMITE_POR_MINUTO && (int) $f['rechazados'] < self::RECHAZOS_POR_MINUTO;
    }

    /** Anota un pedido rechazado (401) de esa IP en este minuto. */
    public function anotarRechazo(string $ip, ?int $ahora = null): void
    {
        $ahora ??= time();
        $minuto = date('c', $ahora - $ahora % 60);
        $this->db->prepare('INSERT INTO tukan_central_pedidos (ip, minuto, n, rechazados) VALUES (?, ?, 1, 1)
                            ON CONFLICT (ip, minuto) DO UPDATE SET rechazados = tukan_central_pedidos.rechazados + 1')
                 ->execute([mb_substr($ip, 0, 45), $minuto]);
    }

    /** Enlace directo "ir" del SSO (protocolo v2 §5.3): ruta relativa segura que empieza con una de RUTAS_SSO. */
    public static function irValido(mixed $ir): bool
    {
        if (!is_string($ir) || $ir === '' || strlen($ir) > 300 || $ir[0] !== '/' || str_starts_with($ir, '//')) {
            return false;
        }
        if (str_contains($ir, '\\') || preg_match('/[\x00-\x1F\x7F]/', $ir) || !preg_match('#^/[A-Za-z0-9._~!$&\'()*+,;=:@%/?\#-]*$#', $ir)) {
            return false;
        }
        foreach (self::RUTAS_SSO as $r) {
            if (str_starts_with($ir, $r)) {
                return true;
            }
        }
        return false;
    }

    /** El usuario de Tukán con ese correo (lo crea la primera vez, con una contraseña que nadie conoce). */
    public function superUsuario(array $p): int
    {
        $st = $this->db->prepare('SELECT id, super, empresa_id FROM usuarios WHERE lower(correo) = ?');
        $st->execute([$p['email']]);
        $u = $st->fetch();
        if ($u) {
            if (!$u['super'] && (int) $u['empresa_id'] !== Empresas::TUKAN) {
                throw new TukanCentralError('no_permitido', 'Ese correo es de un cliente, no del personal de Tukán.', 403);
            }
            $this->db->prepare('UPDATE usuarios SET super = true, activo = true WHERE id = ?')->execute([$u['id']]);
            return (int) $u['id'];
        }
        $nombre = trim((string) ($p['nombre'] ?? '')) ?: $p['email'];
        $st = $this->db->prepare("INSERT INTO usuarios (empresa_id, correo, nombre, clave, rol, super, activo, creado_por_central)
                                  VALUES (?, ?, ?, ?, 'admin', true, true, true) RETURNING id");
        $st->execute([Empresas::TUKAN, $p['email'], mb_substr($nombre, 0, 100), password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT)]);
        return (int) $st->fetchColumn();
    }

    /**
     * Estado v2 de una empresa con la regla única del protocolo (§4.2). La usan "estado" (v1) y "clientes" (v2),
     * así los dos cuentan igual. WhatsApp Tukán no tiene días de gracia: vencida = no entra.
     * @return array{estado:string, detalle:string, bloqueado:bool}
     */
    public static function clasificar(array $e, DateTimeImmutable $hoy): array
    {
        $hoy = $hoy->setTime(0, 0);
        $alDia = Empresas::alDia($e, $hoy);
        if ($e['estado'] === 'suspendida') {
            return ['estado' => 'suspendido', 'detalle' => 'suspendida', 'bloqueado' => true];
        }
        if ($e['estado'] === 'demo') {
            return $alDia ? ['estado' => 'prueba', 'detalle' => 'demo', 'bloqueado' => false]
                          : ['estado' => 'vencido', 'detalle' => 'demo_terminada', 'bloqueado' => true];
        }
        if (empty($e['vence'])) {
            return ['estado' => 'activo', 'detalle' => 'sin_limite', 'bloqueado' => false];
        }
        if (!$alDia) {
            return ['estado' => 'vencido', 'detalle' => 'vencida', 'bloqueado' => true];
        }
        $en7 = $hoy->modify('+' . self::DIAS_POR_VENCER . ' days')->format('Y-m-d');
        return ['estado' => (string) $e['vence'] <= $en7 ? 'por_vencer' : 'activo', 'detalle' => 'activa', 'bloqueado' => false];
    }

    /** Cifras para el panel central. Los clientes son las empresas, sin contar a Tukán. */
    public function estado(?DateTimeImmutable $hoy = null): array
    {
        $hoy = ($hoy ?? new DateTimeImmutable('today'))->setTime(0, 0);
        $en7 = $hoy->modify('+7 days')->format('Y-m-d');
        $n = ['total' => 0, 'prueba' => 0, 'activos' => 0, 'por_vencer' => 0, 'vencidos' => 0, 'suspendidos' => 0];
        $plural = ['prueba' => 'prueba', 'activo' => 'activos', 'por_vencer' => 'por_vencer', 'vencido' => 'vencidos', 'suspendido' => 'suspendidos'];
        $pruebas = $vencidos = [];
        $empresas = $this->db->query('SELECT * FROM empresas WHERE id <> ' . Empresas::TUKAN . ' ORDER BY vence NULLS LAST, nombre')->fetchAll();
        foreach ($empresas as $e) {
            $n['total']++;
            $c = self::clasificar($e, $hoy);
            $n[$plural[$c['estado']]]++;
            if ($c['detalle'] === 'vencida' && count($vencidos) < self::MAX_LISTA) {
                $vencidos[] = ['nombre' => $e['nombre'], 'desde' => (string) $e['vence']];
            } elseif ($c['estado'] === 'prueba' && $e['vence'] <= $en7 && count($pruebas) < self::MAX_LISTA) {
                $pruebas[] = ['nombre' => $e['nombre'], 'vence' => (string) $e['vence']];
            }
        }
        $uno = fn (string $sql) => $this->db->query($sql)->fetchColumn();
        $mes = $hoy->modify('first day of this month')->format('Y-m-d');
        return [
            'ok' => true,
            'protocolo' => 1,
            'protocolo_max' => 2,   // este programa ya habla v2 (el Central viejo lo ignora)
            'producto' => self::PRODUCTO,
            'nombre' => self::NOMBRE,
            'version' => self::version(),
            'generado' => (new DateTimeImmutable())->format('c'),
            'negocios' => $n,
            'nuevos_30d' => (int) $uno("SELECT count(*) FROM empresas WHERE id <> 1 AND creado >= now() - interval '30 days'"),
            'ingresos_mes' => null,
            'ingresos_mes_anterior' => null,
            'pruebas_vencen_7d' => $pruebas,
            'pagos_vencidos' => $vencidos,
            'extra' => [
                'Números conectados' => (int) $uno('SELECT count(*) FROM lineas'),
                'Mensajes este mes' => (int) $uno("SELECT count(*) FROM mensajes WHERE creado >= '$mes'"),
                'Respuestas automáticas este mes' => (int) $uno("SELECT count(*) FROM mensajes WHERE origen IN ('auto', 'bot', 'flujo') AND creado >= '$mes'"),
                'Campañas este mes' => (int) $uno("SELECT count(*) FROM campanias WHERE creado >= '$mes'"),
                'Gasto en Meta del mes (todos)' => '$' . number_format((float) $uno("SELECT COALESCE(sum(costo), 0) FROM mensajes WHERE direccion = 'sale' AND estado NOT IN ('fallido', 'bloqueado') AND creado >= '$mes'"), 2, ',', '.'),
                'Usuarios' => (int) $uno('SELECT count(*) FROM usuarios WHERE activo'),
            ],
        ];
    }

    public static function version(): string
    {
        $head = @file_get_contents(RAIZ . '/.git/HEAD');
        if (is_string($head) && preg_match('#^ref: (\S+)#', $head, $m)) {
            $ref = @file_get_contents(RAIZ . '/.git/' . $m[1]);
            if (!is_string($ref)) {   // las ramas pueden estar en packed-refs
                $packed = (string) @file_get_contents(RAIZ . '/.git/packed-refs');
                $ref = preg_match('#^([0-9a-f]{40}) ' . preg_quote($m[1], '#') . '$#m', $packed, $x) ? $x[1] : '';
            }
            $head = $ref;
        }
        $h = trim((string) $head);
        return preg_match('/^[0-9a-f]{40}$/', $h) ? substr($h, 0, 7) : 'sin-version';
    }
}
