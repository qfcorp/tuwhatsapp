<?php
// SOLO PRUEBAS: servidor FALSO de Pago Plux (copiado de TuPass; se levanta con "php -S ... tests/plux_falso.php"). Nunca se llama a Plux de verdad.
// Revisa lo mismo que revisaría Plux: Basic Auth, la clave simétrica cifrada con RSA, la tarjeta cifrada con AES-256-ECB,
// el idEstablecimiento en base64, que urlRetorno3ds NO tenga parámetros y el valor final. Anota cada revisión en FALSO_LOG
// (sin datos de tarjeta: solo los 4 últimos números).
// Tarjetas: 4540639936908783 aprobada (0) · 4111111111111111 rechazada (2) · 36417200103608 pide OTP 123456 (100/102/0)
//           5555555555554444 pide 3DS (103) · cualquier otra: error (1)
$E = fn(string $k) => (string) getenv($k);
$ruta = (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
$json = function (int $http, array $d): void { http_response_code($http); header('Content-Type: application/json'); echo json_encode($d); };
$anotar = function (array $d) use ($E): void { file_put_contents($E('FALSO_LOG'), json_encode($d) . "\n", FILE_APPEND | LOCK_EX); };

// La página "del banco" (3DS): vuelve a urlRetorno3ds agregando SUS parámetros con "?" (como hace Plux)
if ($ruta === '/banco') {
    header('Location: ' . $_GET['ret'] . '?pti=PTI-1&pcc=PCC-1&ptk=TK-' . preg_replace('/\W/', '', (string) $_GET['ref']), true, 302);
    return true;
}
$auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
$authOk = hash_equals('Basic ' . base64_encode($E('FALSO_ID_CLIENTE') . ':' . $E('FALSO_CLAVE')), $auth);
$in = json_decode((string) file_get_contents('php://input'), true) ?: [];

if ($ruta === '/intv1/integrations/dataTransactionThreeDsResource') {
    $ok = $authOk && str_starts_with((string) ($in['ptk'] ?? ''), 'TK-') && ($in['pti'] ?? '') === 'PTI-1' && ($in['pcc'] ?? '') === 'PCC-1';
    $anotar(['ruta' => '3ds', 'auth_ok' => $authOk, 'code' => $ok ? 0 : 2]);
    $json(200, $ok ? ['code' => 0, 'description' => 'Aprobada con 3DS', 'detail' => ['id_transaccion' => 'FALSO3DS-' . substr((string) $in['ptk'], 3)]]
                   : ['code' => 2, 'description' => 'Validación 3DS fallida']);
    return true;
}
if ($ruta !== '/intv1/credentials/paymentCardResource' || $_SERVER['REQUEST_METHOD'] !== 'POST') { $json(404, ['code' => 1, 'description' => 'ruta']); return true; }

$priv = openssl_pkey_get_private((string) file_get_contents($E('FALSO_LLAVE_PRIVADA')));
$clave = '';
$claveOk = openssl_private_decrypt((string) base64_decode((string) ($_SERVER['HTTP_SIMETRICKEY'] ?? '')), $clave, $priv) && strlen($clave) === 32;
$des = fn($v) => $claveOk ? openssl_decrypt((string) $v, 'aes-256-ecb', $clave) : false;   // base64 de CryptoJS
$c = is_array($in['card'] ?? null) ? $in['card'] : [];
$num = $des($c['number'] ?? ''); $mes = $des($c['expirationMonth'] ?? ''); $anio = $des($c['expirationYear'] ?? ''); $cvv = $des($c['cvv'] ?? '');
$tarjetaOk = is_string($num) && preg_match('/^\d{13,19}$/', $num) && preg_match('/^\d{2}$/', (string) $mes) && preg_match('/^\d{4}$/', (string) $anio) && preg_match('/^\d{3,4}$/', (string) $cvv);
$retorno = (string) ($in['urlRetorno3ds'] ?? '');
$monto = round((float) ($in['baseAmount0'] ?? 0) + (float) ($in['baseAmount12'] ?? 0), 2);
$otp = is_array($in['paramsOtp'] ?? null) ? $in['paramsOtp'] : null;
$num = $tarjetaOk ? $num : '';
$code = match (true) {
    !$authOk => 401,
    !$claveOk || !$tarjetaOk => 1,
    $num === '4540639936908783' => 0,
    $num === '4111111111111111' => 2,
    $num === '36417200103608' => $otp === null ? 100 : (($otp['otpCode'] ?? '') === '123456' && ($otp['idTransaction'] ?? '') === 'OTP-1' ? 0 : 102),
    $num === '5555555555554444' => 103,
    default => 1,
};
$anotar(['ruta' => 'pago', 'auth_ok' => $authOk, 'clave_ok' => $claveOk, 'tarjeta_ok' => (bool) $tarjetaOk, 'ultimos4' => substr($num, -4),
    'establecimiento_ok' => base64_decode((string) ($in['idEstablecimiento'] ?? '')) === $E('FALSO_ESTABLECIMIENTO'),
    'retorno' => $retorno, 'retorno_limpio' => $retorno !== '' && !str_contains($retorno, '?'), 'monto' => $monto,
    'con_iva' => (float) ($in['baseAmount12'] ?? 0) > 0, 'moneda' => $in['currency'] ?? null, 'descripcion' => $in['description'] ?? '',
    'documento' => $in['buyer']['documentNumber'] ?? '', 'otp' => $otp !== null, 'code' => $code]);
$ref = bin2hex(random_bytes(4));
match ($code) {
    401 => $json(401, ['code' => 1, 'description' => 'No autorizado']),
    0 => $json(200, ['code' => 0, 'description' => 'Transacción aprobada', 'detail' => ['id_transaccion' => 'FALSO-' . $ref, 'cardNumber' => substr($num, 0, 6) . '******' . substr($num, -4),
        'cardType' => 'credit', 'amount' => $monto, 'texto' => 'tarjeta ' . $num]]),
    2 => $json(200, ['code' => 2, 'description' => 'Transacción rechazada', 'detail' => 'Fondos insuficientes']),
    100 => $json(200, ['code' => 100, 'description' => 'Requiere OTP', 'detail' => ['idTransaction' => 'OTP-1', 'sessionId' => 'S-1', 'tkn' => 'a', 'tknky' => 'b', 'tkniv' => 'c']]),
    102 => $json(200, ['code' => 102, 'description' => 'OTP incorrecto', 'detail' => ['idTransaction' => 'OTP-1', 'sessionId' => 'S-1', 'tkn' => 'a', 'tknky' => 'b', 'tkniv' => 'c']]),
    103 => $json(200, ['code' => 103, 'description' => 'Requiere 3DS', 'detail' => ['url' => $E('FALSO_BASE') . '/banco?ref=' . $ref . '&ret=' . rawurlencode($retorno), 'parameters' => [], 'customFormChallenge' => false]]),
    default => $json(200, ['code' => 1, 'description' => 'Tarjeta no válida']),
};
return true;
