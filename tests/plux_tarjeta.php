<?php
declare(strict_types=1);

// SOLO PRUEBAS: hace lo mismo que public/assets/plux.js en el navegador (cifra la tarjeta) y escribe el JSON para /pagar.php.
// Uso: php tests/plux_tarjeta.php <llave_publica.pem> <token del pedido> <csrf> <número de tarjeta> [código OTP] [cédula]
[, $pub, $tok, $csrf, $num] = $argv;
$otp = $argv[5] ?? '';
$ced = $argv[6] ?? '1712345678';
$k = substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(48))), 0, 32);
$aes = fn (string $t) => openssl_encrypt($t, 'aes-256-ecb', $k);   // base64, igual que CryptoJS
openssl_public_encrypt($k, $kc, (string) file_get_contents($pub), OPENSSL_PKCS1_PADDING);
$d = ['p' => $tok, 'csrf' => $csrf,
      'card' => ['number' => $aes($num), 'expirationYear' => $aes((string) ((int) date('Y') + 2)), 'expirationMonth' => $aes('07'), 'cvv' => $aes('123')],
      'buyer' => ['documentNumber' => $ced, 'firstName' => 'Ana', 'lastName' => 'Paredes', 'phone' => '0991111111', 'email' => 'ana@ejemplo.com'],
      'simetricKeyCifrada' => base64_encode($kc)];
if ($otp !== '') {
    $d['paramsOtp'] = ['idTransaction' => 'OTP-1', 'sessionId' => 'S-1', 'tkn' => 'a', 'tknky' => 'b', 'tkniv' => 'c', 'otpCode' => $otp];
}
echo json_encode($d);
