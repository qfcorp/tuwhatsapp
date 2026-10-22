<?php
declare(strict_types=1);

namespace TuWhatsApp;

use RuntimeException;

/**
 * Arma una plantilla nueva para Meta a partir del formulario del panel, revisando las reglas de Meta
 * antes de enviarla (así el error se ve en palabras simples y no se gasta una revisión).
 * Reglas: nombre en minúsculas con _, cuerpo hasta 1024 letras, variables {{1}}, {{2}}... en orden y con ejemplo,
 * una variable no puede ir al inicio ni al final, encabezado y pie hasta 60 letras (sin variables),
 * hasta 3 botones de respuesta, 1 de enlace y 1 de llamada.
 */
final class Plantillas
{
    public const CATEGORIAS = [
        'MARKETING' => 'Marketing (promociones, ofertas, novedades)',
        'UTILITY' => 'Utilidad (recordatorios, pagos, confirmaciones de un trámite)',
    ];
    public const IDIOMAS = ['es' => 'Español', 'es_MX' => 'Español (México)', 'es_ES' => 'Español (España)', 'es_AR' => 'Español (Argentina)', 'en_US' => 'Inglés (EE. UU.)', 'pt_BR' => 'Portugués (Brasil)'];
    public const MAX_VARIABLES = 10;

    /** "Recordatorio de Pago" -> "recordatorio_de_pago" */
    public static function nombre(string $n): string
    {
        $n = Reglas::simple($n);
        return trim((string) preg_replace('/[^a-z0-9]+/', '_', $n), '_');
    }

