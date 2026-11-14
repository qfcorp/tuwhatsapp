<?php
declare(strict_types=1);

// Bot de avisos de WhatsApp Tukán (lo usa deploy/configurar_telegram.sh):
//   php bin/telegram.php conectar   → revisa TUWA_TELEGRAM_TOKEN con getMe y registra el webhook https://<TUWA_URL>/telegram.php
//                                     con TUWA_TELEGRAM_SECRETO. Imprime "BOT=<nombre del bot>" si todo sale bien.
//   php bin/telegram.php estado     → muestra si el bot está configurado y cuántas personas lo tienen conectado.
//   php bin/telegram.php cambio_bot → cambió el bot: desconecta a todos y quita el webhook del bot viejo (su token llega por la
//                                     entrada estándar, así no se ve en la lista de procesos). Imprime "DESCONECTADOS=<n>".
require dirname(__DIR__) . '/app/bootstrap.php';

$orden = $argv[1] ?? '';
if ($orden === 'conectar') {
    try {
        $bot = \TuWhatsApp\Telegram::conectarBot(\TuWhatsApp\Telegram::token(), \TuWhatsApp\Telegram::secreto(), \TuWhatsApp\Telegram::base());
        echo "BOT=$bot\n";
        exit(0);
    } catch (\Throwable $e) {
        fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
        exit(1);
    }
}
if ($orden === 'cambio_bot') {
    $viejo = trim((string) stream_get_contents(STDIN));
    echo 'DESCONECTADOS=' . \TuWhatsApp\Telegram::cambioDeBot(db(), $viejo) . "\n";
    exit(0);
}
if ($orden === 'estado') {
    echo 'Bot: ' . (\TuWhatsApp\Telegram::configurado() ? '@' . \TuWhatsApp\Telegram::bot() : 'NO configurado') . "\n";
    echo 'Personas con Telegram conectado: ' . (int) db()->query('SELECT count(*) FROM usuarios WHERE activo AND tg_chat_id IS NOT NULL')->fetchColumn() . "\n";
    exit(0);
}
fwrite(STDERR, "Uso: php bin/telegram.php conectar|estado|cambio_bot\n");
exit(2);
