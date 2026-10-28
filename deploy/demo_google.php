<?php
declare(strict_types=1);

/**
 * Cuenta de MUESTRA para que Google revise la app de Android "Tukan Mensajes" sin ver datos reales.
 * Arma la empresa "Tienda Muestra Tukan" (datos inventados, numero de ficcion que nunca se conecta a Meta, plan Todo incluido sin IA)
 * con el administrador revisor.google@tukanec.com. No toca ninguna otra cuenta. Ver src/DemoGoogle.php.
 *
 *   Crear (o volver a crear con fechas de hoy y contrasena nueva):  php /var/www/tuwhatsapp/deploy/demo_google.php crear
 *   Borrar (cuando Google ya aprobo la app):                        php /var/www/tuwhatsapp/deploy/demo_google.php borrar
 * La contrasena sale UNA sola vez: copiala en Play Console -> Contenido de la app -> Acceso a la app.
 * Los mensajes van sin tildes a proposito (se leen bien por SSH desde el celular).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
define('TUWA_SIN_SESION', true);
require dirname(__DIR__) . '/app/bootstrap.php';

use TuWhatsApp\DemoGoogle;

$accion = $argv[1] ?? '';
try {
    if ($accion === 'crear') {
        $r = DemoGoogle::crear(db());
        echo "\nListo: Tienda Muestra Tukan (cuenta N. {$r['empresa_id']}, de muestra hasta el {$r['vence']}) con {$r['contactos']} contactos,";
        echo " {$r['productos']} productos y {$r['pedidos']} pedidos de ejemplo.\n";
        echo "Datos inventados. Su numero no es de verdad: nunca envia nada a WhatsApp (Meta). No toca ninguna otra cuenta.\n";
        echo "\nCopia esto en Google Play Console -> Contenido de la app -> Acceso a la app:\n";
        echo "  Usuario:    " . DemoGoogle::CORREO . "\n";
        echo "  Contrasena: " . $r['clave'] . "\n";
        echo "\n(La contrasena no se guarda en ningun lado: si la pierdes, corre otra vez 'crear'.)\n";
        echo "Pruebala entrando en " . (getenv('TUWA_URL') ?: 'https://whatsapp.tukanec.com') . "\n\n";
        exit(0);
    }
    if ($accion === 'borrar') {
        echo DemoGoogle::borrar(db())
            ? "Listo: se borro la Tienda Muestra Tukan, sus datos y el usuario " . DemoGoogle::CORREO . ".\n"
            : "No habia cuenta de muestra (" . DemoGoogle::CORREO . "). No se cambio nada.\n";
        exit(0);
    }
} catch (Throwable $e) {
    fwrite(STDERR, "\nNo se pudo: " . $e->getMessage() . "\n");
    exit(1);
}
echo "Uso: php /var/www/tuwhatsapp/deploy/demo_google.php crear | borrar\n";
exit(1);
