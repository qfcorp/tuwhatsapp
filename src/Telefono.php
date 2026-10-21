<?php
declare(strict_types=1);

namespace TuWhatsApp;

/** Números en el formato de WhatsApp: solo dígitos con el código del país (593991234567). */
final class Telefono
{
    /** 0991234567 / +593 99 123 4567 / 593991234567 -> 593991234567. Null si no parece un número. */
    public static function normalizar(?string $tel): ?string
    {
        $d = preg_replace('/\D+/', '', (string) $tel);
        if ($d === '' || $d === null) {
            return null;
        }
        if (str_starts_with($d, '593') && strlen($d) === 12) {
            return $d;
        }
        if (str_starts_with($d, '0') && strlen($d) === 10) {
            return '593' . substr($d, 1);
        }
        if (strlen($d) === 9 && $d[0] === '9') {
            return '593' . $d;
        }
        return (strlen($d) >= 10 && strlen($d) <= 15) ? $d : null;
    }

    /** 593991234567 -> 099 123 4567 (los de Ecuador); los demás con + delante. */
    public static function bonito(string $tel): string
    {
        if (str_starts_with($tel, '593') && strlen($tel) === 12) {
            $l = '0' . substr($tel, 3);
            return substr($l, 0, 3) . ' ' . substr($l, 3, 3) . ' ' . substr($l, 6);
        }
        return '+' . $tel;
    }

    /** Enlace que abre WhatsApp en el celular con el mensaje ya escrito (gratis). */
    public static function enlace(string $tel, string $texto = ''): string
    {
        $n = self::normalizar($tel) ?? preg_replace('/\D+/', '', $tel);
        return 'https://wa.me/' . $n . ($texto !== '' ? '?text=' . rawurlencode($texto) : '');
    }
}
