<?php
declare(strict_types=1);

// Envía el siguiente grupo de las campañas activas, los seguimientos que ya tocan, los recordatorios de carritos abandonados de la tienda, el resumen de cada mañana y los avisos por correo de chats sin contestar. Lo corre el cron cada minuto (lo pone deploy/instalar.sh):
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

$n = \TuWhatsApp\Copiloto::procesarResumenes(db());
if ($n > 0) {
    echo date('Y-m-d H:i') . " resúmenes de la mañana: $n\n";
}

// Respaldo por correo de los avisos: chats de clientes que nadie leyó en N minutos (src/AvisoCorreo.php)
$c = \TuWhatsApp\AvisoCorreo::procesar(db());
if ($c['correos'] + $c['fallidos'] > 0) {
    echo date('Y-m-d H:i') . " avisos por correo: enviados {$c['correos']}, fallidos {$c['fallidos']}\n";
}

// (14 nov 2026) Avisos por Telegram de quien eligió "solo si nadie contesta en N minutos" (src/Telegram.php)
$tg = \TuWhatsApp\Telegram::procesarPendientes(db());
if ($tg['mensajes'] + $tg['fallidos'] > 0) {
    echo date('Y-m-d H:i') . " avisos por Telegram: enviados {$tg['mensajes']}, fallidos {$tg['fallidos']}\n";
}

// (14 nov 2026) Una vez al día: borrar los «Recordar este celular» vencidos (src/Sesiones.php)
\TuWhatsApp\Sesiones::limpiarAlDia(db());

// (14 nov 2026) Avisos nativos de la app, una vez al día: registros por IP viejos y celulares nunca vinculados de más de 7 días
\TuWhatsApp\Fcm::limpiar(db());
