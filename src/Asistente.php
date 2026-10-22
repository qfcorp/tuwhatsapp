<?php
declare(strict_types=1);

namespace TuWhatsApp;

use PDO;
use RuntimeException;

/**
 * Módulo "Asistente con IA" (Claude de Anthropic).
 *  - Sugerir respuesta: lee la conversación y lo que el negocio escribió en "Lo que sabe la IA" y deja un borrador
 *    en la caja del chat. Siempre lo revisa y lo manda una persona: la IA nunca contesta sola.
 *  - Resumir la conversación: qué quiere el cliente y cuál es el siguiente paso.
 * Cada cuenta tiene un tope de gasto al mes (empresas.ia_tope, por defecto $5): al llegar se detiene.
 *
 * Configuración (config/.env): TUWA_IA_CLAVE (clave de la API de Anthropic), TUWA_IA_MODELO (por defecto claude-haiku-5-5),
 * TUWA_IA_PRECIO_ENTRADA y TUWA_IA_PRECIO_SALIDA (dólares por millón de tokens, para calcular el gasto), TUWA_IA_URL (pruebas).
 */
final class Asistente
{
    public static function disponible(): bool
    {
        return (string) getenv('TUWA_IA_CLAVE') !== '';
    }

    public static function gastadoMes(PDO $db, int $empresaId): float
    {
        $st = $db->prepare("SELECT COALESCE(sum(costo), 0) FROM ia_uso WHERE empresa_id = ? AND creado >= date_trunc('month', now())");
        $st->execute([$empresaId]);
        return (float) $st->fetchColumn();
    }

    public static function guardarConocimiento(PDO $db, int $lineaId, string $texto): void
    {
        $texto = trim($texto);
        if (mb_strlen($texto) > 8000) {
            throw new RuntimeException('Es muy largo: máximo 8000 letras. Deja lo más importante (precios, horarios, preguntas frecuentes).');
        }
        $db->prepare('UPDATE lineas SET ia_conocimiento = ? WHERE id = ?')->execute([$texto, $lineaId]);
    }

    /** @return array{ok:bool, texto?:string, costo?:float, error?:string} */
    public static function sugerir(PDO $db, array $linea, array $contacto, ?int $usuarioId): array
    {
        $chat = self::conversacion($db, (int) $contacto['id']);
        if ($chat === '') {
            return ['ok' => false, 'error' => 'Todavía no hay mensajes para leer.'];
        }
        $sistema = self::sistema($linea) . "\n\nTu tarea: escribe la PRÓXIMA respuesta que el negocio le mandaría al cliente por WhatsApp. "
            . 'Solo el texto del mensaje, sin comillas ni explicaciones. Breve (1 a 4 frases), amable, en español, tuteando si el cliente tutea. '
            . 'Usa solo la información del negocio de arriba; si te preguntan algo que no está ahí (un precio, una fecha), di que lo confirmas enseguida. '
            . 'No inventes promociones ni datos.';
        return self::pedir($db, $linea, $usuarioId, 'sugerir', $sistema, "Conversación (la más reciente al final):\n" . $chat, 400);
    }

    /** @return array{ok:bool, texto?:string, costo?:float, error?:string} */
    public static function resumir(PDO $db, array $linea, array $contacto, ?int $usuarioId): array
    {
        $chat = self::conversacion($db, (int) $contacto['id'], 60);
        if ($chat === '') {
            return ['ok' => false, 'error' => 'Todavía no hay mensajes para leer.'];
        }
        $sistema = self::sistema($linea) . "\n\nTu tarea: resume la conversación para alguien del equipo que la ve por primera vez. "
            . 'En español, máximo 4 líneas cortas: qué quiere el cliente, qué se le ofreció o respondió, qué falta, y el siguiente paso recomendado.';
        return self::pedir($db, $linea, $usuarioId, 'resumir', $sistema, "Conversación:\n" . $chat, 300);
    }

    private static function sistema(array $linea): string
    {
        $c = trim((string) ($linea['ia_conocimiento'] ?? ''));
        return 'Ayudas al equipo de atención del negocio "' . $linea['nombre'] . '" que atiende a sus clientes por WhatsApp.'
            . "\n\nInformación del negocio (escrita por el negocio):\n" . ($c !== '' ? $c : '(el negocio todavía no escribió información)');
    }

