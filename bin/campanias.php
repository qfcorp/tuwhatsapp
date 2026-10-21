<?php
declare(strict_types=1);

// Envía el siguiente grupo de las campañas activas. Lo corre el cron cada minuto (lo pone deploy/instalar.sh):
//   * * * * * php /var/www/tuwhatsapp/bin/campanias.php
require dirname(__DIR__) . '/app/bootstrap.php';

$r = \TuWhatsApp\Campanias::procesar(db());
if ($r['enviados'] + $r['fallidos'] + $r['pausadas'] > 0) {
    echo date('Y-m-d H:i') . " enviados {$r['enviados']}, fallidos {$r['fallidos']}, pausadas {$r['pausadas']}\n";
}
