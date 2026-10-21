<?php
declare(strict_types=1);

namespace TuWhatsApp;

use PDO;

/**
 * Términos y condiciones y acuerdo de tratamiento de datos (public/terminos.php y public/tratamiento-datos.php).
 * El administrador de cada cliente los acepta una vez por versión; si cambian, se sube VERSION y se vuelven a pedir.
 * La cuenta de Tukán (empresa 1) no los necesita.
 */
final class Terminos
{
    public const VERSION = '2026-10-21';

    public static function aceptados(?array $empresa): bool
    {
        return $empresa === null || (int) $empresa['id'] === Empresas::TUKAN || ($empresa['terminos_version'] ?? '') === self::VERSION;
    }

    public static function aceptar(PDO $db, int $empresaId, int $usuarioId, string $ip): void
    {
        $db->prepare('UPDATE empresas SET terminos_version = ?, terminos_aceptados_en = now(), terminos_aceptado_por = ?, terminos_ip = ? WHERE id = ?')
           ->execute([self::VERSION, $usuarioId, mb_substr($ip, 0, 45), $empresaId]);
    }
}
