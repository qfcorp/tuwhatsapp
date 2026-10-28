<?php
declare(strict_types=1);

namespace TuWhatsApp;

use PDO;
use RuntimeException;

/**
 * Catálogo de productos o servicios del número: nombre, precio, stock (opcional), código, descripción y foto.
 * Desde el chat se arma el carrito, se manda la ficha de un producto (foto con precio) o la lista para que el cliente elija
 * tocando (cada fila de la lista tiene el id "prod:N"; al tocarla se agrega a su carrito).
 * El agente con IA también lo usa para contestar precios y armar el carrito.
 */
final class Catalogo
{
    public const MAX = 2000;

    /** @return list<array> */
    public static function lista(PDO $db, int $lineaId, bool $soloActivos = false, string $buscar = '', int $limite = 500): array
    {
        $donde = 'linea_id = :l';
        $p = ['l' => $lineaId];
        if ($soloActivos) {
            $donde .= ' AND activo AND (stock IS NULL OR stock > 0)';
        }
        if (trim($buscar) !== '') {
            $donde .= ' AND (nombre ILIKE :b OR codigo ILIKE :b OR descripcion ILIKE :b)';
            $p['b'] = '%' . trim($buscar) . '%';
        }
        $st = $db->prepare("SELECT * FROM productos WHERE $donde ORDER BY activo DESC, lower(nombre) LIMIT " . max(1, min(self::MAX, $limite)));
        $st->execute($p);
        return $st->fetchAll();
    }

    public static function buscar(PDO $db, int $lineaId, int $id): ?array
    {
        $st = $db->prepare('SELECT * FROM productos WHERE id = ? AND linea_id = ?');
        $st->execute([$id, $lineaId]);
        return $st->fetch() ?: null;
    }

    /** Crea o cambia un producto con lo que viene del formulario. */
    public static function guardar(PDO $db, int $lineaId, array $d, ?int $id = null): int
    {
        $nombre = trim((string) ($d['nombre'] ?? ''));
        if ($nombre === '') {
            throw new RuntimeException('Escribe el nombre del producto.');
        }
        $precioTxt = trim((string) ($d['precio'] ?? '0'));
        if (preg_match('/[a-z]/i', $precioTxt)) {
            throw new RuntimeException('El precio de "' . $nombre . '" no es un número.');
        }
        $precio = Embudo::valor($precioTxt);
        $stockTxt = trim((string) ($d['stock'] ?? ''));
        if ($stockTxt !== '' && !preg_match('/^\d{1,7}$/', $stockTxt)) {
            throw new RuntimeException('El stock debe ser un número entero (o vacío si no lo controlas).');
        }
        $stock = $stockTxt === '' ? null : (int) $stockTxt;
        $vals = [mb_substr(trim((string) ($d['codigo'] ?? '')), 0, 40), mb_substr($nombre, 0, 120), mb_substr(trim((string) ($d['descripcion'] ?? '')), 0, 1000),
                 $precio, $stock, !empty($d['activo']) || !array_key_exists('activo', $d) ? 't' : 'f'];
        if ($id !== null) {
            if (!self::buscar($db, $lineaId, $id)) {
                throw new RuntimeException('Ese producto no existe.');
            }
            $db->prepare('UPDATE productos SET codigo = ?, nombre = ?, descripcion = ?, precio = ?, stock = ?, activo = ? WHERE id = ? AND linea_id = ?')
               ->execute([...$vals, $id, $lineaId]);
            return $id;
        }
        $st = $db->prepare('SELECT count(*) FROM productos WHERE linea_id = ?');
        $st->execute([$lineaId]);
        if ((int) $st->fetchColumn() >= self::MAX) {
            throw new RuntimeException('Máximo ' . self::MAX . ' productos por número.');
        }
        $st = $db->prepare('INSERT INTO productos (linea_id, codigo, nombre, descripcion, precio, stock, activo) VALUES (?, ?, ?, ?, ?, ?, ?) RETURNING id');
        $st->execute([$lineaId, ...$vals]);
        return (int) $st->fetchColumn();
    }

    public static function guardarFoto(PDO $db, int $lineaId, int $id, ?string $ruta): void
    {
        $db->prepare('UPDATE productos SET foto_ruta = ? WHERE id = ? AND linea_id = ?')->execute([$ruta, $id, $lineaId]);
    }

    public static function borrar(PDO $db, int $lineaId, int $id): void
    {
        $db->prepare('DELETE FROM productos WHERE id = ? AND linea_id = ?')->execute([$id, $lineaId]);
    }

