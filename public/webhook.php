<?php
declare(strict_types=1);

// Aquí avisa Meta (mensajes que llegan, estados y lo enviado desde el celular). No usa sesión.
define('TUWA_SIN_SESION', true);
define('TUWA_SIN_IDIOMA', true);
require dirname(__DIR__) . '/app/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $c = \TuWhatsApp\Webhook::verificar($_GET);
    if ($c === null) {
        http_response_code(403);
        echo 'no';
        exit;
    }
    echo $c;
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}
$cuerpo = (string) file_get_contents('php://input', false, null, 0, 1_000_000);
if (!\TuWhatsApp\Webhook::firmaValida($cuerpo, (string) ($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? ''))) {
    // Se anota (recortado) para poder ver el problema: casi siempre es la clave secreta de Meta mal guardada
    try {
        if (str_contains($cuerpo, 'whatsapp_business_account')) {
            db()->prepare('INSERT INTO webhook_log (cuerpo, error) VALUES (?, ?)')->execute([mb_substr($cuerpo, 0, 2000),
                'Firma no valida: la clave secreta de la app de Meta no coincide. Vuelve a guardarla con deploy/clave_meta.sh']);
        }
    } catch (\Throwable $e) {
        error_log('tuwhatsapp webhook firma: ' . $e->getMessage());
    }
    http_response_code(401);
    echo 'firma';
    exit;
}
// Meta quiere la respuesta rápido: se contesta y después se trabaja
echo 'ok';
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}
ignore_user_abort(true);
set_time_limit(60);
(new \TuWhatsApp\Webhook(db()))->procesar($cuerpo);
