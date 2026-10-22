<?php
// Meta (Graph API) y Telegram de mentira para las pruebas. Uso: SIM_LOG=archivo php -S 127.0.0.1:8097 tests/meta_simulado.php
declare(strict_types=1);

$log = getenv('SIM_LOG') ?: sys_get_temp_dir() . '/tuwa_sim.log';
$ruta = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$cuerpo = (string) file_get_contents('php://input');
$auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
file_put_contents($log, json_encode(['metodo' => $_SERVER['REQUEST_METHOD'], 'ruta' => $ruta, 'auth' => $auth, 'cuerpo' => json_decode($cuerpo, true) ?? $cuerpo]) . "\n", FILE_APPEND | LOCK_EX);
header('Content-Type: application/json');

function sale(int $c, array $d): never { http_response_code($c); echo json_encode($d); exit; }

// IA (Anthropic Messages API de mentira)
if ($ruta === '/v1/messages' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_SERVER['HTTP_X_API_KEY'] ?? '') === '' || ($_SERVER['HTTP_ANTHROPIC_VERSION'] ?? '') === '') {
        sale(401, ['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key']]);
    }
    $d = json_decode($cuerpo, true) ?: [];
    $sis = (string) ($d['system'] ?? '');
    $txt = str_contains($sis, 'resume') ? "Quiere el plan Pro.\nSiguiente paso: mandarle la cotización." : '¡Hola! El plan Pro cuesta $45 al mes. ¿Te lo activo?';
    if (str_contains((string) ($d['messages'][0]['content'] ?? ''), 'ROMPER')) {
        sale(529, ['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']]);
    }
    sale(200, ['id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => $d['model'] ?? '', 'content' => [['type' => 'text', 'text' => $txt]],
               'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 1000, 'output_tokens' => 200]]);
}
// Otro programa que recibe los avisos (webhook de salida)
if ($ruta === '/aviso' || $ruta === '/aviso_malo') {
    file_put_contents($log, json_encode(['metodo' => 'AVISO', 'ruta' => $ruta, 'firma' => $_SERVER['HTTP_X_TUKAN_FIRMA'] ?? '', 'crudo' => $cuerpo, 'cuerpo' => json_decode($cuerpo, true)]) . "\n", FILE_APPEND | LOCK_EX);
    sale($ruta === '/aviso' ? 200 : 500, ['ok' => $ruta === '/aviso']);
}
if (preg_match('#^/bot[^/]+/sendMessage$#', $ruta)) {
    sale(200, ['ok' => true]);
}
if ($auth === 'Bearer token_malo_0000000000000000000') {
    sale(401, ['error' => ['message' => 'Invalid OAuth access token', 'code' => 190]]);
}
if (preg_match('#^/v[\d.]+/oauth/access_token$#', $ruta)) {   // botón QR: código -> token del negocio
    if (($_GET['client_secret'] ?? '') !== 'secreto_de_prueba' || ($_GET['client_id'] ?? '') === '') {
        sale(400, ['error' => ['message' => 'Error validating client secret', 'code' => 1]]);
    }
    if (str_starts_with((string) ($_GET['code'] ?? ''), 'codigo_vencido')) {
        sale(400, ['error' => ['message' => 'This authorization code has expired', 'code' => 100]]);
    }
    sale(200, ['access_token' => 'EAAGnegocio' . substr(md5((string) $_GET['code']), 0, 20), 'token_type' => 'bearer']);
}
if (preg_match('#^/v[\d.]+/debug_token$#', $ruta)) {   // botón QR: qué cuenta autorizó el token
    if (($_GET['access_token'] ?? '') !== '2592670637899782|secreto_de_prueba') {
        sale(400, ['error' => ['message' => 'Invalid app token', 'code' => 190]]);
    }
    sale(200, ['data' => ['is_valid' => true, 'granular_scopes' => [
        ['scope' => 'business_management', 'target_ids' => ['111']],
        ['scope' => 'whatsapp_business_management', 'target_ids' => ['600']],
    ]]]);
}
if (preg_match('#^/v[\d.]+/(\d+)/phone_numbers$#', $ruta, $m)) {
    $nums = [
        '500' => [['id' => '502', 'display_phone_number' => '+593 98 000 0502', 'is_on_biz_app' => false],
                  ['id' => '501', 'display_phone_number' => '+593 99 640 8397', 'verified_name' => 'Tukán Software', 'is_on_biz_app' => true]],
        '600' => [['id' => '601', 'display_phone_number' => '+593 99 640 8301', 'verified_name' => 'Tukán Software', 'is_on_biz_app' => true]],
        '700' => [['id' => '701', 'display_phone_number' => '+593 99 640 8302', 'is_on_biz_app' => false]],
    ];
    sale(200, ['data' => $nums[$m[1]] ?? []]);
}
if (preg_match('#^/v[\d.]+/(\d+)/subscribed_apps$#', $ruta) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    sale(200, ['success' => true]);
}
if (preg_match('#^/v[\d.]+/(\d+)/register$#', $ruta) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = json_decode($cuerpo, true);
    preg_match('/^\d{6}$/', (string) ($d['pin'] ?? '')) ? sale(200, ['success' => true]) : sale(400, ['error' => ['message' => 'PIN', 'code' => 100]]);
}
if (preg_match('#^/v[\d.]+/(\d+)/smb_app_data$#', $ruta, $m) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($m[1] === '666') {
        sale(400, ['error' => ['message' => 'Sync window expired', 'code' => 100]]);
    }
    sale(200, ['messaging_product' => 'whatsapp', 'request_id' => 'REQ' . bin2hex(random_bytes(4))]);
}
// Archivos: subir (multipart), pedir dirección y bajar bytes. Los ids de archivo de prueba empiezan con 777.
if (preg_match('#^/v[\d.]+/(\d+)/media$#', $ruta) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $f = $_FILES['file'] ?? null;
    if (!$f || ($_POST['messaging_product'] ?? '') !== 'whatsapp' || ($_POST['type'] ?? '') === '') {
        sale(400, ['error' => ['message' => 'Falta el archivo', 'code' => 100]]);
    }
    file_put_contents($log, json_encode(['metodo' => 'SUBIDO', 'ruta' => '/subido', 'subido' => ['nombre' => $f['name'], 'tipo' => $_POST['type'], 'bytes' => $f['size']]]) . "\n", FILE_APPEND | LOCK_EX);
    sale(200, ['id' => '777' . random_int(100000, 999999)]);
}
if (preg_match('#^/v[\d.]+/(777\d+)$#', $ruta, $m)) {
    if ($m[1] === '7770000') {
        sale(404, ['error' => ['message' => 'Media not found', 'code' => 100]]);
    }
    $sim = 'http://' . $_SERVER['HTTP_HOST'];
    sale(200, ['url' => $sim . '/descarga/' . $m[1], 'mime_type' => 'image/jpeg', 'file_size' => 4, 'id' => $m[1]]);
}
if (preg_match('#^/descarga/(777\d+)$#', $ruta, $m)) {
    if ($auth === '') {
        http_response_code(401);
        exit;
    }
    header('Content-Type: image/jpeg');
    echo "\xFF\xD8\xFF\xE0" . 'JPEGPRUEBA' . $m[1];
    exit;
}
if (preg_match('#^/v[\d.]+/(\d+)/messages$#', $ruta, $m) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = json_decode($cuerpo, true);
    if (($d['status'] ?? '') === 'read') {
        sale(200, ['success' => true]);
    }
    if (str_ends_with((string) ($d['to'] ?? ''), '0000')) {
        sale(400, ['error' => ['message' => 'Recipient not on WhatsApp', 'code' => 131026]]);
    }
    if (str_ends_with((string) ($d['to'] ?? ''), '4747')) {
        sale(400, ['error' => ['message' => 'Re-engagement message', 'code' => 131047]]);
    }
    sale(200, ['messaging_product' => 'whatsapp', 'contacts' => [['input' => $d['to'], 'wa_id' => $d['to']]], 'messages' => [['id' => 'wamid.SIM' . bin2hex(random_bytes(6))]]]);
}
if (preg_match('#^/v[\d.]+/(\d+)/message_templates$#', $ruta)) {
    $archivo = $log . '.plantillas.json';
    $creadas = is_file($archivo) ? (json_decode((string) file_get_contents($archivo), true) ?: []) : [];
    $fijas = [
        ['id' => '1', 'name' => 'recordatorio_pago', 'language' => 'es', 'status' => 'APPROVED', 'category' => 'UTILITY',
         'components' => [['type' => 'BODY', 'text' => 'Hola {{1}}, tu plan vence el {{2}}.']]],
        ['id' => '2', 'name' => 'promo_octubre', 'language' => 'es', 'status' => 'APPROVED', 'category' => 'MARKETING',
         'components' => [['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'Oferta'], ['type' => 'BODY', 'text' => 'Este mes tenemos descuento en TuSalón.'],
                          ['type' => 'FOOTER', 'text' => 'Tukán'], ['type' => 'BUTTONS', 'buttons' => [['type' => 'QUICK_REPLY', 'text' => 'Me interesa']]]]],
        ['id' => '3', 'name' => 'en_revision', 'language' => 'es', 'status' => 'PENDING', 'category' => 'MARKETING',
         'components' => [['type' => 'BODY', 'text' => 'No debe salir']]],
        ['id' => '4', 'name' => 'rechazada_vieja', 'language' => 'es', 'status' => 'REJECTED', 'category' => 'MARKETING', 'rejected_reason' => 'PROMOTIONAL',
         'components' => [['type' => 'BODY', 'text' => 'Gana dinero fácil']]],
    ];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $d = json_decode($cuerpo, true) ?: [];
        foreach (array_merge($fijas, $creadas) as $p) {
            if ($p['name'] === ($d['name'] ?? '') && $p['language'] === ($d['language'] ?? '')) {
                sale(400, ['error' => ['message' => 'Invalid parameter', 'code' => 100, 'error_subcode' => 2388024]]);
            }
        }
        if (empty($d['name']) || empty($d['category']) || empty($d['components'])) {
            sale(400, ['error' => ['message' => 'Invalid parameter', 'code' => 100, 'error_user_msg' => 'Faltan datos']]);
        }
        $nueva = ['id' => (string) random_int(1000, 9999), 'name' => $d['name'], 'language' => $d['language'], 'category' => $d['category'],
                  'status' => str_starts_with($d['name'], 'auto_') ? 'APPROVED' : 'PENDING', 'components' => $d['components']];
        $creadas[] = $nueva;
        file_put_contents($archivo, json_encode($creadas));
        sale(200, ['id' => $nueva['id'], 'status' => $nueva['status'], 'category' => $d['category']]);
    }
    if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
        $nombre = (string) ($_GET['name'] ?? '');
        $antes = count($creadas);
        $creadas = array_values(array_filter($creadas, fn ($p) => $p['name'] !== $nombre));
        if ($antes === count($creadas) && !in_array($nombre, array_column($fijas, 'name'), true)) {
            sale(400, ['error' => ['message' => 'Template not found', 'code' => 100, 'error_user_msg' => 'No existe esa plantilla']]);
        }
        file_put_contents($archivo, json_encode($creadas));
        sale(200, ['success' => true]);
    }
    sale(200, ['data' => array_merge($fijas, $creadas)]);
}
if (preg_match('#^/v[\d.]+/(\d+)$#', $ruta, $m)) {
    sale(200, ['display_phone_number' => '+593 99 640 8397', 'verified_name' => 'Tukán Software', 'quality_rating' => 'GREEN', 'id' => $m[1] ?? '']);
}
sale(404, ['error' => ['message' => 'ruta desconocida ' . $ruta, 'code' => 100]]);
