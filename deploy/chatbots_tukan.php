<?php
declare(strict_types=1);

/**
 * (23 nov 2026) Pone los 4 chatbots de ventas de Tukan Software (Pedir demo, Precios y planes, Soporte, Hablar con un asesor)
 * en 5 idiomas, encendidos, en el numero de Tukan. Ver src/ChatbotsTukan.php.
 *
 *   En el numero de Tukan (+593 98 366 2181):  sudo php /var/www/tuwhatsapp/deploy/chatbots_tukan.php
 *   En otro numero (el N. 3):                  sudo php /var/www/tuwhatsapp/deploy/chatbots_tukan.php 3
 * Si ya existen (mismo nombre), les vuelve a poner los textos de fabrica. No toca ningun otro chatbot.
 * Los mensajes van sin tildes a proposito (se leen bien por SSH desde el celular).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
define('TUWA_SIN_SESION', true);
require dirname(__DIR__) . '/app/bootstrap.php';

use TuWhatsApp\ChatbotsTukan;

try {
    $db = db();
    $lineas = $db->query('SELECT id, nombre, telefono FROM lineas ORDER BY id')->fetchAll();
    $id = (int) ($argv[1] ?? 0);
    if ($id === 0) {   // sin numero: el de Tukan (+593 98 366 2181), o el unico que haya
        $tukan = array_values(array_filter($lineas, fn ($l) => str_ends_with(preg_replace('/\D/', '', (string) $l['telefono']), '983662181')));
        $id = count($tukan) === 1 ? (int) $tukan[0]['id'] : (count($lineas) === 1 ? (int) $lineas[0]['id'] : 0);
    }
    if ($id === 0) {
        echo "\nNumeros de WhatsApp en el programa:\n";
        foreach ($lineas as $l) {
            echo "  N. {$l['id']}  {$l['nombre']}  {$l['telefono']}\n";
        }
        echo "\nEscribe el mismo comando con el numero (N.) del de Tukan al final. Ejemplo:\n";
        echo "  sudo php /var/www/tuwhatsapp/deploy/chatbots_tukan.php " . ($lineas[0]['id'] ?? 1) . "\n\n";
        exit(1);
    }
    $r = ChatbotsTukan::instalar($db, $id);
    echo "\nListo: chatbots en el numero N. $id (encendidos, en espanol, ingles, portugues, frances e italiano).\n";
    foreach ($r['creados'] as $n) {
        echo "  Nuevo:        $n\n";
    }
    foreach ($r['actualizados'] as $n) {
        echo "  Actualizado:  $n\n";
    }
    echo "  PDF de planes puestos en los pasos: {$r['pdf']}\n";
    foreach ($r['faltan'] as $n) {
        echo "  AVISO: falta el PDF $n en deploy/pdf_planes (ese paso sale sin PDF)\n";
    }
    echo "\nRevisalos en whatsapp.tukanec.com -> Chatbots.\n\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "\nNo se pudo: " . $e->getMessage() . "\n");
    exit(1);
}
