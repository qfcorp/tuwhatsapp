<?php
declare(strict_types=1);

// Aquí avisan Shopify o WooCommerce (pedidos y carritos). La dirección lleva la clave del número: /tienda.php?c=<clave>
define('TUWA_SIN_SESION', true);
define('TUWA_SIN_IDIOMA', true);
require dirname(__DIR__) . '/app/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Usa POST';
    exit;
}
$linea = \TuWhatsApp\Tiendas::porClave(db(), (string) ($_GET['c'] ?? ''));
if ($linea === null) {
    http_response_code(404);
    echo 'no';
    exit;
}
$cuerpo = (string) file_get_contents('php://input', false, null, 0, 2_000_000);
// WooCommerce manda primero un "ping" (webhook_id=…) al crear el webhook: se contesta bien para que lo active
if ($linea['tienda_tipo'] === 'woocommerce' && preg_match('/^webhook_id=\d+$/', trim($cuerpo))) {
    echo 'ok';
    exit;
}
$firma = (string) ($_SERVER['HTTP_X_SHOPIFY_HMAC_SHA256'] ?? $_SERVER['HTTP_X_WC_WEBHOOK_SIGNATURE'] ?? '');
if (!\TuWhatsApp\Tiendas::firmaValida($linea, $cuerpo, $firma)) {
    db()->prepare('UPDATE lineas SET tienda_error = ? WHERE id = ?')
        ->execute(['Llegó un aviso con la firma mal: revisa que la clave secreta sea la misma en la tienda y aquí (' . date('d/m H:i') . ')', $linea['id']]);
    http_response_code(401);
    echo 'firma';
    exit;
}
$tema = (string) ($_SERVER['HTTP_X_SHOPIFY_TOPIC'] ?? $_SERVER['HTTP_X_WC_WEBHOOK_TOPIC'] ?? '');
$datos = json_decode($cuerpo, true);
if (!is_array($datos)) {
    http_response_code(400);
    echo 'json';
    exit;
}
// La tienda quiere la respuesta rápido: se contesta y después se trabaja
echo 'ok';
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}
ignore_user_abort(true);
set_time_limit(60);
try {
    $r = \TuWhatsApp\Tiendas::procesar(db(), $linea, $tema, $datos);
    db()->prepare('INSERT INTO webhook_log (cuerpo, error) VALUES (?, NULL)')->execute([mb_substr('tienda ' . $tema . ': ' . $r, 0, 2000)]);
} catch (\Throwable $e) {
    error_log('tuwhatsapp tienda: ' . $e);
    db()->prepare('UPDATE lineas SET tienda_error = ? WHERE id = ?')->execute([mb_substr('Error al procesar un aviso: ' . $e->getMessage(), 0, 300), $linea['id']]);
}
