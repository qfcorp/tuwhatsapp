<?php
declare(strict_types=1);

// Aviso de pago de Pago Plux (webhook): POST JSON con Basic Auth. Estado PAGADO o RECHAZADO.
// En el panel de Plux (Developers → Web Hook) va esta dirección, con el usuario y la clave que muestra Más → Cobros.
define('TUWA_SIN_SESION', true);
define('TUWA_SIN_IDIOMA', true);
require dirname(__DIR__) . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo '{"ok":false,"error":"Usa POST."}';
    exit;
}
$auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
$u = (string) ($_SERVER['PHP_AUTH_USER'] ?? '');
$c = (string) ($_SERVER['PHP_AUTH_PW'] ?? '');
if ($u === '' && preg_match('/^Basic\s+([A-Za-z0-9+\/=]+)$/i', $auth, $m)) {
    [$u, $c] = array_pad(explode(':', (string) base64_decode($m[1]), 2), 2, '');
}
try {
    [$http, $r] = \TuWhatsApp\Plux::webhook(db(), $u, $c, (string) file_get_contents('php://input', false, null, 0, 50000));
} catch (\Throwable $e) {
    error_log('tuwhatsapp plux webhook: ' . $e);
    [$http, $r] = [500, ['ok' => false, 'error' => 'Error interno.']];
}
if ($http === 401) {
    header('WWW-Authenticate: Basic realm="WhatsApp Tukan"');
}
http_response_code($http);
echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
