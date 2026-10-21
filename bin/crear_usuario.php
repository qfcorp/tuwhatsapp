<?php
declare(strict_types=1);

// Crea o cambia un usuario del panel. Uso en el servidor:  php /var/www/tuwhatsapp/bin/crear_usuario.php
// (o sin preguntas: php crear_usuario.php correo@x.com "Nombre" "contraseña")
require dirname(__DIR__) . '/app/bootstrap.php';

function preguntar(string $q, bool $oculto = false): string
{
    echo $q;
    if ($oculto && DIRECTORY_SEPARATOR === '/') {
        system('stty -echo 2>/dev/null');
        $r = trim((string) fgets(STDIN));
        system('stty echo 2>/dev/null');
        echo "\n";
        return $r;
    }
    return trim((string) fgets(STDIN));
}

$correo = $argv[1] ?? preguntar('Correo para entrar: ');
$nombre = $argv[2] ?? preguntar('Tu nombre: ');
$clave = $argv[3] ?? preguntar('Contraseña (mínimo 8, no se ve al escribir): ', true);
if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "ERROR: el correo no es valido.\n");
    exit(1);
}
if (mb_strlen($clave) < 8) {
    fwrite(STDERR, "ERROR: la contrasena debe tener al menos 8 letras o numeros.\n");
    exit(1);
}
$st = db()->prepare('INSERT INTO usuarios (correo, nombre, clave) VALUES (lower(?), ?, ?)
                     ON CONFLICT (correo) DO UPDATE SET nombre = EXCLUDED.nombre, clave = EXCLUDED.clave RETURNING id');
$st->execute([$correo, $nombre !== '' ? $nombre : $correo, password_hash($clave, PASSWORD_DEFAULT)]);
echo "LISTO: ya puedes entrar con $correo\n";
