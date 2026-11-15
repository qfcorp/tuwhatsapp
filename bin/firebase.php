<?php
declare(strict_types=1);

// (14 nov 2026) Clave de Firebase para los avisos nativos de la app Tukán Mensajes (lo usa deploy/configurar_firebase.sh):
//   php bin/firebase.php guardar < clave.json  → revisa la clave de la cuenta de servicio y la guarda cifrada. Imprime "PROYECTO=<id>".
//   php bin/firebase.php estado                → muestra si hay clave y cuántos celulares con la app reciben avisos.
//   php bin/firebase.php quitar                → borra la clave (la app deja de recibir avisos nativos).
require dirname(__DIR__) . '/app/bootstrap.php';

$orden = $argv[1] ?? '';
try {
    if ($orden === 'guardar') {
        $json = (string) stream_get_contents(STDIN, 20001);
        $g = \TuWhatsApp\Fcm::guardarClave(db(), $json);
        echo 'PROYECTO=' . $g['project_id'] . "\n" . 'CUENTA=' . $g['client_email'] . "\n";
        exit(0);
    }
    if ($orden === 'estado') {
        $e = \TuWhatsApp\Fcm::estadoClave(db());
        echo $e['configurado'] ? 'Clave de Firebase: proyecto ' . $e['project_id'] . ' (' . $e['client_email'] . ")\n" : "Clave de Firebase: NO configurada\n";
        echo 'Celulares con la app que reciben avisos: ' . $e['celulares'] . "\n";
        exit(0);
    }
    if ($orden === 'quitar') {
        \TuWhatsApp\Fcm::quitarClave(db());
        echo "Clave de Firebase quitada\n";
        exit(0);
    }
    fwrite(STDERR, "Uso: php bin/firebase.php guardar < clave.json | estado | quitar\n");
    exit(2);
} catch (\Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}
