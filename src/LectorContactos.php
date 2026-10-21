<?php
declare(strict_types=1);

namespace TuWhatsApp;

/**
 * Lee los contactos que exporta el celular o Google, para guardarlos en Contactos.
 *  - .vcf (vCard): lo que exportan Android (Contactos → Exportar), iPhone (Contactos → Exportar) y Google Contactos.
 *  - .csv: "CSV de Google" o "CSV de Outlook" de contacts.google.com.
 *  - texto: una línea por contacto, "número, nombre".
 * Devuelve filas [número tal como venía, nombre]; el número se revisa después con Telefono::normalizar().
 */
final class LectorContactos
{
    /** @return list<array{0:string,1:string}> */
    public static function leer(string $contenido, string $nombreArchivo = ''): array
    {
        $contenido = self::utf8($contenido);
        $ext = strtolower(pathinfo($nombreArchivo, PATHINFO_EXTENSION));
        if ($ext === 'vcf' || preg_match('/^\s*BEGIN:VCARD/im', $contenido)) {
            return self::vcf($contenido);
        }
        if ($ext === 'csv' || self::pareceCsv($contenido)) {
            return self::csv($contenido);
        }
        return self::texto($contenido);
    }

    /** @return list<array{0:string,1:string}> */
    public static function texto(string $texto): array
    {
        $r = [];
        foreach (preg_split('/\r?\n/', $texto) ?: [] as $fila) {
            $fila = trim($fila);
            if ($fila !== '') {
                $p = preg_split('/[,;\t]/', $fila, 2);
                $r[] = [trim($p[0]), trim((string) ($p[1] ?? ''))];
            }
        }
        return $r;
    }

    /** @return list<array{0:string,1:string}> */
    public static function vcf(string $texto): array
    {
        $texto = str_replace(["\r\n", "\r"], "\n", $texto);
        // Quoted-printable: "=" al final de la línea sigue en la de abajo (Android lo usa para nombres con tildes).
        // Solo en las líneas QUOTED-PRINTABLE: las fotos en base64 también terminan en "=".
        $lineas = explode("\n", $texto);
        $unidas = [];
        for ($i = 0, $n = count($lineas); $i < $n; $i++) {
            $l = $lineas[$i];
            if (stripos($l, 'QUOTED-PRINTABLE') !== false && str_contains($l, ':')) {
                while (str_ends_with($l, '=') && $i + 1 < $n) {
                    $l = substr($l, 0, -1) . ltrim($lineas[++$i]);
                }
            }
            $unidas[] = $l;
        }
        $texto = implode("\n", $unidas);
        // Líneas dobladas: las que empiezan con espacio son continuación de la anterior
        $texto = preg_replace('/\n[ \t]/', '', $texto) ?? $texto;
        $r = [];
        foreach (preg_split('/^BEGIN:VCARD\s*$/mi', $texto) ?: [] as $tarjeta) {
            if (!preg_match('/^END:VCARD/mi', $tarjeta)) {
                continue;
            }
            $nombre = '';
            $partes = '';
            $empresa = '';
            $tels = [];
            foreach (explode("\n", $tarjeta) as $linea) {
                if (!preg_match('/^(?:item\d+\.)?([A-Za-z-]+)((?:;[^:]*)?):(.*)$/', $linea, $m)) {
                    continue;
                }
                $campo = strtoupper($m[1]);
                $valor = self::valor($m[3], $m[2]);
                if ($campo === 'FN') {
                    $nombre = $valor;
                } elseif ($campo === 'N') {
                    $n = array_map('trim', explode(';', $valor));
                    $partes = trim(($n[1] ?? '') . ' ' . ($n[2] ?? '') . ' ' . ($n[0] ?? ''));
                } elseif ($campo === 'ORG') {
                    $empresa = trim(str_replace(';', ' ', $valor));
                } elseif ($campo === 'TEL') {
                    $t = preg_replace('/^tel:/i', '', trim($valor));
                    if ($t !== '') {
                        $tels[] = $t;
                    }
                }
            }
            $nombre = preg_replace('/\s+/u', ' ', trim($nombre !== '' ? $nombre : ($partes !== '' ? $partes : $empresa))) ?? '';
            foreach (array_unique($tels) as $t) {
                $r[] = [$t, $nombre];
            }
        }
        return $r;
    }

