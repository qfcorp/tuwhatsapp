<?php
declare(strict_types=1);

/**
 * Panel Tukán Central SIMULADO para probar el ingreso del super usuario (php -S 127.0.0.1:PUERTO tests/panel_super_simulado.php).
 * Imita public/super_api.php del panel (tukan-central, Anexo C del protocolo v2): revisa el token v2 de tipo «super» (firma con
 * TUKAN_CENTRAL_SECRET, programa TUKAN_CENTRAL_ID, «bh» = sha256 del cuerpo, vida ≤ 60 s) y contesta según el caso:
 *   clave «ClaveBuena1»: sin código → codigo · código 123456 → ok · otro código → codigo con error
 *   otra clave → credenciales · correo que empieza con «bloq» → bloqueado · con «sin2» → sin_2fa
 *   PANEL_SIM_DIR/caido existe → 503; PANEL_SIM_DIR/roto existe → texto que no es JSON.
 *   PANEL_SIM_DIR/super.log: una línea JSON por cada pedido con firma buena (las pruebas la leen).
 */
$dir = getenv('PANEL_SIM_DIR') ?: sys_get_temp_dir() . '/panel_super_sim';
@mkdir($dir, 0777, true);
$json = function (int $c, array $d): never { http_response_code($c); header('Content-Type: application/json; charset=utf-8'); echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; };
$ruta = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if ($ruta === '/') $json(200, ['ok' => true, 'simulado' => true]);
if (is_file("$dir/caido")) { http_response_code(503); echo 'Panel en mantenimiento'; exit; }
if ($ruta !== '/super_api.php' || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') $json(405, ['ok' => false, 'codigo' => 'metodo', 'error' => 'Solo POST.']);
$cuerpo = (string) file_get_contents('php://input');
$b64 = fn(string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
$partes = explode('.', (string) ($_SERVER['HTTP_X_TUKAN_TOKEN'] ?? ''));
$p = count($partes) === 2 ? json_decode((string) base64_decode(strtr($partes[0], '-_', '+/')), true) : null;
$firmaBien = is_array($p) && hash_equals($b64(hash_hmac('sha256', $partes[0], (string) getenv('TUKAN_CENTRAL_SECRET'), true)), $partes[1]);
$iat = (int) ($p['iat'] ?? 0);
$exp = (int) ($p['exp'] ?? 0);
if (!$firmaBien || ($p['v'] ?? 0) !== 2 || ($p['tipo'] ?? '') !== 'super' || ($p['inst'] ?? '') !== getenv('TUKAN_CENTRAL_ID')
    || ($p['bh'] ?? '') !== $b64(hash('sha256', $cuerpo, true)) || $exp < time() || $exp - $iat > 60 || !preg_match('/^[0-9a-f]{32}$/', (string) ($p['nonce'] ?? ''))
    || !filter_var($p['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
    $json(401, ['ok' => false, 'codigo' => 'firma', 'error' => 'Token no válido.']);
}
$b = json_decode($cuerpo, true) ?: [];
file_put_contents("$dir/super.log", json_encode(['email' => $b['email'] ?? '', 'codigo' => $b['codigo'] ?? '', 'ip' => $b['ip'] ?? '', 'vida' => $exp - $iat, 'tipo' => $p['tipo']]) . "\n", FILE_APPEND);
if (is_file("$dir/roto")) { echo 'no soy json'; exit; }
$email = (string) ($b['email'] ?? '');
if (str_starts_with($email, 'bloq')) $json(200, ['ok' => false, 'codigo' => 'bloqueado', 'error' => 'Demasiados intentos equivocados. Por seguridad espera 15 minutos y vuelve a intentar.']);
if (($b['clave'] ?? '') !== 'ClaveBuena1') $json(200, ['ok' => false, 'codigo' => 'credenciales', 'error' => 'El correo o la contraseña no son correctos.']);
if (str_starts_with($email, 'sin2')) $json(200, ['ok' => false, 'codigo' => 'sin_2fa', 'error' => 'Primero activa la verificación en 2 pasos entrando una vez al Panel Tukán.']);
$codigo = trim((string) ($b['codigo'] ?? ''));
if ($codigo === '') $json(200, ['ok' => false, 'codigo' => 'codigo', 'error' => 'Escribe también el código de 2 pasos de tu app.']);
if ($codigo !== '123456') $json(200, ['ok' => false, 'codigo' => 'codigo', 'error' => 'El código de 2 pasos no es correcto.']);
$json(200, ['ok' => true, 'email' => strtolower($email), 'nombre' => 'Super Panel', 'rol' => 'dueno']);
