<?php
declare(strict_types=1);

// Aquí avisa Telegram (bot de avisos de WhatsApp Tukán): "/start <código>" conecta el Telegram de una persona, "/parar" lo desconecta.
// Solo acepta lo que trae el secreto que se registró con setWebhook (deploy/configurar_telegram.sh). No usa sesión.
define('TUWA_SIN_SESION', true);
define('TUWA_SIN_IDIOMA', true);
require dirname(__DIR__) . '/app/bootstrap.php';

header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}
$secreto = \TuWhatsApp\Telegram::secreto();
$recibido = (string) ($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
if (!\TuWhatsApp\Telegram::configurado() || $recibido === '' || !hash_equals($secreto, $recibido)) {
    http_response_code(403);
    exit;
}
$u = json_decode((string) file_get_contents('php://input', false, null, 0, 200_000), true);
$respuesta = null;
if (is_array($u)) {
    try {
        $respuesta = \TuWhatsApp\Telegram::procesarUpdate(db(), $u);
    } catch (\Throwable $e) {
        error_log('tuwhatsapp telegram webhook: ' . $e->getMessage());
    }
}
// Siempre 200 y rápido (si no, Telegram reintenta). La respuesta al usuario va en la misma respuesta HTTP.
if ($respuesta !== null) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($respuesta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} else {
    header('Content-Type: text/plain; charset=utf-8');
    echo 'ok';
}
