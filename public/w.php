<?php
declare(strict_types=1);

// Enlace corto del módulo "Enlaces": cuenta el clic y abre WhatsApp con el texto listo. No usa sesión.
//   https://whatsapp.tukanec.com/w.php?c=abc123
define('TUWA_SIN_SESION', true);
define('TUWA_SIN_IDIOMA', true);
require dirname(__DIR__) . '/app/bootstrap.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
$destino = null;
try {
    $destino = \TuWhatsApp\Enlaces::abrir(db(), (string) ($_GET['c'] ?? ''));
} catch (\Throwable $e) {
    error_log('tuwhatsapp enlace: ' . $e->getMessage());
}
if ($destino === null) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Enlace no disponible</title>'
       . '<p style="font-family:sans-serif;padding:24px">Este enlace ya no está disponible.</p>';
    exit;
}
header('Location: ' . $destino, true, 302);
