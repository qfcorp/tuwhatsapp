<?php
declare(strict_types=1);

namespace TuWhatsApp;

use PDO;
use RuntimeException;

final class Contactos
{
    public const TIPOS = ['cliente', 'personal', 'desconocido'];

    public static function buscar(PDO $db, int $lineaId, int $id): ?array
    {
        $st = $db->prepare('SELECT * FROM contactos WHERE id = ? AND linea_id = ?');
        $st->execute([$id, $lineaId]);
        return $st->fetch() ?: null;
    }

    public static function porTelefono(PDO $db, int $lineaId, string $tel): ?array
    {
        $st = $db->prepare('SELECT * FROM contactos WHERE linea_id = ? AND telefono = ?');
        $st->execute([$lineaId, $tel]);
        return $st->fetch() ?: null;
    }

    /** Devuelve el contacto; si no existe lo crea como "desconocido". */
    public static function obtener(PDO $db, int $lineaId, string $tel, string $nombreWhatsapp = ''): array
    {
        $st = $db->prepare("INSERT INTO contactos (linea_id, telefono, nombre_whatsapp) VALUES (?, ?, ?)
                            ON CONFLICT (linea_id, telefono) DO UPDATE SET nombre_whatsapp = CASE WHEN EXCLUDED.nombre_whatsapp <> ''
                                THEN EXCLUDED.nombre_whatsapp ELSE contactos.nombre_whatsapp END
                            RETURNING *");
        $st->execute([$lineaId, $tel, mb_substr($nombreWhatsapp, 0, 100)]);
        return $st->fetch();
    }

    /** Crea o actualiza un contacto desde el panel o la API. */
    public static function guardar(PDO $db, int $lineaId, string $telefono, string $nombre, string $tipo, ?string $notas = null): array
    {
        $tel = Telefono::normalizar($telefono);
        if ($tel === null) {
            throw new RuntimeException('El número "' . $telefono . '" no es válido. Escríbelo como 0991234567 o con el código del país.');
        }
        if (!in_array($tipo, self::TIPOS, true)) {
            throw new RuntimeException('Tipo de contacto no válido.');
        }
        $c = self::obtener($db, $lineaId, $tel);
        $db->prepare('UPDATE contactos SET nombre = CASE WHEN ? <> \'\' THEN ? ELSE nombre END, tipo = ?, notas = COALESCE(?, notas) WHERE id = ?')
           ->execute([trim($nombre), mb_substr(trim($nombre), 0, 100), $tipo, $notas === null ? null : mb_substr($notas, 0, 2000), $c['id']]);
        return self::buscar($db, $lineaId, (int) $c['id']);
    }

    public static function cambiarTipo(PDO $db, int $lineaId, int $id, string $tipo): void
    {
        if (!in_array($tipo, self::TIPOS, true)) {
            throw new RuntimeException('Tipo de contacto no válido.');
        }
        $db->prepare('UPDATE contactos SET tipo = ? WHERE id = ? AND linea_id = ?')->execute([$tipo, $id, $lineaId]);
    }

    /**
     * Guarda una lista pegada (una línea por contacto: "número, nombre") o leída de un archivo del celular.
     * $soloNuevos: los números que ya existen no se tocan (así un Cliente no pasa a Personal por error).
     * Los teléfonos fijos y números raros se cuentan en "malos" (no tienen WhatsApp).
     * @param list<array{0:string,1:string}>|string $filas
     * @return array{nuevos:int, cambiados:int, iguales:int, malos:list<string>, total:int}
     */
    public static function importar(PDO $db, int $lineaId, array|string $filas, string $tipo, bool $soloNuevos = false): array
    {
        if (!in_array($tipo, self::TIPOS, true)) {
            throw new RuntimeException('Tipo de contacto no válido.');
        }
        if (is_string($filas)) {
            $filas = LectorContactos::texto($filas);
        }
        if (count($filas) > 20000) {
            throw new RuntimeException('Son demasiados contactos de una vez (máximo 20.000).');
        }
        $r = ['nuevos' => 0, 'cambiados' => 0, 'iguales' => 0, 'malos' => [], 'total' => count($filas)];
        $vistos = [];
        $db->beginTransaction();
        try {
            foreach ($filas as [$numero, $nombre]) {
                $tel = Telefono::normalizar($numero);
                if ($tel === null) {
                    $r['malos'][] = trim($numero . ($nombre !== '' ? ' ' . $nombre : ''));
                    continue;
                }
                if (isset($vistos[$tel])) {
                    continue;   // el mismo número dos veces en la lista
                }
                $vistos[$tel] = true;
                $existe = self::porTelefono($db, $lineaId, $tel);
                if ($existe && $soloNuevos) {
                    $r['iguales']++;
                    continue;
                }
                self::guardar($db, $lineaId, $tel, mb_substr($nombre, 0, 100), $tipo);
                $existe ? $r['cambiados']++ : $r['nuevos']++;
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        return $r;
    }

    public static function nombre(array $c): string
    {
        $n = trim((string) ($c['nombre'] ?? ''));
        if ($n === '') {
            $n = trim((string) ($c['nombre_whatsapp'] ?? ''));
        }
        return $n !== '' ? $n : Telefono::bonito((string) $c['telefono']);
    }

    /** Conversaciones para la bandeja: el último mensaje de cada contacto, lo más nuevo arriba. */
    public static function bandeja(PDO $db, int $lineaId, string $filtro = 'todos', string $buscar = '', int $limite = 100): array
    {
        $donde = 'c.linea_id = :l';
        $p = ['l' => $lineaId];
        if (in_array($filtro, self::TIPOS, true)) {
            $donde .= ' AND c.tipo = :t';
            $p['t'] = $filtro;
        }
        if ($filtro === 'sin_leer') {
            $donde .= ' AND EXISTS (SELECT 1 FROM mensajes x WHERE x.contacto_id = c.id AND NOT x.leido)';
        }
        if ($buscar !== '') {
            $donde .= ' AND (c.nombre ILIKE :b OR c.nombre_whatsapp ILIKE :b OR c.telefono LIKE :bt)';
            $p['b'] = '%' . $buscar . '%';
            $p['bt'] = '%' . preg_replace('/\D+/', '', $buscar) . '%';
            if ($p['bt'] === '%%') {
                $p['bt'] = '%-%';
            }
        }
        $st = $db->prepare("SELECT c.*, m.texto AS ultimo_texto, m.direccion AS ultima_direccion, m.creado AS ultimo_en,
                                   (SELECT count(*) FROM mensajes x WHERE x.contacto_id = c.id AND NOT x.leido) AS sin_leer
                            FROM contactos c
                            JOIN LATERAL (SELECT texto, direccion, creado FROM mensajes WHERE contacto_id = c.id ORDER BY creado DESC, id DESC LIMIT 1) m ON true
                            WHERE $donde ORDER BY m.creado DESC LIMIT " . max(1, min(500, $limite)));
        $st->execute($p);
        return $st->fetchAll();
    }

    /** Todos los contactos (para la pantalla Contactos). */
    public static function lista(PDO $db, int $lineaId, string $tipo = '', string $buscar = ''): array
    {
        $donde = 'linea_id = :l';
        $p = ['l' => $lineaId];
        if (in_array($tipo, self::TIPOS, true)) {
            $donde .= ' AND tipo = :t';
            $p['t'] = $tipo;
        }
        if ($buscar !== '') {
            $donde .= ' AND (nombre ILIKE :b OR nombre_whatsapp ILIKE :b OR telefono LIKE :bt)';
            $p['b'] = '%' . $buscar . '%';
            $d = preg_replace('/\D+/', '', $buscar);
            $p['bt'] = $d === '' ? '%-%' : '%' . $d . '%';
        }
        $st = $db->prepare("SELECT * FROM contactos WHERE $donde ORDER BY tipo, lower(COALESCE(NULLIF(nombre, ''), nombre_whatsapp, telefono)) LIMIT 1000");
        $st->execute($p);
        return $st->fetchAll();
    }

    public static function contar(PDO $db, int $lineaId): array
    {
        $st = $db->prepare('SELECT tipo, count(*) FROM contactos WHERE linea_id = ? GROUP BY tipo');
        $st->execute([$lineaId]);
        return $st->fetchAll(PDO::FETCH_KEY_PAIR) + ['cliente' => 0, 'personal' => 0, 'desconocido' => 0];
    }
}
