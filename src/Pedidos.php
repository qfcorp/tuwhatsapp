<?php
declare(strict_types=1);

namespace TuWhatsApp;

use DateTimeImmutable;
use PDO;
use RuntimeException;

/**
 * Carrito y pedidos por WhatsApp.
 *  - Cada contacto tiene como mucho un pedido en estado "carrito" (se arma desde el chat, desde la lista del catálogo
 *    que el cliente toca, o lo arma el agente con IA).
 *  - Confirmar: carrito -> pendiente (de pago) y se baja el stock (si no alcanza, no se confirma).
 *  - Pagado -> enviado -> entregado. Cancelar devuelve el stock.
 *  - Los pedidos de Shopify o WooCommerce también llegan aquí (origen shopify / woocommerce).
 * IVA del número (lineas.iva_pct): 0 = los precios ya son finales; si es 15, al subtotal se le suma el 15 %.
 */
final class Pedidos
{
    public const ESTADOS = ['carrito' => 'Carrito', 'pendiente' => 'Pendiente de pago', 'pagado' => 'Pagado', 'enviado' => 'Enviado', 'entregado' => 'Entregado', 'cancelado' => 'Cancelado'];
    /** A qué estados se puede pasar desde cada uno. */
    private const PASOS = [
        'carrito'   => ['pendiente', 'pagado', 'cancelado'],
        'pendiente' => ['pagado', 'cancelado', 'carrito'],
        'pagado'    => ['enviado', 'entregado', 'cancelado'],
        'enviado'   => ['entregado', 'cancelado'],
        'entregado' => [],
        'cancelado' => [],
    ];

