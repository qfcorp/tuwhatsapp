<?php
declare(strict_types=1);

namespace TuWhatsApp;

use PDO;

/** Conexión a PostgreSQL con los datos de config/.env (TUWA_DB_HOST, _PORT, _NAME, _USER, _PASS). */
final class Db
{
    public static function conectar(): PDO
    {
        $host = getenv('TUWA_DB_HOST') ?: '127.0.0.1';
        $port = getenv('TUWA_DB_PORT') ?: '5432';
        $name = getenv('TUWA_DB_NAME') ?: 'tuwhatsapp';
        $user = getenv('TUWA_DB_USER') ?: 'tuwhatsapp';
        $pass = getenv('TUWA_DB_PASS') ?: '';
        $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$name", $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec("SET TIME ZONE 'America/Guayaquil'");
        return $pdo;
    }
}
