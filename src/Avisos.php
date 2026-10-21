<?php
declare(strict_types=1);

namespace TuWhatsApp;

use DateTimeImmutable;
use PDO;

/**
 * Avisos de gasto: al llegar al porcentaje elegido (ej. 80 %) y al 100 % del tope del mes.
 * Siempre se ven arriba en el panel; además llegan por Telegram si config/.env tiene TELEGRAM_BOT_TOKEN y TELEGRAM_CHAT_ID.
 * Cada aviso se manda una sola vez por mes.
 */
final class Avisos
{
    /** @return list<int> niveles avisados ahora */
    public static function revisar(PDO $db, array $linea, ?DateTimeImmutable $ahora = null): array
    {
        $ahora ??= new DateTimeImmutable();
        $tope = (float) $linea['tope_mensual'];
        if ($tope <= 0) {
            return [];
        }
        $gastado = Gasto::delMes($db, (int) $linea['id'], $ahora);
        $mes = $ahora->format('Y-m-01');
        $avisados = [];
        foreach (array_unique([(int) $linea['aviso_pct'], 100]) as $nivel) {
            if ($gastado + 1e-9 < $tope * $nivel / 100) {
                continue;
            }
            $st = $db->prepare('INSERT INTO avisos_gasto (linea_id, mes, nivel) VALUES (?, ?, ?) ON CONFLICT DO NOTHING');
            $st->execute([$linea['id'], $mes, $nivel]);
            if ($st->rowCount() === 0) {
                continue;
            }
            $avisados[] = $nivel;
            $texto = $nivel >= 100
                ? "WhatsApp {$linea['nombre']}: llegaste al tope del mes (" . self::d($gastado) . ' de ' . self::d($tope) . '). Ya NO se envía nada pagado; lo que falte mándalo gratis desde el celular o sube el tope en Gasto.'
                : "WhatsApp {$linea['nombre']}: llevas el $nivel % del tope del mes (" . self::d($gastado) . ' de ' . self::d($tope) . ').';
            self::telegram($texto);
        }
        return $avisados;
    }

    public static function telegram(string $texto): bool
    {
        $token = trim((string) getenv('TELEGRAM_BOT_TOKEN'));
        $chat = trim((string) getenv('TELEGRAM_CHAT_ID'));
        if ($token === '' || $chat === '') {
            return false;
        }
        $base = rtrim((string) (getenv('TELEGRAM_API_URL') ?: 'https://api.telegram.org'), '/');
        $ch = curl_init($base . '/bot' . $token . '/sendMessage');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['chat_id' => $chat, 'text' => $texto]),
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
        ]);
        $ok = curl_exec($ch) !== false && curl_getinfo($ch, CURLINFO_RESPONSE_CODE) === 200;
        curl_close($ch);
        return $ok;
    }

    private static function d(float $v): string
    {
        return '$' . number_format($v, 2, ',', '.');
    }
}
