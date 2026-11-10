<?php
declare(strict_types=1);

namespace TuWhatsApp;

use PDO;
use Throwable;

require_once __DIR__ . '/TukanFactura.php';

/**
 * Factura electrónica con TuFactura (extra «facturacion»: APAGADO de fábrica, ni la demo ni ningún plan lo traen; se enciende
 * desde el Panel Tukán Central cuando el negocio compra TuFactura). 9 nov 2026.
 * La pieza común está en src/TukanFactura.php (copia de tufactura/docs/enlace/TukanFactura.php: no se cambia aquí).
 *  - Cada PEDIDO PAGADO se puede facturar con un toque (en el pedido o en la pantalla «Factura electrónica»), o sale solo al
 *    pasar a «Pagado» (a mano, o cuando Plux avisa) si se eligió «automático».
 *  - Cada número de WhatsApp (línea) se conecta con su propia clave de TuFactura: en las tablas de la pieza común el «negocio»
 *    es el id de la línea (cada número puede ser un local o una empresa distinta).
 *  - Cliente: la cédula o el RUC de sus campos personalizados (cedula, ruc, cedula_o_ruc, identificacion…) y su correo; si no
 *    tiene, consumidor final.
 *  - IVA: si el número suma el IVA (lineas.iva_pct > 0) los precios van sin IVA con esa tarifa; si los precios son finales (0),
 *    van con el IVA incluido (la factura suma exactamente lo cobrado).
 *  - La clave tf_ se guarda cifrada con TUKAN_FACTURA_LLAVE (o, si no está, con la clave de la base de datos).
 */
final class FacturaTukan
{
    public const PROGRAMA = 'WhatsApp Tukán';
    public const ORIGEN = 'pedido';
    public const PAGADOS = ['pagado', 'enviado', 'entregado'];

    public static function enlace(PDO $db): \TukanFactura
    {
        $llave = getenv('TUKAN_FACTURA_LLAVE') ?: ('TuWhatsApp|' . (getenv('TUWA_DB_PASS') ?: '') . '|' . (getenv('TUWA_DB_NAME') ?: 'tuwhatsapp'));
        return new \TukanFactura($db, $llave, self::PROGRAMA);
    }

    /** ¿La empresa dueña de esta línea tiene encendida la factura electrónica? */
    public static function activo(PDO $db, int $lineaId): bool
    {
        $st = $db->prepare('SELECT e.* FROM empresas e JOIN lineas l ON l.empresa_id = e.id WHERE l.id = ?');
        $st->execute([$lineaId]);
        $e = $st->fetch();
        return $e ? Modulos::activo($e, 'facturacion') : false;
    }

    /** Cédula/RUC y correo de los campos personalizados del contacto. */
    public static function identidad(array $datos): array
    {
        $id = ''; $correo = ''; $dir = '';
        foreach ($datos as $k => $v) {
            if (!is_scalar($v)) continue;
            $k = strtolower((string) $k);
            $v = trim((string) $v);
            if ($id === '' && preg_match('/c[eé]dula|ruc|identificaci|documento/u', $k)) $id = $v;
            if ($correo === '' && preg_match('/correo|e_?mail/', $k) && filter_var($v, FILTER_VALIDATE_EMAIL)) $correo = $v;
            if ($dir === '' && preg_match('/direcci/u', $k)) $dir = $v;
        }
        return [$id, $correo, $dir];
    }

    /** Lo que se manda a TuFactura de un pedido pagado: [cliente, items, extra], o null. */
    public static function datos(PDO $db, int $lineaId, string $id): ?array
    {
        if (!ctype_digit($id)) return null;
        $st = $db->prepare("SELECT p.*, l.iva_pct, c.nombre AS c_nombre, c.nombre_whatsapp AS c_nwa, c.telefono AS c_tel, c.datos AS c_datos
                            FROM pedidos p JOIN lineas l ON l.id = p.linea_id LEFT JOIN contactos c ON c.id = p.contacto_id
                            WHERE p.id = ? AND p.linea_id = ? AND p.estado IN ('pagado', 'enviado', 'entregado')");
        $st->execute([(int) $id, $lineaId]);
        $p = $st->fetch();
        if (!$p) return null;
        [$ident, $correo, $dir] = self::identidad(json_decode((string) ($p['c_datos'] ?? '{}'), true) ?: []);
        $nombre = trim((string) ($p['cliente_nombre'] ?: ($p['c_nombre'] ?: $p['c_nwa'])));
        $cliente = ['identificacion' => $ident, 'nombre' => $nombre, 'correo' => $correo, 'telefono' => (string) ($p['c_tel'] ?? ''), 'direccion' => $dir];
        $ivaPct = (float) $p['iva_pct'];
        $items = [];
        foreach (Pedidos::items($db, (int) $p['id']) as $i) {
            $it = ['descripcion' => (string) $i['nombre'], 'cantidad' => (int) $i['cantidad']];
            if ($ivaPct > 0) $items[] = $it + ['precio_sin_iva' => (float) $i['precio'], 'iva' => (int) round($ivaPct)];
            else $items[] = $it + ['precio' => (float) $i['precio']];
        }
        if (!$items) return null;
        $forma = match ((string) $p['metodo']) { 'plux' => 'plux', 'payphone' => 'payphone', 'transferencia' => 'transferencia', 'efectivo' => 'efectivo', default => 'otro' };
        return [$cliente, $items, ['forma' => $forma, 'info' => ['Pedido' => 'N.º ' . $p['numero'] . ' por WhatsApp']]];
    }

    /** Últimos pedidos pagados de la línea: [id, fecha, cliente, total, detalle]. */
    public static function cobros(PDO $db, int $lineaId, int $limite = 25): array
    {
        $st = $db->prepare("SELECT p.id, p.numero, COALESCE(p.pagado_en, p.actualizado) AS fecha, p.total, p.origen,
                                   COALESCE(NULLIF(p.cliente_nombre, ''), NULLIF(c.nombre, ''), c.nombre_whatsapp, '') AS nombre
                            FROM pedidos p LEFT JOIN contactos c ON c.id = p.contacto_id
                            WHERE p.linea_id = ? AND p.estado IN ('pagado', 'enviado', 'entregado') ORDER BY fecha DESC, p.id DESC LIMIT " . max(1, min(100, $limite)));
        $st->execute([$lineaId]);
        return array_map(fn($r) => [(string) $r['id'], (string) $r['fecha'], (string) $r['nombre'], (float) $r['total'], 'Pedido N.º ' . $r['numero'] . ($r['origen'] !== 'chat' ? ' (' . $r['origen'] . ')' : '')], $st->fetchAll());
    }

    /** Al pasar un pedido a «Pagado»: si se eligió «automático», sale la factura sola. Nunca tumba el pedido. */
    public static function alCobrar(PDO $db, int $lineaId, int $pedidoId): void
    {
        try {
            if (!self::activo($db, $lineaId)) return;
            self::enlace($db)->alCobrar($lineaId, true, self::ORIGEN, (string) $pedidoId, fn() => self::datos($db, $lineaId, (string) $pedidoId));
        } catch (Throwable $e) {
            error_log('WhatsApp Tukán TuFactura: ' . $e->getMessage());
        }
    }
}