    /** Los últimos mensajes como texto: "Cliente: …" / "Negocio: …" */
    private static function conversacion(PDO $db, int $contactoId, int $cuantos = 30): string
    {
        $st = $db->prepare('SELECT direccion, texto, creado FROM (SELECT * FROM mensajes WHERE contacto_id = ? ORDER BY creado DESC, id DESC LIMIT ' . (int) $cuantos . ') x ORDER BY creado, id');
        $st->execute([$contactoId]);
        $r = [];
        foreach ($st as $m) {
            $t = trim((string) $m['texto']);
            if ($t !== '') {
                $r[] = ($m['direccion'] === 'entra' ? 'Cliente: ' : 'Negocio: ') . mb_substr($t, 0, 1500);
            }
        }
        return implode("\n", $r);
    }

    private static function pedir(PDO $db, array $linea, ?int $usuarioId, string $que, string $sistema, string $usuario, int $maxSalida): array
    {
        if (!self::disponible()) {
            return ['ok' => false, 'error' => 'La IA todavía no está configurada en el servidor (falta TUWA_IA_CLAVE). Escribe a Tukán.'];
        }
        $empresaId = (int) $linea['empresa_id'];
        $empresa = Empresas::buscar($db, $empresaId);
        if (!Modulos::activo($empresa, 'ia')) {
            return ['ok' => false, 'error' => 'El asistente con IA no está incluido en tu plan.'];
        }
        $tope = (float) ($empresa['ia_tope'] ?? 5);
        if (self::gastadoMes($db, $empresaId) >= $tope) {
            return ['ok' => false, 'error' => 'Llegaste al tope de IA de este mes (' . '$' . number_format($tope, 2, ',', '.') . '). Se renueva el 1 del próximo mes o pide a Tukán subirlo.'];
        }
        $cuerpo = json_encode([
            'model' => (string) (getenv('TUWA_IA_MODELO') ?: 'claude-haiku-5-5'),
            'max_tokens' => $maxSalida,
            'system' => $sistema,
            'messages' => [['role' => 'user', 'content' => $usuario]],
        ], JSON_UNESCAPED_UNICODE);
        $ch = curl_init(rtrim((string) (getenv('TUWA_IA_URL') ?: 'https://api.anthropic.com'), '/') . '/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $cuerpo, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 45,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-api-key: ' . getenv('TUWA_IA_CLAVE'), 'anthropic-version: 2023-06-01'],
        ]);
        $resp = curl_exec($ch);
        $codigo = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $d = is_string($resp) ? json_decode($resp, true) : null;
        if ($codigo !== 200 || !is_array($d)) {
            error_log('tuwhatsapp ia: ' . $codigo . ' ' . mb_substr((string) $resp, 0, 300));
            return ['ok' => false, 'error' => $codigo === 429 || $codigo === 529 ? 'La IA está ocupada: intenta en un minuto.' : 'La IA no respondió. Intenta de nuevo en un momento.'];
        }
        $texto = '';
        foreach ((array) ($d['content'] ?? []) as $b) {
            if (($b['type'] ?? '') === 'text') {
                $texto .= (string) $b['text'];
            }
        }
        $entrada = (int) ($d['usage']['input_tokens'] ?? 0);
        $salida = (int) ($d['usage']['output_tokens'] ?? 0);
        $costo = round($entrada / 1e6 * (float) (getenv('TUWA_IA_PRECIO_ENTRADA') ?: 1) + $salida / 1e6 * (float) (getenv('TUWA_IA_PRECIO_SALIDA') ?: 5), 5);
        $db->prepare('INSERT INTO ia_uso (empresa_id, usuario_id, que, entrada, salida, costo) VALUES (?, ?, ?, ?, ?, ?)')
           ->execute([$empresaId, $usuarioId, $que, $entrada, $salida, $costo]);
        $texto = trim($texto);
        return $texto === '' ? ['ok' => false, 'error' => 'La IA no devolvió texto. Intenta de nuevo.'] : ['ok' => true, 'texto' => mb_substr($texto, 0, 4000), 'costo' => $costo];
    }
}
