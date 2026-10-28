<?php
declare(strict_types=1);

namespace TuWhatsApp;

use PDO;
use RuntimeException;

/**
 * Campos personalizados del cliente (por número): "Ciudad", "Cédula", "Fecha de cumpleaños", "Talla"…
 * Los valores se guardan en contactos.datos (JSON). Un chatbot por pasos también puede llenarlos con una pregunta.
 */
final class Campos
{
    public const TIPOS = ['texto' => 'Texto', 'numero' => 'Número', 'fecha' => 'Fecha', 'lista' => 'Lista de opciones'];
    public const MAX = 30;

    /** @return list<array> */
    public static function lista(PDO $db, int $lineaId): array
    {
        $st = $db->prepare('SELECT * FROM campos WHERE linea_id = ? ORDER BY orden, id');
        $st->execute([$lineaId]);
        return $st->fetchAll();
    }

    /** "Fecha de nacimiento" -> "fecha_de_nacimiento" */
    public static function clave(string $nombre): string
    {
        $c = str_replace(' ', '_', Reglas::simple($nombre));
        return mb_substr($c !== '' ? $c : 'campo', 0, 40);
    }

    public static function guardar(PDO $db, int $lineaId, string $nombre, string $tipo, string $opciones = '', ?int $id = null): int
    {
        $nombre = trim($nombre);
        if ($nombre === '') {
            throw new RuntimeException('Escribe el nombre del campo.');
        }
        if (!isset(self::TIPOS[$tipo])) {
            throw new RuntimeException('Tipo de campo no válido.');
        }
        $ops = $tipo === 'lista' ? implode(', ', array_slice(array_values(array_filter(array_map(fn ($x) => mb_substr(trim($x), 0, 40), explode(',', $opciones)))), 0, 30)) : '';
        if ($tipo === 'lista' && $ops === '') {
            throw new RuntimeException('Escribe las opciones de la lista separadas por coma (ejemplo: S, M, L).');
        }
        if ($id !== null) {
            $db->prepare('UPDATE campos SET nombre = ?, tipo = ?, opciones = ? WHERE id = ? AND linea_id = ?')->execute([mb_substr($nombre, 0, 60), $tipo, $ops, $id, $lineaId]);
            return $id;
        }
        if (count(self::lista($db, $lineaId)) >= self::MAX) {
            throw new RuntimeException('Máximo ' . self::MAX . ' campos.');
        }
        $clave = self::clave($nombre);
        $base = $clave;
        $n = 2;
        $existe = $db->prepare('SELECT 1 FROM campos WHERE linea_id = ? AND clave = ?');
        while (true) {
            $existe->execute([$lineaId, $clave]);
            if (!$existe->fetchColumn()) {
                break;
            }
            $clave = mb_substr($base, 0, 36) . '_' . $n++;
        }
        $st = $db->prepare('INSERT INTO campos (linea_id, clave, nombre, tipo, opciones, orden) VALUES (?, ?, ?, ?, ?, (SELECT COALESCE(max(orden), 0) + 1 FROM campos WHERE linea_id = ?)) RETURNING id');
        $st->execute([$lineaId, $clave, mb_substr($nombre, 0, 60), $tipo, $ops, $lineaId]);
        return (int) $st->fetchColumn();
    }

    public static function borrar(PDO $db, int $lineaId, int $id): void
    {
        $db->prepare('DELETE FROM campos WHERE id = ? AND linea_id = ?')->execute([$id, $lineaId]);
    }

    /** Revisa y limpia un valor según el tipo. Devuelve el valor guardable ('' = vacío) o lanza un error claro. */
    public static function valor(array $campo, string $v): string
    {
        $v = trim($v);
        if ($v === '') {
            return '';
        }
        switch ($campo['tipo']) {
            case 'numero':
                $n = str_replace(',', '.', str_replace(['$', ' '], '', $v));
                if (!is_numeric($n)) {
                    throw new RuntimeException('"' . $campo['nombre'] . '" debe ser un número.');
                }
                return (string) (0 + $n);
            case 'fecha':
                $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v) ?: \DateTimeImmutable::createFromFormat('!d/m/Y', $v);
                if (!$d) {
                    throw new RuntimeException('"' . $campo['nombre'] . '" debe ser una fecha (día/mes/año).');
                }
                return $d->format('Y-m-d');
            case 'lista':
                foreach (array_map('trim', explode(',', (string) $campo['opciones'])) as $o) {
                    if (Reglas::simple($o) === Reglas::simple($v)) {
                        return $o;
                    }
                }
                throw new RuntimeException('"' . $campo['nombre'] . '" debe ser una de estas opciones: ' . $campo['opciones'] . '.');
            default:
                return mb_substr($v, 0, 300);
        }
    }

    /** Guarda los valores que vienen del formulario del chat (solo los campos que existen). */
    public static function guardarValores(PDO $db, int $lineaId, int $contactoId, array $valores): void
    {
        $st = $db->prepare('SELECT datos FROM contactos WHERE id = ? AND linea_id = ?');
        $st->execute([$contactoId, $lineaId]);
        $datos = json_decode((string) $st->fetchColumn(), true) ?: [];
        foreach (self::lista($db, $lineaId) as $c) {
            if (array_key_exists($c['clave'], $valores)) {
                $v = self::valor($c, (string) $valores[$c['clave']]);
                if ($v === '') {
                    unset($datos[$c['clave']]);
                } else {
                    $datos[$c['clave']] = $v;
                }
            }
        }
        $db->prepare('UPDATE contactos SET datos = ? WHERE id = ? AND linea_id = ?')->execute([json_encode($datos ?: new \stdClass(), JSON_UNESCAPED_UNICODE), $contactoId, $lineaId]);
    }

    /** Un solo valor (lo usa el chatbot). Lanza RuntimeException si no es válido. */
    public static function guardarUno(PDO $db, int $lineaId, int $contactoId, string $clave, string $valor): void
    {
        self::guardarValores($db, $lineaId, $contactoId, [$clave => $valor]);
    }

    /** @return array<string,string> clave => valor */
    public static function de(array $contacto): array
    {
        $d = is_array($contacto['datos'] ?? null) ? $contacto['datos'] : json_decode((string) ($contacto['datos'] ?? '{}'), true);
        return is_array($d) ? array_map('strval', $d) : [];
    }

    /** Valor para mostrar ("2026-05-04" -> "04/05/2026"). */
    public static function mostrar(array $campo, string $v): string
    {
        if ($campo['tipo'] === 'fecha' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            return (new \DateTimeImmutable($v))->format('d/m/Y');
        }
        return $v;
    }
}