    public static function buscar(PDO $db, int $lineaId, int $id): ?array
    {
        $st = $db->prepare('SELECT p.*, c.nombre AS c_nombre, c.nombre_whatsapp AS c_nombre_whatsapp, c.telefono AS c_telefono
                            FROM pedidos p LEFT JOIN contactos c ON c.id = p.contacto_id WHERE p.id = ? AND p.linea_id = ?');
        $st->execute([$id, $lineaId]);
        return $st->fetch() ?: null;
    }

    /** @return list<array> */
    public static function items(PDO $db, int $pedidoId): array
    {
        $st = $db->prepare('SELECT * FROM pedido_items WHERE pedido_id = ? ORDER BY id');
        $st->execute([$pedidoId]);
        return $st->fetchAll();
    }

    /** El carrito abierto del contacto (lo crea si $crear). */
    public static function carrito(PDO $db, array $linea, int $contactoId, bool $crear = true, string $origen = 'chat', ?int $usuarioId = null): ?array
    {
        $st = $db->prepare("SELECT id FROM pedidos WHERE linea_id = ? AND contacto_id = ? AND estado = 'carrito' ORDER BY id DESC LIMIT 1");
        $st->execute([(int) $linea['id'], $contactoId]);
        $id = $st->fetchColumn();
        if ($id !== false) {
            return self::buscar($db, (int) $linea['id'], (int) $id);
        }
        if (!$crear) {
            return null;
        }
        return self::buscar($db, (int) $linea['id'], self::crear($db, (int) $linea['id'], $contactoId, 'carrito', $origen, $usuarioId));
    }

    /** Pedido nuevo con el número siguiente del número de WhatsApp. */
    public static function crear(PDO $db, int $lineaId, ?int $contactoId, string $estado, string $origen, ?int $usuarioId = null, array $extra = []): int
    {
        $propia = !$db->inTransaction();
        if ($propia) {
            $db->beginTransaction();
        }
        try {
            $db->prepare('SELECT pg_advisory_xact_lock(?, ?)')->execute([11, $lineaId]);
            $st = $db->prepare('INSERT INTO pedidos (linea_id, contacto_id, numero, estado, origen, usuario_id, externo_id, externo_url, cliente_nombre, notas)
                                VALUES (?, ?, (SELECT COALESCE(max(numero), 0) + 1 FROM pedidos WHERE linea_id = ?), ?, ?, ?, ?, ?, ?, ?) RETURNING id');
            $st->execute([$lineaId, $contactoId, $lineaId, $estado, $origen, $usuarioId, $extra['externo_id'] ?? null, $extra['externo_url'] ?? '',
                          $extra['cliente_nombre'] ?? '', $extra['notas'] ?? '']);
            $id = (int) $st->fetchColumn();
            if ($propia) {
                $db->commit();
            }
            return $id;
        } catch (\Throwable $e) {
            if ($propia) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /** Agrega un producto del catálogo al carrito del contacto. Devuelve el carrito actualizado. */
    public static function agregar(PDO $db, array $linea, int $contactoId, int $productoId, int $cantidad = 1, string $origen = 'chat', ?int $usuarioId = null): array
    {
        if ($cantidad < 1 || $cantidad > 999) {
            throw new RuntimeException('La cantidad debe estar entre 1 y 999.');
        }
        $p = Catalogo::buscar($db, (int) $linea['id'], $productoId);
        if (!$p || !$p['activo']) {
            throw new RuntimeException('Ese producto no está disponible.');
        }
        $carrito = self::carrito($db, $linea, $contactoId, true, $origen, $usuarioId);
        $st = $db->prepare('SELECT id, cantidad FROM pedido_items WHERE pedido_id = ? AND producto_id = ?');
        $st->execute([(int) $carrito['id'], $productoId]);
        $ya = $st->fetch();
        $nueva = $cantidad + ($ya ? (int) $ya['cantidad'] : 0);
        if ($p['stock'] !== null && $nueva > (int) $p['stock']) {
            throw new RuntimeException((int) $p['stock'] > 0 ? 'De "' . $p['nombre'] . '" solo quedan ' . (int) $p['stock'] . '.' : '"' . $p['nombre'] . '" está agotado.');
        }
        if ($ya) {
            $db->prepare('UPDATE pedido_items SET cantidad = ?, precio = ?, nombre = ? WHERE id = ?')->execute([$nueva, $p['precio'], $p['nombre'], $ya['id']]);
        } else {
            $db->prepare('INSERT INTO pedido_items (pedido_id, producto_id, nombre, precio, cantidad) VALUES (?, ?, ?, ?, ?)')
               ->execute([(int) $carrito['id'], $productoId, $p['nombre'], $p['precio'], $cantidad]);
        }
        self::recalcular($db, $linea, (int) $carrito['id']);
        return self::buscar($db, (int) $linea['id'], (int) $carrito['id']);
    }

    /** Agrega una línea libre (algo que no está en el catálogo, un envío, un descuento negativo no). */
    public static function agregarLibre(PDO $db, array $linea, int $pedidoId, string $nombre, string $precio, int $cantidad = 1): void
    {
        $pedido = self::editable($db, $linea, $pedidoId);
        $nombre = trim($nombre);
        if ($nombre === '') {
            throw new RuntimeException('Escribe qué es (ejemplo: Envío a Quito).');
        }
        if ($cantidad < 1 || $cantidad > 999) {
            throw new RuntimeException('La cantidad debe estar entre 1 y 999.');
        }
        $db->prepare('INSERT INTO pedido_items (pedido_id, nombre, precio, cantidad) VALUES (?, ?, ?, ?)')
           ->execute([(int) $pedido['id'], mb_substr($nombre, 0, 120), Embudo::valor($precio), $cantidad]);
        self::recalcular($db, $linea, (int) $pedido['id']);
    }

    public static function cambiarCantidad(PDO $db, array $linea, int $pedidoId, int $itemId, int $cantidad): void
    {
        $pedido = self::editable($db, $linea, $pedidoId);
        if ($cantidad <= 0) {
            $db->prepare('DELETE FROM pedido_items WHERE id = ? AND pedido_id = ?')->execute([$itemId, (int) $pedido['id']]);
        } else {
            $st = $db->prepare('SELECT i.*, p.stock, p.nombre AS p_nombre FROM pedido_items i LEFT JOIN productos p ON p.id = i.producto_id WHERE i.id = ? AND i.pedido_id = ?');
            $st->execute([$itemId, (int) $pedido['id']]);
            $it = $st->fetch();
            if (!$it) {
                throw new RuntimeException('Esa línea ya no está en el pedido.');
            }
            if ($it['stock'] !== null && $cantidad > (int) $it['stock']) {
                throw new RuntimeException('De "' . $it['nombre'] . '" solo quedan ' . (int) $it['stock'] . '.');
            }
            $db->prepare('UPDATE pedido_items SET cantidad = ? WHERE id = ?')->execute([min(999, $cantidad), $itemId]);
        }
        self::recalcular($db, $linea, (int) $pedido['id']);
    }

    /** Solo se cambian los productos mientras es carrito o pendiente de pago (y no viene de una tienda). */
    private static function editable(PDO $db, array $linea, int $pedidoId): array
    {
        $p = self::buscar($db, (int) $linea['id'], $pedidoId);
        if (!$p) {
            throw new RuntimeException('Ese pedido no existe.');
        }
        if (!in_array($p['estado'], ['carrito', 'pendiente'], true) || in_array($p['origen'], ['shopify', 'woocommerce'], true)) {
            throw new RuntimeException('Este pedido ya no se puede cambiar.');
        }
        return $p;
    }

    /** @return array{subtotal:float, iva:float, total:float} */
    public static function totales(array $items, float $ivaPct): array
    {
        $sub = 0.0;
        foreach ($items as $i) {
            $sub += round((float) $i['precio'] * (int) $i['cantidad'], 2);
        }
        $sub = round($sub, 2);
        $iva = $ivaPct > 0 ? round($sub * $ivaPct / 100, 2) : 0.0;
        return ['subtotal' => $sub, 'iva' => $iva, 'total' => round($sub + $iva, 2)];
    }

    public static function recalcular(PDO $db, array $linea, int $pedidoId): void
    {
        $t = self::totales(self::items($db, $pedidoId), (float) ($linea['iva_pct'] ?? 0));
        $db->prepare("UPDATE pedidos SET subtotal = ?, iva = ?, total = ?, actualizado = now(), enlace_pago = CASE WHEN total <> ? THEN '' ELSE enlace_pago END WHERE id = ?")
           ->execute([$t['subtotal'], $t['iva'], $t['total'], $t['total'], $pedidoId]);
    }

    /**
     * Cambia el estado. Al salir de carrito/pendiente baja el stock (todo o nada); al cancelar lo devuelve.
     */
    public static function cambiarEstado(PDO $db, array $linea, int $pedidoId, string $nuevo, ?DateTimeImmutable $ahora = null): array
    {
        if (!isset(self::ESTADOS[$nuevo])) {
            throw new RuntimeException('Estado no válido.');
        }
        $db->beginTransaction();
        try {
            $st = $db->prepare('SELECT * FROM pedidos WHERE id = ? AND linea_id = ? FOR UPDATE');
            $st->execute([$pedidoId, (int) $linea['id']]);
            $p = $st->fetch();
            if (!$p) {
                throw new RuntimeException('Ese pedido no existe.');
            }
            if ($p['estado'] === $nuevo) {
                $db->commit();
                return $p;
            }
            if (!in_array($nuevo, self::PASOS[$p['estado']], true)) {
                throw new RuntimeException('Un pedido "' . self::ESTADOS[$p['estado']] . '" no puede pasar a "' . self::ESTADOS[$nuevo] . '".');
            }
            $items = self::items($db, $pedidoId);
            if ($nuevo !== 'cancelado' && $nuevo !== 'carrito' && !$items) {
                throw new RuntimeException('El pedido no tiene productos.');
            }
            $descontado = (bool) $p['descontado'];
            $externo = in_array($p['origen'], ['shopify', 'woocommerce'], true);   // el stock lo maneja la tienda
            if (!$descontado && !$externo && in_array($nuevo, ['pendiente', 'pagado', 'enviado', 'entregado'], true)) {
                foreach ($items as $i) {
                    if ($i['producto_id'] === null) {
                        continue;
                    }
                    $chk = $db->prepare('SELECT stock FROM productos WHERE id = ? FOR UPDATE');
                    $chk->execute([(int) $i['producto_id']]);
                    $stock = $chk->fetchColumn();
                    if ($stock !== false && $stock !== null && (int) $stock < (int) $i['cantidad']) {
                        throw new RuntimeException('No alcanza el stock de "' . $i['nombre'] . '" (quedan ' . (int) $stock . ').');
                    }
                    $db->prepare('UPDATE productos SET stock = stock - ? WHERE id = ? AND stock IS NOT NULL')->execute([(int) $i['cantidad'], (int) $i['producto_id']]);
                }
                $descontado = true;
            }
            if ($descontado && in_array($nuevo, ['cancelado', 'carrito'], true)) {
                foreach ($items as $i) {
                    if ($i['producto_id'] !== null) {
                        $db->prepare('UPDATE productos SET stock = stock + ? WHERE id = ? AND stock IS NOT NULL')->execute([(int) $i['cantidad'], (int) $i['producto_id']]);
                    }
                }
                $descontado = false;
            }
            $db->prepare('UPDATE pedidos SET estado = ?, descontado = ?, actualizado = now(), pagado_en = CASE WHEN ? = \'pagado\' AND pagado_en IS NULL THEN ? ELSE pagado_en END WHERE id = ?')
               ->execute([$nuevo, $descontado ? 't' : 'f', $nuevo, ($ahora ?? new DateTimeImmutable())->format('c'), $pedidoId]);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        return self::buscar($db, (int) $linea['id'], $pedidoId);
    }

    public static function guardarNotas(PDO $db, int $lineaId, int $pedidoId, string $notas): void
    {
        $db->prepare('UPDATE pedidos SET notas = ?, actualizado = now() WHERE id = ? AND linea_id = ?')->execute([mb_substr(trim($notas), 0, 1000), $pedidoId, $lineaId]);
    }

    /**
     * Enlace de pago: con Plux, la página de pago de WhatsApp Tukán del pedido; con Payphone, un enlace por pedido
     * (si hay los dos, el que eligió el negocio en Cobros); si no, el enlace fijo del número
     * (DeUna, PayPal, Kushki…) cambiando {total} y {pedido}. Se guarda en el pedido. Devuelve '' si no hay forma de pago en línea.
     */
    public static function enlacePago(PDO $db, array $linea, array $pedido): string
    {
        if ((string) $pedido['enlace_pago'] !== '') {
            return (string) $pedido['enlace_pago'];
        }
        $url = '';
        $token = Cifrado::descifrar((string) ($linea['payphone_token'] ?? ''));
        $conPlux = Plux::configurado($linea);
        $elegido = (string) ($linea['cobro_tarjeta'] ?? '');
        // Con qué se cobra con tarjeta: lo que eligió el negocio en Cobros; si no eligió, Payphone y si no, Plux
        $usar = match (true) {
            $elegido === 'plux' && $conPlux => 'plux',
            $elegido === 'payphone' && $token !== '' => 'payphone',
            $token !== '' => 'payphone',
            $conPlux => 'plux',
            default => '',
        };
        if ($usar === 'plux') {
            $url = Plux::urlPago($db, $pedido);
            $metodo = 'plux';
        } elseif ($usar === 'payphone') {
            $cent = (int) round((float) $pedido['total'] * 100);
            $iva = (int) round((float) $pedido['iva'] * 100);
            $r = (float) $pedido['iva'] > 0
                ? Payphone::crearLink($token, (string) $linea['payphone_store'], 0, $cent - $iva, $iva, 'P' . $pedido['id'] . 'T' . substr((string) time(), -6), 'Pedido ' . $pedido['numero'] . ' ' . $linea['nombre'])
                : Payphone::crearLink($token, (string) $linea['payphone_store'], $cent, 0, 0, 'P' . $pedido['id'] . 'T' . substr((string) time(), -6), 'Pedido ' . $pedido['numero'] . ' ' . $linea['nombre']);
            if (!$r['ok']) {
                throw new RuntimeException($r['error']);
            }
            $url = $r['url'];
            $metodo = 'payphone';
        } elseif (trim((string) ($linea['pago_enlace'] ?? '')) !== '') {
            $url = strtr(trim((string) $linea['pago_enlace']), ['{total}' => number_format((float) $pedido['total'], 2, '.', ''), '{pedido}' => (string) $pedido['numero']]);
            $metodo = 'enlace';
        }
        if ($url !== '') {
            $db->prepare('UPDATE pedidos SET enlace_pago = ?, metodo = ? WHERE id = ?')->execute([$url, $metodo, (int) $pedido['id']]);
        }
        return $url;
    }

    /** Resumen del pedido para mandar por WhatsApp (con cómo pagar). */
    public static function resumen(array $linea, array $pedido, array $items, string $enlace = ''): string
    {
        $d = fn (float $v) => '$' . number_format($v, 2, ',', '.');
        $t = '*Pedido #' . $pedido['numero'] . '* · ' . $linea['nombre'] . "\n";
        foreach ($items as $i) {
            $t .= (int) $i['cantidad'] . ' x ' . $i['nombre'] . ' = ' . $d((float) $i['precio'] * (int) $i['cantidad']) . "\n";
        }
        if ((float) $pedido['iva'] > 0) {
            $t .= 'Subtotal: ' . $d((float) $pedido['subtotal']) . "\nIVA " . rtrim(rtrim(number_format((float) $linea['iva_pct'], 2, ',', ''), '0'), ',') . ' %: ' . $d((float) $pedido['iva']) . "\n";
        }
        $t .= '*Total: ' . $d((float) $pedido['total']) . '*';
        $pago = [];
        if ($enlace !== '') {
            $pago[] = 'Paga aquí: ' . $enlace;
        }
        if (trim((string) ($linea['pago_transferencia'] ?? '')) !== '') {
            $pago[] = "Transferencia:\n" . trim((string) $linea['pago_transferencia']);
        }
        if ($pago) {
            $t .= "\n\n" . implode("\n\n", $pago);
        }
        return $t;
    }

    /** @return list<array> pedidos del número (sin carritos vacíos), con el nombre del cliente */
    public static function lista(PDO $db, int $lineaId, string $estado = '', string $buscar = '', int $limite = 200): array
    {
        $donde = "p.linea_id = :l AND NOT (p.estado = 'carrito' AND p.total = 0)";
        $par = ['l' => $lineaId];
        if (isset(self::ESTADOS[$estado])) {
            $donde .= ' AND p.estado = :e';
            $par['e'] = $estado;
        }
        if (trim($buscar) !== '') {
            $donde .= ' AND (c.nombre ILIKE :b OR c.nombre_whatsapp ILIKE :b OR p.cliente_nombre ILIKE :b OR c.telefono LIKE :bt OR p.numero::text = :n)';
            $par['b'] = '%' . trim($buscar) . '%';
            $par['bt'] = '%' . (preg_replace('/\D+/', '', $buscar) ?: '-') . '%';
            $par['n'] = trim(ltrim($buscar, '#'));
        }
        $st = $db->prepare("SELECT p.*, c.nombre AS c_nombre, c.nombre_whatsapp AS c_nombre_whatsapp, c.telefono AS c_telefono,
                                   (SELECT count(*) FROM pedido_items i WHERE i.pedido_id = p.id) AS lineas
                            FROM pedidos p LEFT JOIN contactos c ON c.id = p.contacto_id WHERE $donde ORDER BY p.actualizado DESC LIMIT " . max(1, min(1000, $limite)));
        $st->execute($par);
        return $st->fetchAll();
    }

    /** @return array<string, array{n:int, total:float}> por estado */
    public static function cuentas(PDO $db, int $lineaId): array
    {
        $st = $db->prepare("SELECT estado, count(*), COALESCE(sum(total), 0) FROM pedidos WHERE linea_id = ? AND NOT (estado = 'carrito' AND total = 0) GROUP BY estado");
        $st->execute([$lineaId]);
        $r = [];
        foreach ($st->fetchAll(PDO::FETCH_NUM) as [$e, $n, $t]) {
            $r[$e] = ['n' => (int) $n, 'total' => (float) $t];
        }
        return $r;
    }

    /** Últimos pedidos del contacto (para el chat). */
    public static function deContacto(PDO $db, int $contactoId, int $limite = 5): array
    {
        $st = $db->prepare("SELECT * FROM pedidos WHERE contacto_id = ? AND estado <> 'carrito' ORDER BY id DESC LIMIT " . max(1, $limite));
        $st->execute([$contactoId]);
        return $st->fetchAll();
    }

    public static function nombreCliente(array $p): string
    {
        foreach (['c_nombre', 'c_nombre_whatsapp', 'cliente_nombre'] as $k) {
            if (trim((string) ($p[$k] ?? '')) !== '') {
                return trim((string) $p[$k]);
            }
        }
        return isset($p['c_telefono']) && $p['c_telefono'] ? Telefono::bonito((string) $p['c_telefono']) : 'Sin nombre';
    }
}