    /**
     * Importa productos desde un CSV (Excel "Guardar como CSV"): nombre, precio, stock, código, descripción.
     * La primera fila puede ser de títulos. Si el código ya existe, se actualiza.
     * @return array{nuevos:int, cambiados:int, malos:int}
     */
    public static function importarCsv(PDO $db, int $lineaId, string $csv): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
        $lineas = preg_split('/\R/', trim($csv)) ?: [];
        if (count($lineas) > self::MAX + 1) {
            throw new RuntimeException('Son demasiados productos de una vez (máximo ' . self::MAX . ').');
        }
        $sep = substr_count($lineas[0] ?? '', ';') > substr_count($lineas[0] ?? '', ',') ? ';' : ',';
        $r = ['nuevos' => 0, 'cambiados' => 0, 'malos' => 0];
        $db->beginTransaction();
        try {
            foreach ($lineas as $i => $l) {
                $f = array_map('trim', str_getcsv($l, $sep, '"', ''));
                if ($i === 0 && isset($f[1]) && !preg_match('/\d/', $f[1])) {
                    continue;   // títulos
                }
                if (($f[0] ?? '') === '') {
                    continue;
                }
                try {
                    $datos = ['nombre' => $f[0], 'precio' => $f[1] ?? '0', 'stock' => $f[2] ?? '', 'codigo' => $f[3] ?? '', 'descripcion' => $f[4] ?? '', 'activo' => 1];
                    $existe = null;
                    if ($datos['codigo'] !== '') {
                        $st = $db->prepare('SELECT id, stock, descripcion, foto_ruta FROM productos WHERE linea_id = ? AND codigo = ? LIMIT 1');
                        $st->execute([$lineaId, $datos['codigo']]);
                        $ya = $st->fetch();
                        if ($ya) {   // al actualizar, lo que viene vacío se queda como estaba
                            $existe = (int) $ya['id'];
                            if ($datos['stock'] === '' && $ya['stock'] !== null) {
                                $datos['stock'] = (string) $ya['stock'];
                            }
                            if ($datos['descripcion'] === '') {
                                $datos['descripcion'] = (string) $ya['descripcion'];
                            }
                        }
                    }
                    self::guardar($db, $lineaId, $datos, $existe !== null ? (int) $existe : null);
                    $existe !== null ? $r['cambiados']++ : $r['nuevos']++;
                } catch (RuntimeException) {
                    $r['malos']++;
                }
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        return $r;
    }

    /** Texto de la ficha de un producto para mandar por WhatsApp. */
    public static function ficha(array $p, array $linea): string
    {
        $t = '*' . $p['nombre'] . '*' . "\n" . self::precio((float) $p['precio'], $linea);
        if (trim((string) $p['descripcion']) !== '') {
            $t .= "\n" . trim((string) $p['descripcion']);
        }
        if ($p['stock'] !== null && (int) $p['stock'] <= 5) {
            $t .= "\n" . ((int) $p['stock'] > 0 ? 'Quedan ' . (int) $p['stock'] : 'Agotado');
        }
        return $t;
    }

    public static function precio(float $v, array $linea): string
    {
        $iva = (float) ($linea['iva_pct'] ?? 0);
        return '$' . number_format($v, 2, ',', '.') . ($iva > 0 ? ' + IVA' : '');
    }

    /** Filas para la lista de WhatsApp (máximo 10): id "prod:N", título y precio. */
    public static function filasLista(array $productos, array $linea): array
    {
        $r = [];
        foreach (array_slice($productos, 0, 10) as $p) {
            $r[] = ['id' => 'prod:' . (int) $p['id'], 'titulo' => mb_substr((string) $p['nombre'], 0, 24), 'descripcion' => self::precio((float) $p['precio'], $linea)];
        }
        return $r;
    }

    /** El catálogo como texto para el agente con IA (lo más importante primero, recortado). */
    public static function paraIa(PDO $db, int $lineaId, array $linea, int $maxLetras = 6000): string
    {
        $r = [];
        $largo = 0;
        foreach (self::lista($db, $lineaId, true, '', 300) as $p) {
            $l = '[' . (int) $p['id'] . '] ' . $p['nombre'] . ' — ' . self::precio((float) $p['precio'], $linea)
               . ($p['stock'] !== null ? ' (quedan ' . (int) $p['stock'] . ')' : '')
               . (trim((string) $p['descripcion']) !== '' ? ': ' . mb_strimwidth(trim(preg_replace('/\s+/', ' ', (string) $p['descripcion'])), 0, 160, '…') : '');
            if ($largo + mb_strlen($l) > $maxLetras) {
                $r[] = '(hay más productos: si preguntan por uno que no está aquí, di que lo confirmas)';
                break;
            }
            $r[] = $l;
            $largo += mb_strlen($l);
        }
        return implode("\n", $r);
    }
}
