<?php
declare(strict_types=1);

namespace TuWhatsApp;

use RuntimeException;

/** Guarda el token de Meta cifrado en la base, con la llave TUWA_LLAVE de config/.env (64 letras hex). */
final class Cifrado
{
    private static function llave(): string
    {
        $hex = trim((string) getenv('TUWA_LLAVE'));
        if (!preg_match('/^[0-9a-f]{64}$/i', $hex)) {
            throw new RuntimeException('Falta TUWA_LLAVE en config/.env (corre otra vez el instalador).');
        }
        return (string) hex2bin($hex);
    }

    public static function cifrar(string $texto): string
    {
        if ($texto === '') {
            return '';
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox($texto, $nonce, self::llave()));
    }

    public static function descifrar(string $guardado): string
    {
        if ($guardado === '') {
            return '';
        }
        $crudo = base64_decode($guardado, true);
        if ($crudo === false || strlen($crudo) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return '';
        }
        $nonce = substr($crudo, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $texto = sodium_crypto_secretbox_open(substr($crudo, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, self::llave());
        return $texto === false ? '' : $texto;
    }
}
