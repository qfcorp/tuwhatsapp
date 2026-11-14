<?php
declare(strict_types=1);

/**
 * API de Telegram SIMULADA para las pruebas (nunca habla con Telegram de verdad). Se levanta con php -S (ver tests/correr.sh).
 * Guarda cada llamada en TG_LOG (una línea JSON: metodo, token, datos).
 *  - Token bueno: el que empieza con "123456:" → getMe responde @TukanMensajesBot; "654321:" → @OtroTukanBot (cambio de bot);
 *    cualquier otro → 401.
 *  - sendMessage a chat_id 403403 → 403 (la persona bloqueó el bot); 400400 → 400 "chat not found"; 500500 → 500 (falla temporal);
 *    403000 → 403 sin JSON de Telegram (como un proxy: no debe desconectar a nadie); 900900 → tarda 5 s (Telegram lento).
 */
$ruta = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json');
if (!preg_match('#^/bot([^/]+)/(\w+)$#', $ruta, $m)) {
    http_response_code(404);
    echo '{"ok":false,"error_code":404}';
    return;
}
[, $token, $metodo] = $m;
$datos = json_decode((string) file_get_contents('php://input'), true) ?: [];
file_put_contents((string) getenv('TG_LOG'), json_encode(['metodo' => $metodo, 'token' => $token, 'datos' => $datos, 't' => microtime(true)], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
if (!str_starts_with($token, '123456:') && !str_starts_with($token, '654321:')) {
    http_response_code(401);
    echo '{"ok":false,"error_code":401,"description":"Unauthorized"}';
    return;
}
switch ($metodo) {
    case 'getMe':
        $otro = str_starts_with($token, '654321:');
        echo json_encode(['ok' => true, 'result' => ['id' => $otro ? 654321 : 123456, 'is_bot' => true, 'first_name' => 'Tukán Mensajes',
                                                     'username' => $otro ? 'OtroTukanBot' : 'TukanMensajesBot']]);
        return;
    case 'setWebhook':
        $ok = str_starts_with((string) ($datos['url'] ?? ''), 'https://') && ($datos['secret_token'] ?? '') !== '';
        http_response_code($ok ? 200 : 400);
        echo json_encode($ok ? ['ok' => true, 'result' => true] : ['ok' => false, 'description' => 'Bad Request: bad webhook']);
        return;
    case 'sendMessage':
        $chat = (int) ($datos['chat_id'] ?? 0);
        if ($chat === 403403) {
            http_response_code(403);
            echo '{"ok":false,"error_code":403,"description":"Forbidden: bot was blocked by the user"}';
            return;
        }
        if ($chat === 400400) {
            http_response_code(400);
            echo '{"ok":false,"error_code":400,"description":"Bad Request: chat not found"}';
            return;
        }
        if ($chat === 403000) {
            http_response_code(403);
            header('Content-Type: text/html');
            echo '<html>Forbidden</html>';
            return;
        }
        if ($chat === 900900) {
            sleep(5);
        }
        if ($chat === 500500) {
            http_response_code(500);
            echo '{"ok":false,"error_code":500,"description":"Internal Server Error"}';
            return;
        }
        echo json_encode(['ok' => true, 'result' => ['message_id' => random_int(1, 99999), 'chat' => ['id' => $chat]]]);
        return;
    default:
        echo '{"ok":true,"result":true}';
}
