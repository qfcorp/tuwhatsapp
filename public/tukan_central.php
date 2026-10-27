<?php
declare(strict_types=1);

/**
 * Conector con el Panel Tukán Central. No pasa por index.php.
 * Protocolo v1 (GET, token en ?t=):
 *   /tukan_central.php?accion=sso&t=<token>     entra como personal de Tukán y abre Clientes de Tukán
 *                                               (con "ir" en el token: abre esa pantalla, ej. /?r=cliente&id=3)
 *   /tukan_central.php?accion=estado&t=<token>  cifras en JSON
 * Protocolo v2 (POST con cuerpo JSON y el token en la cabecera X-Tukan-Token): capacidades, clientes, cliente, planes,
 *   modulos, ia_uso y ejecutar (crear_demo, extender_prueba, suspender, reactivar…) — src/TukanCentralV2.php.
 * Sin TUKAN_CENTRAL_ID o TUKAN_CENTRAL_SECRET en config/.env responde 404 y no hace nada más.
 * Todos los errores traen "codigo" (protocolo v2 §3.2); en el SSO va en la cabecera X-Tukan-Codigo.
 */

use TuWhatsApp\{TukanCentral, TukanCentralError, TukanCentralV2};

$accion = (string) ($_GET['accion'] ?? '');
if ($accion !== 'sso') {
    define('TUWA_SIN_SESION', true);
    define('TUWA_SIN_IDIOMA', true);
}
require dirname(__DIR__) . '/app/bootstrap.php';
header('Cache-Control: no-store');

function tc_json(int $http, array $d, array $cabeceras = []): never
{
    http_response_code($http);
    foreach ($cabeceras as $k => $v) {
        header("$k: $v");
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

function tc_error_sso(int $http, string $mensaje, string $codigo, array $cabeceras = []): never
{
    http_response_code($http);
    foreach ($cabeceras as $k => $v) {
        header("$k: $v");
    }
    header('X-Tukan-Codigo: ' . $codigo);
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
    if ($accion === 'sso' || $accion === '') {
        header('Content-Type: text/plain; charset=utf-8');
        echo "No encontrado\n";
        exit;
    }
    tc_json(404, ['ok' => false, 'protocolo' => 2, 'codigo' => 'sin_configurar', 'error' => 'El conector con el Panel Tukán Central no está configurado.']);
}

// ---------------------------------------------------------------------
// Protocolo v2: todo lo que no es sso ni estado
// ---------------------------------------------------------------------
if (!in_array($accion, ['sso', 'estado'], true)) {
    $cuerpo = (string) file_get_contents('php://input', false, null, 0, TukanCentralV2::MAX_CUERPO + 1);
    [$http, $datos, $cabeceras] = (new TukanCentralV2(db()))->procesar(
        $accion, (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'), (string) ($_SERVER['CONTENT_TYPE'] ?? ($_SERVER['HTTP_CONTENT_TYPE'] ?? '')),
        $cuerpo, (string) ($_SERVER['HTTP_X_TUKAN_TOKEN'] ?? ''), ip_cliente());
    tc_json($http, $datos, $cabeceras);
}

// ---------------------------------------------------------------------
// Protocolo v1: sso y estado (ahora con "codigo" en los errores)
// ---------------------------------------------------------------------
$fallar = function (int $http, string $mensaje, string $codigo, array $cabeceras = []) use ($accion): never {
    $accion === 'sso' ? tc_error_sso($http, $mensaje, $codigo, $cabeceras)
                      : tc_json($http, ['ok' => false, 'protocolo' => 2, 'codigo' => $codigo, 'error' => $mensaje], $cabeceras);
};

try {
    $central = new TukanCentral(db());
    if (!$central->dentroDelLimite(ip_cliente())) {
        $fallar(429, 'Demasiados pedidos. Espera un minuto.', 'limite', ['Retry-After' => '60']);
    }
    try {
        $p = $central->validar((string) ($_GET['t'] ?? ''), $accion);
    } catch (TukanCentralError $e) {
        if ($e->http === 401) {
            $central->anotarRechazo(ip_cliente());
        }
        throw $e;
    }
    if ($accion === 'estado') {
        tc_json(200, $central->estado());
    }
    $id = $central->superUsuario($p);
    // "ir" (enlace directo, §5.3): solo si es una ruta segura de RUTAS_SSO; si no, al inicio (Clientes de Tukán)
    $ir = array_key_exists('ir', $p) && TukanCentral::irValido($p['ir']) ? (string) $p['ir'] : null;
    session_regenerate_id(true);
    $_SESSION = ['usuario_id' => $id, 'desde_central' => true];
    aviso('Entraste desde el Panel Tukán Central.' . (array_key_exists('ir', $p) && $ir === null ? ' El enlace directo no era válido: se abrió Clientes de Tukán.' : ''), 'info');
    header('Location: ' . ($ir ?? url('clientes')));
    exit;
} catch (TukanCentralError $e) {
    $fallar($e->http, $e->getMessage(), $e->codigo);
} catch (\Throwable $e) {
    error_log('tuwhatsapp tukan_central: ' . $e);
    $fallar(500, $accion === 'sso' ? 'Error del servidor. Intenta de nuevo.' : 'Error del servidor', 'interno');
}
