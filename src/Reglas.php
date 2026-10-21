<?php
declare(strict_types=1);

namespace TuWhatsApp;

use DateTimeImmutable;

/**
 * Decide si un mensaje que llega se contesta solo y con qué. No toca la base ni Meta (fácil de probar).
 *
 * Orden de las reglas (la primera que dice "no" gana):
 *  1. Respuestas automáticas apagadas.
 *  2. Contacto marcado como Personal: nunca.
 *  3. Pidió hablar con una persona hace poco: no.
 *  4. Solo clientes registrados: si no es Cliente, no.
 *  5. Fuera de horario: si estás en horario de atención, contestas tú.
 *  6. Palabras de negocio: si no dice ninguna (ni elige una opción del menú), no.
 *  7. Una sola respuesta: después del menú solo se contestan las opciones (máximo N en H horas).
 * Si pasa todo: si escribió el número de una opción, va esa respuesta; si no, va el menú.
 */
final class Reglas
{
    public const DIAS = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];

    /**
     * @param int $autosRecientes respuestas automáticas a este contacto en las últimas horas_silencio horas
     * @return array{responder:bool, motivo:string, texto:?string, humano:bool}
     */
    public static function decidir(array $linea, array $contacto, string $texto, DateTimeImmutable $ahora, int $autosRecientes): array
    {
        $no = fn (string $m) => ['responder' => false, 'motivo' => $m, 'texto' => null, 'humano' => false];
        if (!self::si($linea['auto_activo'] ?? false)) {
            return $no('Respuestas automáticas apagadas');
        }
        if (($contacto['tipo'] ?? '') === 'personal') {
            return $no('Contacto personal: no se contesta solo');
        }
        if (!empty($contacto['silencio_hasta']) && new DateTimeImmutable($contacto['silencio_hasta']) > $ahora) {
            return $no('Pidió hablar con una persona: contestas tú');
        }
        if (self::si($linea['regla_solo_clientes'] ?? false) && ($contacto['tipo'] ?? '') !== 'cliente') {
            return $no('No es cliente registrado');
        }
        if (self::si($linea['regla_horario'] ?? false) && self::enHorario(Lineas::horario($linea), $ahora)) {
            return $no('Estás en horario de atención: contestas tú');
        }
        $opciones = Lineas::opciones($linea);
        $opcion = self::opcionElegida($texto, count($opciones));
        if ($opcion === null && self::si($linea['regla_palabras'] ?? false)
            && !self::tienePalabra($texto, self::listaPalabras((string) ($linea['palabras'] ?? '')))) {
            return $no('No habla de negocio (sin palabras clave)');
        }
        if (self::si($linea['regla_una_vez'] ?? false)) {
            $max = max(1, (int) ($linea['max_auto'] ?? 3));
            if ($autosRecientes >= $max) {
                return $no("Ya se le contestó $max veces: contestas tú");
            }
            if ($autosRecientes > 0 && $opcion === null) {
                return $no('Ya recibió el menú: contestas tú');
            }
        }
        if ($opcion !== null) {
            $o = $opciones[$opcion - 1];
            return ['responder' => true, 'motivo' => 'Eligió la opción ' . $opcion . ': ' . $o['titulo'], 'texto' => $o['respuesta'], 'humano' => $o['humano']];
        }
        return ['responder' => true, 'motivo' => 'Se le envió el menú', 'texto' => self::textoMenu($linea), 'humano' => false];
    }

    public static function textoMenu(array $linea): string
    {
        $t = trim((string) $linea['mensaje_menu']);
        foreach (Lineas::opciones($linea) as $i => $o) {
            $t .= "\n" . ($i + 1) . '. ' . $o['titulo'];
        }
        return $t;
    }

    /** @param array<int, ?array{0:string,1:string}> $horario */
    public static function enHorario(array $horario, DateTimeImmutable $ahora): bool
    {
        $h = $horario[(int) $ahora->format('N')] ?? null;
        if ($h === null) {
            return false;
        }
        $hora = $ahora->format('H:i');
        return $hora >= $h[0] && $hora < $h[1];
    }

    /** "1", "2." o " 3 " -> número de opción; cualquier otra cosa -> null. */
    public static function opcionElegida(string $texto, int $cuantas): ?int
    {
        if ($cuantas < 1 || !preg_match('/^\s*(\d)\s*[.)]?\s*$/u', $texto, $m)) {
            return null;
        }
        $n = (int) $m[1];
        return ($n >= 1 && $n <= $cuantas) ? $n : null;
    }

    /** @return list<string> */
    public static function listaPalabras(string $texto): array
    {
        $r = [];
        foreach (preg_split('/[,\n;]+/u', $texto) ?: [] as $p) {
            $p = trim(mb_strtolower($p));
            if ($p !== '' && !in_array($p, $r, true)) {
                $r[] = mb_substr($p, 0, 40);
            }
        }
        return array_slice($r, 0, 100);
    }

    /** Busca palabras completas, sin importar mayúsculas ni tildes ("cotización" = "cotizacion"). */
    public static function tienePalabra(string $texto, array $palabras): bool
    {
        $t = ' ' . self::simple($texto) . ' ';
        foreach ($palabras as $p) {
            $p = self::simple($p);
            if ($p !== '' && str_contains($t, ' ' . $p . ' ')) {
                return true;
            }
        }
        return false;
    }

    public static function simple(string $s): string
    {
        $s = mb_strtolower($s);
        $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
        $s = preg_replace('/[^a-z0-9]+/u', ' ', $s) ?? '';
        return trim($s);
    }

    private static function si(mixed $v): bool
    {
        return $v === true || $v === 't' || $v === 1 || $v === '1';
    }
}
