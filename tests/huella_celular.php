<?php
declare(strict_types=1);

/**
 * Celular SIMULADO para las pruebas de pantallas de «Entrar con huella» (WebAuthn). Nunca se usa en el servidor.
 * Uso: php tests/huella_celular.php registro|entrar <carpeta> <desafío base64url> <rpId> <origen> [contador]
 * Guarda la llave y el id de la credencial en <carpeta> y escribe el JSON que mandaría el navegador.
 */
require dirname(__DIR__) . '/src/Huella.php';

use TuWhatsApp\Huella;

[$_, $modo, $dir, $desafio, $rp, $origen] = $argv + [5 => ''];
$contador = (int) ($argv[6] ?? 1);
$desafio = Huella::deB64u($desafio);
if (!is_file("$dir/llave.pem")) {
    $k = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    openssl_pkey_export($k, $pem);
    file_put_contents("$dir/llave.pem", $pem);
    file_put_contents("$dir/cred", random_bytes(32));
}
$k = openssl_pkey_get_private(file_get_contents("$dir/llave.pem"));
$cred = file_get_contents("$dir/cred");
if ($modo === 'registro') {
    $d = openssl_pkey_get_details($k);
    $cose = [1 => 2, 3 => -7, -1 => 1, -2 => str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT), -3 => str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT)];
    $auth = hash('sha256', $rp, true) . chr(0x45) . pack('N', 0) . str_repeat("\0", 16) . pack('n', strlen($cred)) . $cred . Huella::cborEscribir($cose);
    $cd = json_encode(['type' => 'webauthn.create', 'challenge' => Huella::b64u($desafio), 'origin' => $origen]);
    echo json_encode(['id' => Huella::b64u($cred), 'clientDataJSON' => Huella::b64u($cd),
                      'attestationObject' => Huella::b64u(Huella::cborEscribir(['fmt' => ['t' => 'none'], 'attStmt' => [], 'authData' => $auth]))]);
} else {
    $auth = hash('sha256', $rp, true) . chr(0x05) . pack('N', $contador);
    $cd = json_encode(['type' => 'webauthn.get', 'challenge' => Huella::b64u($desafio), 'origin' => $origen]);
    openssl_sign($auth . hash('sha256', $cd, true), $firma, $k, OPENSSL_ALGO_SHA256);
    if ($modo === 'mala') {
        $firma[10] = chr(ord($firma[10]) ^ 1);
    }
    echo json_encode(['id' => Huella::b64u($cred), 'clientDataJSON' => Huella::b64u($cd), 'authenticatorData' => Huella::b64u($auth),
                      'signature' => Huella::b64u($firma), 'userHandle' => '']);
}
