<?php
declare(strict_types=1);

/**
 * Firebase Cloud Messaging y OAuth de Google SIMULADOS para las pruebas (nunca habla con Google). Se levanta con php -S (tests/correr.sh).
 * Guarda cada llamada en FCM_LOG (una línea JSON).
 *  - POST /token: revisa el JWT (firma RS256 con la llave pública FCM_SIM_PUBLICA, iss = FCM_SIM_CORREO, scope de FCM, aud, vencimiento)
 *    y da un token de acceso "acceso-<n>".
 *  - POST /v1/projects/<proyecto>/messages:send: pide "Authorization: Bearer acceso-…". Tokens especiales del celular:
 *    token-invalido → 404 UNREGISTERED · token-malo → 400 INVALID_ARGUMENT (token no válido) · token-500 → 500 · token-lento → tarda 5 s
 *    · token-401 → 401 (token de acceso vencido).
 */
$ruta = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$log = (string) getenv('FCM_LOG');
$anotar = function (array $x) use ($log): void {
    file_put_contents($log, json_encode($x + ['t' => microtime(true)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
};
$b64d = fn (string $t): string => (string) base64_decode(strtr($t, '-_', '+/') . str_repeat('=', (4 - strlen($t) % 4) % 4));
header('Content-Type: application/json');

if ($ruta === '/token' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $jwt = (string) ($_POST['assertion'] ?? '');
    $partes = explode('.', $jwt);
    $bien = count($partes) === 3 && ($_POST['grant_type'] ?? '') === 'urn:ietf:params:oauth:grant-type:jwt-bearer';
    $datos = $bien ? json_decode($b64d($partes[1]), true) : null;
    $cab = $bien ? json_decode($b64d($partes[0]), true) : null;
    $firmaOk = $bien && openssl_verify($partes[0] . '.' . $partes[1], $b64d($partes[2]), (string) file_get_contents((string) getenv('FCM_SIM_PUBLICA')), OPENSSL_ALGO_SHA256) === 1;
    $ok = $firmaOk && is_array($datos) && ($cab['alg'] ?? '') === 'RS256'
          && ($datos['iss'] ?? '') === getenv('FCM_SIM_CORREO')
          && ($datos['scope'] ?? '') === 'https://www.googleapis.com/auth/firebase.messaging'
          && str_ends_with((string) ($datos['aud'] ?? ''), '/token')
          && (int) ($datos['exp'] ?? 0) > time() && (int) ($datos['iat'] ?? 0) <= time() + 5;
    $anotar(['tipo' => 'token', 'ok' => $ok, 'firma' => $firmaOk]);
    if (!$ok) {
        http_response_code(400);
        echo '{"error":"invalid_grant","error_description":"Invalid JWT Signature."}';
        return;
    }
    $n = substr_count((string) @file_get_contents($log), '"tipo":"token","ok":true');
    echo json_encode(['access_token' => 'acceso-' . $n, 'expires_in' => 3599, 'token_type' => 'Bearer']);
    return;
}

if (preg_match('#^/v1/projects/([^/]+)/messages:send$#', $ruta, $m) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    $cuerpo = json_decode((string) file_get_contents('php://input'), true) ?: [];
    $token = (string) ($cuerpo['message']['token'] ?? '');
    $anotar(['tipo' => 'envio', 'proyecto' => $m[1], 'auth' => $auth, 'mensaje' => $cuerpo['message'] ?? null]);
    if (!str_starts_with($auth, 'Bearer acceso-') || $token === 'token-401') {
        http_response_code(401);
        echo '{"error":{"code":401,"message":"Request had invalid authentication credentials.","status":"UNAUTHENTICATED"}}';
        return;
    }
    if ($token === 'token-invalido') {
        http_response_code(404);
        echo '{"error":{"code":404,"message":"Requested entity was not found.","status":"NOT_FOUND","details":[{"@type":"type.googleapis.com/google.firebase.fcm.v1.FcmError","errorCode":"UNREGISTERED"}]}}';
        return;
    }
    if ($token === 'token-malo') {
        http_response_code(400);
        echo '{"error":{"code":400,"message":"The registration token is not a valid FCM registration token","status":"INVALID_ARGUMENT","details":[{"@type":"type.googleapis.com/google.firebase.fcm.v1.FcmError","errorCode":"INVALID_ARGUMENT"}]}}';
        return;
    }
    if ($token === 'token-500') {
        http_response_code(500);
        echo '{"error":{"code":500,"message":"Internal error","status":"INTERNAL"}}';
        return;
    }
    if ($token === 'token-lento') {
        sleep(5);
    }
    echo json_encode(['name' => 'projects/' . $m[1] . '/messages/' . random_int(1, 999999)]);
    return;
}

http_response_code(404);
echo '{"error":{"code":404,"status":"NOT_FOUND"}}';
