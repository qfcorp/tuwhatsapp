<?php
declare(strict_types=1);

namespace TuWhatsApp;

use DateTimeImmutable;
use PDO;
use RuntimeException;

/**
 * Cuánto se gasta en Meta y el tope del mes.
 * Precios por mensaje entregado (dólares), editables en Gasto → Precios de Meta.
 * Reglas de Meta que se aplican aquí:
 *  - Si el cliente llegó desde un anuncio "Enviar WhatsApp", los mensajes de las 72 horas siguientes no se cobran.
 *  - Una plantilla de utilidad enviada mientras la ventana de 24 horas está abierta no se cobra.
 *  - Lo que se envía a mano desde el celular no pasa por aquí y no se cobra.
 */
final class Gasto
{
    public const CATEGORIAS = ['servicio' => 'Respuestas (servicio)', 'utilidad' => 'Utilidad', 'marketing' => 'Marketing', 'autenticacion' => 'Autenticación'];
    /** Nombres de Meta -> los nuestros */
    public const DE_META = ['service' => 'servicio', 'utility' => 'utilidad', 'marketing' => 'marketing', 'authentication' => 'autenticacion',
                            'marketing_lite' => 'marketing', 'authentication_international' => 'autenticacion'];

    /** @return array<string,float> */
    public static function precios(PDO $db): array
    {
        $p = [];
        foreach ($db->query("SELECT clave, valor FROM ajustes WHERE clave LIKE 'precio\\_%'") as $f) {
            $p[substr($f['clave'], 7)] = (float) $f['valor'];
        }
        foreach (array_keys(self::CATEGORIAS) as $c) {
            $p[$c] ??= 0.0;
        }
        return $p;
    }

    public static function guardarPrecios(PDO $db, array $d): void
    {
        foreach (array_keys(self::CATEGORIAS) as $c) {
            $v = (float) str_replace(',', '.', trim((string) ($d[$c] ?? '')));
            if ($v < 0 || $v > 1) {
                throw new RuntimeException('Cada precio debe estar entre $0 y $1 por mensaje.');
            }
            $db->prepare('INSERT INTO ajustes (clave, valor) VALUES (?, ?) ON CONFLICT (clave) DO UPDATE SET valor = EXCLUDED.valor')
               ->execute(['precio_' . $c, (string) round($v, 5)]);
        }
    }

    public static function ventanaAbierta(array $contacto, DateTimeImmutable $ahora): bool
    {
        return !empty($contacto['ultimo_entrante'])
            && new DateTimeImmutable($contacto['ultimo_entrante']) > $ahora->modify('-24 hours');
    }

    /** Lo que costará un mensaje (0 si Meta no lo cobra). */
    public static function estimar(PDO $db, string $categoria, array $contacto, DateTimeImmutable $ahora): float
    {
        if (!empty($contacto['gratis_hasta']) && new DateTimeImmutable($contacto['gratis_hasta']) > $ahora) {
            return 0.0;
        }
        if ($categoria === 'utilidad' && self::ventanaAbierta($contacto, $ahora)) {
            return 0.0;
        }
        return self::precios($db)[$categoria] ?? 0.0;
    }

    /** Gastado en el mes de $ahora (lo enviado o en camino, sin los fallidos). */
    public static function delMes(PDO $db, int $lineaId, ?DateTimeImmutable $ahora = null): float
    {
        $ini = ($ahora ?? new DateTimeImmutable())->modify('first day of this month')->setTime(0, 0);
        $st = $db->prepare("SELECT COALESCE(SUM(costo), 0) FROM mensajes WHERE linea_id = ? AND direccion = 'sale'
                            AND estado NOT IN ('fallido', 'bloqueado') AND creado >= ? AND creado < ?");
        $st->execute([$lineaId, $ini->format('c'), $ini->modify('+1 month')->format('c')]);
        return round((float) $st->fetchColumn(), 5);
    }

    /** Resumen para la pantalla Gasto. */
    public static function resumen(PDO $db, array $linea, ?DateTimeImmutable $ahora = null): array
    {
        $ahora ??= new DateTimeImmutable();
        $ini = $ahora->modify('first day of this month')->setTime(0, 0);
        $gastado = self::delMes($db, (int) $linea['id'], $ahora);
        $tope = (float) $linea['tope_mensual'];
        $st = $db->prepare("SELECT origen, categoria, count(*) AS n, COALESCE(SUM(costo),0) AS costo FROM mensajes
                            WHERE linea_id = ? AND direccion = 'sale' AND estado NOT IN ('fallido','bloqueado') AND creado >= ?
                            GROUP BY origen, categoria");
        $st->execute([$linea['id'], $ini->format('c')]);
        $porCategoria = [];
        $porOrigen = [];
        foreach ($st as $f) {
            $c = $f['categoria'] ?? 'gratis';
            $porCategoria[$c]['n'] = ($porCategoria[$c]['n'] ?? 0) + (int) $f['n'];
            $porCategoria[$c]['costo'] = ($porCategoria[$c]['costo'] ?? 0) + (float) $f['costo'];
            $porOrigen[$f['origen']]['n'] = ($porOrigen[$f['origen']]['n'] ?? 0) + (int) $f['n'];
            $porOrigen[$f['origen']]['costo'] = ($porOrigen[$f['origen']]['costo'] ?? 0) + (float) $f['costo'];
        }
        $st = $db->prepare("SELECT date_trunc('month', creado)::date AS mes, COALESCE(SUM(costo),0) AS costo, count(*) AS n FROM mensajes
                            WHERE linea_id = ? AND direccion = 'sale' AND estado NOT IN ('fallido','bloqueado') AND creado >= ?
                            GROUP BY 1 ORDER BY 1 DESC");
        $st->execute([$linea['id'], $ini->modify('-5 months')->format('c')]);
        $bloqueados = $db->prepare("SELECT count(*) FROM mensajes WHERE linea_id = ? AND estado = 'bloqueado' AND creado >= ?");
        $bloqueados->execute([$linea['id'], $ini->format('c')]);
        return [
            'gastado' => $gastado,
            'tope' => $tope,
            'queda' => max(0, $tope - $gastado),
            'pct' => $tope > 0 ? min(100, (int) floor($gastado * 100 / $tope)) : 100,
            'por_categoria' => $porCategoria,
            'por_origen' => $porOrigen,
            'meses' => $st->fetchAll(),
            'bloqueados' => (int) $bloqueados->fetchColumn(),
        ];
    }
}