    /** @return array el cuerpo que se manda a POST /{waba}/message_templates */
    public static function armar(array $f): array
    {
        $nombre = self::nombre((string) ($f['nombre'] ?? ''));
        if ($nombre === '' || strlen($nombre) > 512) {
            throw new RuntimeException('Escribe un nombre para la plantilla (ejemplo: recordatorio_pago).');
        }
        $categoria = (string) ($f['categoria'] ?? '');
        if (!isset(self::CATEGORIAS[$categoria])) {
            throw new RuntimeException('Elige si es de Marketing o de Utilidad.');
        }
        $idioma = (string) ($f['idioma'] ?? 'es');
        if (!isset(self::IDIOMAS[$idioma])) {
            throw new RuntimeException('Idioma no válido.');
        }
        $cuerpo = trim(str_replace("\r\n", "\n", (string) ($f['cuerpo'] ?? '')));
        $cuerpo = (string) preg_replace('/\{\{\s*(\d+)\s*\}\}/', '{{$1}}', $cuerpo);
        if ($cuerpo === '') {
            throw new RuntimeException('Escribe el texto del mensaje.');
        }
        if (mb_strlen($cuerpo) > 1024) {
            throw new RuntimeException('El texto es muy largo: máximo 1024 letras (tiene ' . mb_strlen($cuerpo) . ').');
        }
        if (preg_match('/\n{3,}/', $cuerpo)) {
            throw new RuntimeException('Meta no acepta más de una línea en blanco seguida en el texto.');
        }
        $vars = self::variables($cuerpo);
        if ($vars) {
            if ($vars !== range(1, count($vars))) {
                throw new RuntimeException('Las variables deben ir en orden y sin saltos: {{1}}, {{2}}, {{3}}…');
            }
            if (count($vars) > self::MAX_VARIABLES) {
                throw new RuntimeException('Máximo ' . self::MAX_VARIABLES . ' variables.');
            }
            if (preg_match('/^\s*\{\{\d+\}\}/', $cuerpo) || preg_match('/\{\{\d+\}\}[\s.!?¡¿]*$/u', $cuerpo)) {
                throw new RuntimeException('Una variable ({{1}}, {{2}}…) no puede ir al principio ni al final del texto (un punto al final no cuenta): agrega palabras antes y después, por ejemplo "Hola {{1}}, tu cita es el {{2}}. ¡Te esperamos!".');
            }
        }
        $ejemplos = [];
        foreach ($vars as $n) {
            $e = trim((string) ($f['ejemplo'][$n] ?? ''));
            if ($e === '') {
                throw new RuntimeException("Escribe un ejemplo para {{{$n}}} (Meta lo pide para revisar la plantilla).");
            }
            $ejemplos[] = mb_substr($e, 0, 200);
        }
        $componentes = [];
        $encabezado = trim((string) ($f['encabezado'] ?? ''));
        if ($encabezado !== '') {
            if (mb_strlen($encabezado) > 60) {
                throw new RuntimeException('El encabezado puede tener máximo 60 letras.');
            }
            if (str_contains($encabezado, '{{')) {
                throw new RuntimeException('El encabezado no puede llevar variables {{1}}; ponlas en el texto del mensaje.');
            }
            $componentes[] = ['type' => 'HEADER', 'format' => 'TEXT', 'text' => $encabezado];
        }
        $body = ['type' => 'BODY', 'text' => $cuerpo];
        if ($ejemplos) {
            $body['example'] = ['body_text' => [$ejemplos]];
        }
        $componentes[] = $body;
        $pie = trim((string) ($f['pie'] ?? ''));
        if ($pie !== '') {
            if (mb_strlen($pie) > 60) {
                throw new RuntimeException('El pie puede tener máximo 60 letras.');
            }
            if (str_contains($pie, '{{')) {
                throw new RuntimeException('El pie no puede llevar variables.');
            }
            $componentes[] = ['type' => 'FOOTER', 'text' => $pie];
        }
        $botones = [];
        $cuenta = ['QUICK_REPLY' => 0, 'URL' => 0, 'PHONE_NUMBER' => 0];
        foreach ((array) ($f['boton_tipo'] ?? []) as $i => $tipo) {
            $tipo = strtoupper((string) $tipo);
            $texto = trim((string) ($f['boton_texto'][$i] ?? ''));
            $destino = trim((string) ($f['boton_destino'][$i] ?? ''));
            if ($tipo === '' || $tipo === 'NINGUNO') {
                continue;
            }
            if (!isset($cuenta[$tipo])) {
                throw new RuntimeException('Tipo de botón no válido.');
            }
            if ($texto === '' || mb_strlen($texto) > 25) {
                throw new RuntimeException('Cada botón necesita un texto de máximo 25 letras.');
            }
            $cuenta[$tipo]++;
            $b = ['type' => $tipo, 'text' => $texto];
            if ($tipo === 'URL') {
                if (!preg_match('#^https?://[^\s{}]+$#i', $destino)) {
                    throw new RuntimeException('El botón "' . $texto . '" necesita un enlace completo que empiece con https://');
                }
                $b['url'] = $destino;
            } elseif ($tipo === 'PHONE_NUMBER') {
                $tel = Telefono::normalizar($destino);
                if ($tel === null) {
                    throw new RuntimeException('El botón "' . $texto . '" necesita un número de teléfono válido.');
                }
                $b['phone_number'] = '+' . $tel;
            }
            $botones[] = $b;
        }
        if ($cuenta['QUICK_REPLY'] > 3 || $cuenta['URL'] > 1 || $cuenta['PHONE_NUMBER'] > 1) {
            throw new RuntimeException('Máximo 3 botones de respuesta, 1 de enlace y 1 de llamada.');
        }
        if ($botones) {
            // Meta pide los botones de respuesta juntos, antes o después de los otros
            usort($botones, fn ($a, $b) => ($a['type'] === 'QUICK_REPLY' ? 0 : 1) <=> ($b['type'] === 'QUICK_REPLY' ? 0 : 1));
            $componentes[] = ['type' => 'BUTTONS', 'buttons' => $botones];
        }
        return ['name' => $nombre, 'language' => $idioma, 'category' => $categoria, 'components' => $componentes];
    }

    /** @return list<int> números de variable en el orden en que aparecen por primera vez, ordenados */
    public static function variables(string $texto): array
    {
        preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $texto, $m);
        $n = array_values(array_unique(array_map('intval', $m[1])));
        sort($n);
        return $n;
    }
}
