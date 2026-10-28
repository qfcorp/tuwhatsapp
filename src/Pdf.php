<?php
declare(strict_types=1);

namespace TuWhatsApp;

/**
 * PDF mínimo, sin librerías: páginas A4, texto en Helvetica (normal y negrita) con tildes y ñ, líneas y rectángulos de color.
 * Lo usa la cotización. Coordenadas en puntos desde ARRIBA a la izquierda (más fácil de pensar); se convierten al guardar.
 */
final class Pdf
{
    public const ANCHO = 595.28;
    public const ALTO = 841.89;

    /** Ancho de cada letra (códigos 32 a 255 de Windows-1252) por cada 1000 puntos de tamaño. */
    private const ANCHOS = [
        'Helvetica' => [278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584,761,556,761,222,556,333,1000,556,556,333,1000,667,333,1000,761,611,761,761,222,222,333,333,350,556,1000,333,1000,500,333,944,761,500,667,278,333,556,556,556,556,260,556,333,737,370,556,584,333,737,333,400,584,333,333,333,556,537,278,333,333,365,556,834,834,834,611,667,667,667,667,667,667,1000,722,667,667,667,667,278,278,278,278,722,722,778,778,778,778,778,584,778,722,722,722,722,667,667,611,556,556,556,556,556,556,889,500,556,556,556,556,278,278,278,278,556,556,556,556,556,556,556,584,611,556,556,556,556,500,556,500],
        'Helvetica-Bold' => [278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,333,556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,611,611,389,556,333,611,556,778,556,556,500,389,280,389,584,761,556,761,278,556,500,1000,556,556,333,1000,667,333,1000,761,611,761,761,278,278,500,500,350,556,1000,333,1000,556,333,944,761,500,667,278,333,556,556,556,556,280,556,333,737,370,556,584,333,737,333,400,584,333,333,333,611,556,278,333,333,365,556,834,834,834,611,722,722,722,722,722,722,1000,722,667,667,667,667,278,278,278,278,722,722,778,778,778,778,778,584,778,722,722,722,722,667,667,611,556,556,556,556,556,556,889,556,556,556,556,556,278,278,278,278,611,611,611,611,611,611,611,584,611,611,611,611,611,556,611,556],
    ];

    /** @var list<string> contenido de cada página */
    private array $paginas = [];
    private string $actual = '';

    public function __construct()
    {
        $this->paginaNueva();
    }

    public function paginaNueva(): void
    {
        if ($this->actual !== '') {
            $this->paginas[] = $this->actual;
        }
        $this->actual = "\n";
    }

    /** UTF-8 -> Windows-1252 (lo que entiende la Helvetica del PDF). Lo que no existe se cambia por algo parecido. */
    public static function aWin(string $t): string
    {
        $t = strtr($t, ['“' => '"', '”' => '"', '‘' => "'", '’' => "'", '–' => '-', '—' => '-', '…' => '...', '•' => '-', "\t" => ' ']);
        $r = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $t);
        return $r === false ? (string) preg_replace('/[^\x20-\x7E]/', '?', $t) : $r;
    }

    /** Ancho del texto en puntos. */
    public static function ancho(string $texto, float $tam, bool $negrita = false): float
    {
        $tabla = self::ANCHOS[$negrita ? 'Helvetica-Bold' : 'Helvetica'];
        $w = 0;
        foreach (str_split(self::aWin($texto)) as $ch) {
            $o = ord($ch);
            $w += $o >= 32 ? $tabla[$o - 32] : 0;
        }
        return $w * $tam / 1000;
    }

    /** Parte un texto en líneas que caben en $max puntos. @return list<string> */
    public static function partir(string $texto, float $tam, float $max, bool $negrita = false): array
    {
        $r = [];
        foreach (preg_split('/\R/u', $texto) ?: [] as $parrafo) {
            $linea = '';
            foreach (preg_split('/\s+/u', trim($parrafo)) ?: [] as $palabra) {
                $prueba = $linea === '' ? $palabra : $linea . ' ' . $palabra;
                if ($linea !== '' && self::ancho($prueba, $tam, $negrita) > $max) {
                    $r[] = $linea;
                    $linea = $palabra;
                } else {
                    $linea = $prueba;
                }
            }
            $r[] = $linea;
        }
        return $r;
    }

    /** @param string $alinear izq | der | centro (respecto de $x) */
    public function texto(float $x, float $y, string $texto, float $tam = 10, bool $negrita = false, array $color = [0, 0, 0], string $alinear = 'izq'): void
    {
        if ($alinear !== 'izq') {
            $w = self::ancho($texto, $tam, $negrita);
            $x -= $alinear === 'der' ? $w : $w / 2;
        }
        $t = str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ' '], self::aWin($texto));
        $this->actual .= sprintf("BT %s rg /%s %.2F Tf %.2F %.2F Td (%s) Tj ET\n", self::color($color), $negrita ? 'F2' : 'F1', $tam, $x, self::ALTO - $y, $t);
    }

    public function linea(float $x1, float $y1, float $x2, float $y2, array $color = [0.8, 0.8, 0.8], float $grosor = 0.6): void
    {
        $this->actual .= sprintf("%s RG %.2F w %.2F %.2F m %.2F %.2F l S\n", self::color($color), $grosor, $x1, self::ALTO - $y1, $x2, self::ALTO - $y2);
    }

    public function rect(float $x, float $y, float $w, float $h, array $relleno): void
    {
        $this->actual .= sprintf("%s rg %.2F %.2F %.2F %.2F re f\n", self::color($relleno), $x, self::ALTO - $y - $h, $w, $h);
    }

    private static function color(array $c): string
    {
        return sprintf('%.3F %.3F %.3F', (float) ($c[0] ?? 0), (float) ($c[1] ?? 0), (float) ($c[2] ?? 0));
    }

    /** "#14213D" -> [r, g, b] de 0 a 1 */
    public static function hex(string $h): array
    {
        $h = ltrim($h, '#');
        return [hexdec(substr($h, 0, 2)) / 255, hexdec(substr($h, 2, 2)) / 255, hexdec(substr($h, 4, 2)) / 255];
    }

    /** Los bytes del PDF terminado. */
    public function salida(string $titulo = 'Documento'): string
    {
        $paginas = array_merge($this->paginas, [$this->actual]);
        $objs = [];
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $objs[5] = '<< /Title (' . str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], self::aWin($titulo)) . ') /Producer (WhatsApp Tukan) >>';
        $kids = [];
        $n = 6;
        foreach ($paginas as $contenido) {
            $pag = $n++;
            $cont = $n++;
            $kids[] = "$pag 0 R";
            $objs[$pag] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>', self::ANCHO, self::ALTO, $cont);
            $objs[$cont] = '<< /Length ' . strlen($contenido) . " >>\nstream\n" . $contenido . "\nendstream";
        }
        $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        ksort($objs);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $pos = [];
        foreach ($objs as $i => $o) {
            $pos[$i] = strlen($pdf);
            $pdf .= "$i 0 obj\n$o\nendobj\n";
        }
        $xref = strlen($pdf);
        $total = max(array_keys($objs)) + 1;
        $pdf .= "xref\n0 $total\n0000000000 65535 f \n";
        for ($i = 1; $i < $total; $i++) {
            $pdf .= isset($pos[$i]) ? sprintf("%010d 00000 n \n", $pos[$i]) : "0000000000 65535 f \n";
        }
        return $pdf . "trailer\n<< /Size $total /Root 1 0 R /Info 5 0 R >>\nstartxref\n$xref\n%%EOF\n";
    }
}
