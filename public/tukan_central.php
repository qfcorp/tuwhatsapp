<?php
declare(strict_types=1);

/**
 * Conector con el Panel Tukán Central (protocolo v1). No pasa por index.php.
 *   GET /tukan_central.php?accion=sso&t=<token>     entra como personal de Tukán y abre Clientes de Tukán
 *   GET /tukan_central.php?accion=estado&t=<token>  cifras en JSON
 * Sin TUKAN_CENTRAL_ID o TUKAN_CENTRAL_SECRET en config/.env responde 404.
 * Cualquier otra acción responde 400 sin "codigo": así el panel sabe que este programa es v1.
 */

use TuWhatsApp\TukanCentral;

$accion = (string) ($_GET['accion'] ?? '');
if ($accion !== 'sso') {
    define('TUWA_SIN_SESION', true);
    define('TUWA_SIN_IDIOMA', true);
}
require dirname(__DIR__) . '/app/bootstrap.php';
header('Cache-Control: no-store');

function tc_json(int $http, array $d): never
{
    http_response_code($http);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

function tc_error_sso(int $http, string $mensaje): never
{
    http_response_code($http);
    header('Content-Type: text/html; charset=utf-8');
    $url = e(TukanCentral::urlCentral());
    echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>No se pudo entrar · WhatsApp Tukán</title><link rel="stylesheet" href="/assets/app.css"></head><body>'
       . '<main class="principal"><section class="bloqueada tarjeta"><h1>No se pudo entrar</h1><p>' . e($mensaje) . '</p>'
       . '<p><a class="boton" href="' . $url . '">Volver al Panel Tukán</a></p></section></main></body></html>';
    exit;
}

if (!TukanCentral::config()) {
    http_response_code(404);
    header('X-Tukan-Codigo: sin_configurar');
    header('Content-Type: text/plain; charset=utf-8');
    echo "No encontrado\n";
    exit;
}
if (!in_array($accion, ['sso', 'estado'], true)) {
    tc_json(400, ['ok' => false, 'error' => 'Acción no válida: este programa usa el protocolo v1 (sso y estado).']);
}

try {
    $central = new TukanCentral(db());
    if (!$central->dentroDelLimite(ip_cliente())) {
        header('Retry-After: 60');
        $accion === 'sso' ? tc_error_sso(429, 'Demasiados pedidos. Espera un minuto.') : tc_json(429, ['ok' => false, 'error' => 'Demasiados pedidos']);
    }
    try {
        $p = $central->validar((string) ($_GET['t'] ?? ''), $accion);
    } catch (\RuntimeException $e) {
        if ($e instanceof \PDOException) {
            throw $e;
        }
        $http = $e->getCode() >= 400 ? (int) $e->getCode() : 401;
        $accion === 'sso' ? tc_error_sso($http, $e->getMessage()) : tc_json($http, ['ok' => false, 'error' => $e->getMessage()]);
    }
    if ($accion === 'estado') {
        tc_json(200, $central->estado());
    }
    try {
        $id = $central->superUsuario($p);
    } catch (\RuntimeException $e) {
        if ($e instanceof \PDOException) {
            throw $e;
        }
        tc_error_sso(403, $e->getMessage());
    }
    session_regenerate_id(true);
    $_SESSION = ['usuario_id' => $id, 'desde_central' => true];
    aviso('Entraste desde el Panel Tukán Central.', 'info');
    header('Location: ' . url('clientes'));
    exit;
} catch (\PDOException $e) {
    error_log('tuwhatsapp tukan_central: ' . $e);
    $accion === 'sso' ? tc_error_sso(500, 'Error del servidor. Intenta de nuevo.') : tc_json(500, ['ok' => false, 'error' => 'Error del servidor']);
}