    /** @return list<array{0:string,1:string}> */
    public static function csv(string $texto): array
    {
        $texto = preg_replace('/^\xEF\xBB\xBF/', '', $texto) ?? $texto;
        $h = fopen('php://temp', 'r+');
        fwrite($h, $texto);
        rewind($h);
        $primera = (string) fgets($h);
        $sep = substr_count($primera, ';') > substr_count($primera, ',') ? ';' : ',';
        rewind($h);
        $cab = fgetcsv($h, 0, $sep, '"', '');
        if (!$cab) {
            return [];
        }
        $cab = array_map(fn ($c) => Reglas::simple((string) $c), $cab);
        $colNombre = null;
        $colPartes = [];
        $colTel = [];
        foreach ($cab as $i => $c) {
            if (in_array($c, ['name', 'nombre', 'display name', 'nombre completo', 'full name'], true)) {
                $colNombre = $i;
            } elseif (in_array($c, ['first name', 'given name', 'nombre de pila', 'middle name', 'additional name', 'last name', 'family name', 'apellido', 'apellidos'], true)) {
                $colPartes[] = $i;
            }
            $esTel = preg_match('/\b(phone|telefono|movil|mobile|celular|cell)\b/', $c);
            $esEtiqueta = preg_match('/\b(label|type|tipo|etiqueta)\b/', $c);
            if ($esTel && !$esEtiqueta) {
                $colTel[] = $i;
            }
        }
        if (!$colTel) {   // sin cabecera: se lee como texto "número, nombre"
            fclose($h);
            return self::texto($texto);
        }
        $r = [];
        while (($f = fgetcsv($h, 0, $sep, '"', '')) !== false) {
            $nombre = $colNombre !== null ? trim((string) ($f[$colNombre] ?? '')) : '';
            if ($nombre === '' && $colPartes) {
                $nombre = trim(implode(' ', array_filter(array_map(fn ($i) => trim((string) ($f[$i] ?? '')), $colPartes))));
            }
            $vistos = [];
            foreach ($colTel as $i) {
                foreach (preg_split('/\s*:::\s*/', (string) ($f[$i] ?? '')) ?: [] as $t) {
                    $t = trim($t);
                    if ($t !== '' && !in_array($t, $vistos, true)) {
                        $vistos[] = $t;
                        $r[] = [$t, $nombre];
                    }
                }
            }
        }
        fclose($h);
        return $r;
    }

    private static function valor(string $v, string $params): string
    {
        if (stripos($params, 'QUOTED-PRINTABLE') !== false) {
            $v = quoted_printable_decode($v);
        }
        if (preg_match('/CHARSET=([A-Za-z0-9_-]+)/i', $params, $m) && strtoupper($m[1]) !== 'UTF-8') {
            $c = @mb_convert_encoding($v, 'UTF-8', $m[1]);
            $v = is_string($c) ? $c : $v;
        }
        return str_replace(['\\,', '\\;', '\\n', '\\N', '\\\\'], [',', ';', ' ', ' ', '\\'], $v);
    }

    private static function utf8(string $s): string
    {
        if (str_starts_with($s, "\xFF\xFE") || str_starts_with($s, "\xFE\xFF")) {
            $c = mb_convert_encoding($s, 'UTF-8', 'UTF-16');
            return is_string($c) ? $c : $s;
        }
        if (!mb_check_encoding($s, 'UTF-8')) {
            $c = mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
            return is_string($c) ? $c : $s;
        }
        return $s;
    }

    private static function pareceCsv(string $s): bool
    {
        $primera = strtok($s, "\n") ?: '';
        return (bool) preg_match('/(phone|tel[eé]fono|m[oó]vil|celular)/i', $primera) && substr_count($primera, ',') + substr_count($primera, ';') >= 2;
    }
}
