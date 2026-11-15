<?php
declare(strict_types=1);

// (14 nov 2026) Aquí la app de Android «Tukán Mensajes» registra su celular para los avisos nativos (Firebase Cloud Messaging).
//   POST accion=registrar, dispositivo (32 hex), secreto (64 hex), token, modelo, version, avisos (0/1)  → {"ok":true}
//   POST accion=quitar,    dispositivo, secreto                                          → {"ok":true}
// Un celular ya registrado solo se cambia con su mismo secreto. No usa sesión (el vínculo con la persona va por la cookie tuwa_disp).
define('TUWA_SIN_SESION', true);
define('TUWA_SIN_IDIOMA', true);
require dirname(__DIR__) . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo '{"ok":false,"error":"solo POST"}';
    exit;
}
$accion = (string) ($_POST['accion'] ?? '');
try {
    if ($accion === 'registrar') {
        [$codigo, $r] = \TuWhatsApp\Fcm::registrar(db(), $_POST, ip_cliente());   // la IP real detrás de Cloudflare
    } elseif ($accion === 'quitar') {
        [$codigo, $r] = \TuWhatsApp\Fcm::quitarDesdeApp(db(), $_POST);
    } else {
        [$codigo, $r] = [400, ['ok' => false, 'error' => 'accion desconocida']];
    }
} catch (\Throwable $e) {
    error_log('tuwhatsapp fcm.php: ' . $e->getMessage());
    [$codigo, $r] = [500, ['ok' => false, 'error' => 'error del servidor']];
}
http_response_code($codigo);
echo json_encode($r, JSON_UNESCAPED_UNICODE);
