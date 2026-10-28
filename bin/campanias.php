<?php
declare(strict_types=1);

// Envía el siguiente grupo de las campañas activas, los seguimientos que ya tocan y los recordatorios de carritos abandonados de la tienda. Lo corre el cron cada minuto (lo pone deploy/instalar.sh):
//   * * * * * php /var/www/tuwhatsapp/bin/campanias.php
require dirname(__DIR__) . '/app/bootstrap.php';

$r = \TuWhatsApp\Campanias::procesar(db());
if ($r['enviados'] + $r['fallidos'] + $r['pausadas'] > 0) {
    echo date('Y-m-d H:i') . " enviados {$r['enviados']}, fallidos {$r['fallidos']}, pausadas {$r['pausadas']}\n";
}

$s = \TuWhatsApp\Seguimientos::procesar(db());
if (array_sum($s) > 0) {
    echo date('Y-m-d H:i') . " seguimientos: enviados {$s['enviado']}, a pendientes {$s['pendiente']}, omitidos {$s['omitido']}, fallidos {$s['fallido']}\n";
}

$t = \TuWhatsApp\Tiendas::procesarCarritos(db());
if (array_sum($t) > 0) {
    echo date('Y-m-d H:i') . " carritos de la tienda: avisados {$t['avisado']}, omitidos {$t['omitido']}, fallidos {$t['fallido']}\n";
}
