<?php
declare(strict_types=1);

namespace TuWhatsApp;

use DateTimeImmutable;
use PDO;
use RuntimeException;

/**
 * Tienda en línea conectada: Shopify o WooCommerce mandan avisos (webhooks) a https://<dominio>/tienda.php?c=<clave del número>.
 *  - Pedido nuevo: entra a Pedidos (origen shopify / woocommerce) con sus productos, y al cliente le llega por WhatsApp la
 *    plantilla elegida ("Hola {{1}}, recibimos tu pedido {{2}} por {{3}}. Síguelo aquí: {{4}}").
 *  - Pedido enviado (Shopify "fulfilled", WooCommerce "completed"): plantilla de envío.
 *  - Carrito abandonado (Shopify checkouts): si en N horas no compró, plantilla de recordatorio con el enlace para terminar la
 *    compra (la manda el cron cada minuto). Si después compra, ya no se manda. Nunca a quien pidió no recibir promociones.
 * Las plantillas son de Meta (aprobadas); los espacios {{1}}… se llenan con: nombre, número de pedido, total y enlace.
 * La firma de cada aviso se revisa con la clave secreta de la tienda (HMAC-SHA256 en base64).
 */
final class Tiendas
{
    public const TIPOS = ['shopify' => 'Shopify', 'woocommerce' => 'WooCommerce'];

    public static function porClave(PDO $db, string $clave): ?array
    {
        if (!preg_match('/^[a-f0-9]{24}$/', $clave)) {
            return null;
        }
        $st = $db->prepare("SELECT * FROM lineas WHERE tienda_clave = ? AND tienda_tipo <> ''");
        $st->execute([$clave]);
        return $st->fetch() ?: null;
    }

    public static function url(array $linea): string
    {
        return rtrim((string) (getenv('TUWA_URL') ?: ''), '/') . '/tienda.php?c=' . $linea['tienda_clave'];
    }

    /** Guarda la conexión (pantalla Tienda en línea). El secreto solo se cambia si se escribe uno nuevo. */
    public static function guardar(PDO $db, array $linea, array $d): void
    {
        $tipo = (string) ($d['tienda_tipo'] ?? '');
        if ($tipo !== '' && !isset(self::TIPOS[$tipo])) {
            throw new RuntimeException('Elige Shopify o WooCommerce.');
        }
        $secreto = trim((string) ($d['tienda_secreto'] ?? ''));
        if ($tipo !== '' && $secreto === '' && (string) $linea['tienda_secreto'] === '') {
            throw new RuntimeException('Pega la clave secreta con la que firma tu tienda (está en la configuración de webhooks de ' . self::TIPOS[$tipo] . ').');
        }
        $plantilla = function (string $k) use ($d): string {
            $v = trim((string) ($d[$k] ?? ''));
            return preg_match('/^[a-z0-9_]{1,512}\|[A-Za-z_]{2,10}$/', $v) ? $v : '';
        };
        $horas = max(1, min(72, (int) ($d['tienda_carrito_horas'] ?? 2)));
        $clave = (string) $linea['tienda_clave'] !== '' ? (string) $linea['tienda_clave'] : bin2hex(random_bytes(12));
        $db->prepare('UPDATE lineas SET tienda_tipo = ?, tienda_clave = ?, tienda_plantilla_pedido = ?, tienda_plantilla_envio = ?, tienda_plantilla_carrito = ?,
                      tienda_carrito_horas = ?, tienda_error = \'\' WHERE id = ?')
           ->execute([$tipo, $clave, $plantilla('tienda_plantilla_pedido'), $plantilla('tienda_plantilla_envio'), $plantilla('tienda_plantilla_carrito'), $horas, (int) $linea['id']]);
        if ($secreto !== '') {
            $db->prepare('UPDATE lineas SET tienda_secreto = ? WHERE id = ?')->execute([Cifrado::cifrar($secreto), (int) $linea['id']]);
        }
    }

    public static function firmaValida(array $linea, string $cuerpo, string $firma): bool
    {
        $secreto = Cifrado::descifrar((string) $linea['tienda_secreto']);
        return $secreto !== '' && $firma !== '' && hash_equals(base64_encode(hash_hmac('sha256', $cuerpo, $secreto, true)), trim($firma));
    }

    /**
     * Procesa un aviso de la tienda ya verificado. Devuelve qué hizo (para el registro).
     * @param string $tema Shopify: orders/create, orders/paid, orders/fulfilled, orders/cancelled, checkouts/create, checkouts/update
     *                     WooCommerce: order.created, order.updated
     */
    public static function procesar(PDO $db, array $linea, string $tema, array $d, ?DateTimeImmutable $ahora = null): string
    {
        $ahora ??= new DateTimeImmutable();
        $tipo = (string) $linea['tienda_tipo'];
        if ($tipo === 'shopify' && str_starts_with($tema, 'checkouts/')) {
            return self::carrito($db, $linea, $d, $ahora);
        }
        if (($tipo === 'shopify' && str_starts_with($tema, 'orders/')) || ($tipo === 'woocommerce' && str_starts_with($tema, 'order.'))) {
            return self::pedido($db, $linea, $tipo === 'shopify' ? self::deShopify($d, $tema) : self::deWoo($d), $ahora);
        }
        return 'Aviso ignorado (' . $tema . ')';
    }

    /** Pedido de Shopify en nuestro formato. */
    public static function deShopify(array $d, string $tema): array
    {
        $dir = (array) ($d['shipping_address'] ?? []) + (array) ($d['billing_address'] ?? []);
        $cli = (array) ($d['customer'] ?? []);
        $estado = match (true) {
            $tema === 'orders/cancelled' || !empty($d['cancelled_at']) => 'cancelado',
            $tema === 'orders/fulfilled' || ($d['fulfillment_status'] ?? '') === 'fulfilled' => 'enviado',
            in_array($d['financial_status'] ?? '', ['paid', 'partially_refunded'], true) || $tema === 'orders/paid' => 'pagado',
            default => 'pendiente',
        };
        $items = [];
        foreach ((array) ($d['line_items'] ?? []) as $i) {
            $items[] = ['nombre' => (string) ($i['title'] ?? $i['name'] ?? 'Producto') . (!empty($i['variant_title']) ? ' (' . $i['variant_title'] . ')' : ''),
                        'precio' => (float) ($i['price'] ?? 0), 'cantidad' => max(1, (int) ($i['quantity'] ?? 1))];
        }
        return [
            'id' => (string) ($d['id'] ?? ''), 'numero' => (string) ($d['name'] ?? ('#' . ($d['order_number'] ?? ''))), 'estado' => $estado,
            'total' => (float) ($d['total_price'] ?? 0), 'iva' => (float) ($d['total_tax'] ?? 0), 'url' => (string) ($d['order_status_url'] ?? ''),
            'telefono' => (string) ($d['phone'] ?? '') ?: (string) ($dir['phone'] ?? '') ?: (string) ($cli['phone'] ?? ''),
            'nombre' => trim((string) ($dir['first_name'] ?? $cli['first_name'] ?? '') . ' ' . (string) ($dir['last_name'] ?? $cli['last_name'] ?? '')),
            'checkout' => (string) ($d['checkout_token'] ?? ''), 'items' => $items, 'origen' => 'shopify',
        ];
    }

    /** Pedido de WooCommerce en nuestro formato. */
    public static function deWoo(array $d): array
    {
        $b = (array) ($d['billing'] ?? []);
        $s = (array) ($d['shipping'] ?? []);
        $estado = match ((string) ($d['status'] ?? '')) {
            'processing' => 'pagado',
            'completed' => 'entregado',
            'cancelled', 'refunded', 'failed', 'trash' => 'cancelado',
            default => 'pendiente',
        };
        $items = [];
        foreach ((array) ($d['line_items'] ?? []) as $i) {
            $cant = max(1, (int) ($i['quantity'] ?? 1));
            $items[] = ['nombre' => (string) ($i['name'] ?? 'Producto'), 'precio' => round((float) ($i['total'] ?? 0) / $cant, 2), 'cantidad' => $cant];
        }
        return [
            'id' => (string) ($d['id'] ?? ''), 'numero' => '#' . (string) ($d['number'] ?? $d['id'] ?? ''), 'estado' => $estado,
            'total' => (float) ($d['total'] ?? 0), 'iva' => (float) ($d['total_tax'] ?? 0), 'url' => (string) ($d['_links']['self'][0]['href'] ?? ''),
            'telefono' => (string) ($b['phone'] ?? '') ?: (string) ($s['phone'] ?? ''),
            'nombre' => trim((string) ($b['first_name'] ?? $s['first_name'] ?? '') . ' ' . (string) ($b['last_name'] ?? $s['last_name'] ?? '')),
            'checkout' => '', 'items' => $items, 'origen' => 'woocommerce',
        ];
    }

    private static function pedido(PDO $db, array $linea, array $x, DateTimeImmutable $ahora): string
    {
        if ($x['id'] === '') {
            return 'Pedido sin id: ignorado';
        }
        $lid = (int) $linea['id'];
        $tel = Telefono::normalizar($x['telefono']);
        $contacto = null;
        if ($tel !== null) {
            $contacto = Contactos::obtener($db, $lid, $tel);
            $db->prepare("UPDATE contactos SET nombre = CASE WHEN nombre = '' THEN ? ELSE nombre END, tipo = CASE WHEN tipo = 'desconocido' THEN 'cliente' ELSE tipo END WHERE id = ?")
               ->execute([mb_substr($x['nombre'], 0, 100), $contacto['id']]);
            $contacto = Contactos::buscar($db, $lid, (int) $contacto['id']);
        }
        if ($x['checkout'] !== '') {
            $db->prepare("UPDATE tienda_carritos SET estado = 'compro' WHERE linea_id = ? AND externo_id = ? AND estado = 'esperando'")->execute([$lid, $x['checkout']]);
        }
        $st = $db->prepare('SELECT * FROM pedidos WHERE linea_id = ? AND origen = ? AND externo_id = ?');
        $st->execute([$lid, $x['origen'], $x['id']]);
        $antes = $st->fetch() ?: null;
        $total = round($x['total'], 2);
        $iva = round($x['iva'], 2);
        $db->beginTransaction();
        try {
            if ($antes === null) {
                $id = Pedidos::crear($db, $lid, $contacto ? (int) $contacto['id'] : null, $x['estado'], $x['origen'], null,
                                     ['externo_id' => $x['id'], 'externo_url' => mb_substr($x['url'], 0, 500), 'cliente_nombre' => mb_substr($x['nombre'], 0, 100),
                                      'notas' => 'Pedido ' . $x['numero'] . ' de ' . self::TIPOS[$x['origen']]]);
            } else {
                $id = (int) $antes['id'];
                $db->prepare('DELETE FROM pedido_items WHERE pedido_id = ?')->execute([$id]);
                $db->prepare('UPDATE pedidos SET estado = ?, contacto_id = COALESCE(contacto_id, ?), externo_url = ?, actualizado = now() WHERE id = ?')
                   ->execute([$x['estado'], $contacto ? (int) $contacto['id'] : null, mb_substr($x['url'], 0, 500), $id]);
            }
            foreach (array_slice($x['items'], 0, 200) as $i) {
                $db->prepare('INSERT INTO pedido_items (pedido_id, nombre, precio, cantidad) VALUES (?, ?, ?, ?)')
                   ->execute([$id, mb_substr($i['nombre'], 0, 120), max(0, round($i['precio'], 2)), min(9999, $i['cantidad'])]);
            }
            $db->prepare("UPDATE pedidos SET total = ?, iva = ?, subtotal = ?, pagado_en = CASE WHEN ? IN ('pagado', 'enviado', 'entregado') AND pagado_en IS NULL THEN ? ELSE pagado_en END WHERE id = ?")
               ->execute([$total, $iva, round($total - $iva, 2), $x['estado'], $ahora->format('c'), $id]);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        // Aviso por WhatsApp: pedido nuevo, o recién enviado
        $plantilla = '';
        if ($antes === null && $x['estado'] !== 'cancelado') {
            $plantilla = (string) $linea['tienda_plantilla_pedido'];
        } elseif ($antes !== null && in_array($x['estado'], ['enviado', 'entregado'], true) && !in_array($antes['estado'], ['enviado', 'entregado'], true)) {
            $plantilla = (string) $linea['tienda_plantilla_envio'];
        }
        $que = ($antes === null ? 'Pedido nuevo ' : 'Pedido actualizado ') . $x['numero'] . ' (' . Pedidos::ESTADOS[$x['estado']] . ')';
        if ($plantilla === '') {
            return $que;
        }
        if ($contacto === null) {
            return $que . ': sin teléfono, no se avisó por WhatsApp';
        }
        $r = self::mandarPlantilla($db, $linea, $contacto, $plantilla, [$x['nombre'] !== '' ? explode(' ', $x['nombre'])[0] : 'cliente', $x['numero'],
                                                                         '$' . number_format($total, 2, ',', '.'), $x['url'] !== '' ? $x['url'] : $x['numero']], $ahora);
        return $que . ($r === '' ? ': avisado por WhatsApp' : ': no se avisó (' . $r . ')');
    }

    private static function carrito(PDO $db, array $linea, array $d, DateTimeImmutable $ahora): string
    {
        $token = (string) ($d['token'] ?? $d['id'] ?? '');
        if ($token === '' || !empty($d['completed_at'])) {
            return 'Carrito terminado o sin id: ignorado';
        }
        $dir = (array) ($d['shipping_address'] ?? []) + (array) ($d['billing_address'] ?? []);
        $cli = (array) ($d['customer'] ?? []);
        $tel = Telefono::normalizar((string) ($d['phone'] ?? '') ?: (string) ($dir['phone'] ?? '') ?: (string) ($cli['phone'] ?? ''));
        $nombre = trim((string) ($dir['first_name'] ?? $cli['first_name'] ?? ''));
        $horas = max(1, (int) $linea['tienda_carrito_horas']);
        $db->prepare("INSERT INTO tienda_carritos (linea_id, externo_id, telefono, nombre, total, url, avisar_en, estado) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                      ON CONFLICT (linea_id, externo_id) DO UPDATE SET telefono = EXCLUDED.telefono, nombre = EXCLUDED.nombre, total = EXCLUDED.total, url = EXCLUDED.url,
                          estado = CASE WHEN tienda_carritos.estado IN ('esperando', 'sin_telefono') THEN EXCLUDED.estado ELSE tienda_carritos.estado END")
           ->execute([(int) $linea['id'], mb_substr($token, 0, 100), $tel ?? '', mb_substr($nombre, 0, 100), round((float) ($d['total_price'] ?? 0), 2),
                      mb_substr((string) ($d['abandoned_checkout_url'] ?? ''), 0, 1000), $ahora->modify("+$horas hours")->format('c'), $tel === null ? 'sin_telefono' : 'esperando']);
        return $tel === null ? 'Carrito guardado sin teléfono (no se puede avisar)' : 'Carrito guardado: se avisará en ' . $horas . ' h si no compra';
    }

    /** Cron cada minuto: manda el recordatorio de los carritos que ya esperaron. */
    public static function procesarCarritos(PDO $db, ?DateTimeImmutable $ahora = null): array
    {
        $ahora ??= new DateTimeImmutable();
        $r = ['avisado' => 0, 'omitido' => 0, 'fallido' => 0];
        $st = $db->prepare("SELECT t.*, l.id AS lid FROM tienda_carritos t JOIN lineas l ON l.id = t.linea_id WHERE t.estado = 'esperando' AND t.avisar_en <= ? ORDER BY t.avisar_en LIMIT 50");
        $st->execute([$ahora->format('c')]);
        foreach ($st->fetchAll() as $c) {
            $linea = Lineas::buscar($db, (int) $c['lid']);
            $poner = fn (string $e, string $res) => $db->prepare('UPDATE tienda_carritos SET estado = ?, resultado = ? WHERE id = ?')->execute([$e, mb_substr($res, 0, 300), $c['id']]);
            if (!$linea || (string) $linea['tienda_plantilla_carrito'] === '' || !Empresas::alDiaPorLinea($db, $linea)) {
                $poner('omitido', 'Sin plantilla de carrito o cuenta no al día');
                $r['omitido']++;
                continue;
            }
            $contacto = Contactos::obtener($db, (int) $linea['id'], (string) $c['telefono']);
            if ($contacto['marketing'] === 'baja' || $contacto['tipo'] === 'personal') {
                $poner('omitido', 'Pidió no recibir promociones');
                $r['omitido']++;
                continue;
            }
            if ($contacto['nombre'] === '' && $c['nombre'] !== '') {
                $db->prepare('UPDATE contactos SET nombre = ? WHERE id = ?')->execute([$c['nombre'], $contacto['id']]);
            }
            $err = self::mandarPlantilla($db, $linea, $contacto, (string) $linea['tienda_plantilla_carrito'],
                                         [$c['nombre'] !== '' ? $c['nombre'] : 'hola', '$' . number_format((float) $c['total'], 2, ',', '.'), $c['url'] !== '' ? $c['url'] : (string) $linea['nombre']], $ahora);
            if ($err === '') {
                $poner('avisado', 'Recordatorio enviado');
                $r['avisado']++;
            } else {
                $poner('fallido', $err);
                $r['fallido']++;
            }
        }
        return $r;
    }

    /** Manda una plantilla aprobada ("nombre|idioma") llenando sus espacios en orden. Devuelve '' si salió bien o el error. */
    private static function mandarPlantilla(PDO $db, array $linea, array $contacto, string $plantilla, array $valores, DateTimeImmutable $ahora): string
    {
        [$nombre, $idioma] = explode('|', $plantilla . '|');
        $lista = Meta::deLinea($linea)->plantillas();
        if (!$lista['ok']) {
            $db->prepare('UPDATE lineas SET tienda_error = ? WHERE id = ?')->execute([mb_substr($lista['error'], 0, 300), (int) $linea['id']]);
            return $lista['error'];
        }
        foreach ($lista['plantillas'] as $p) {
            if ($p['nombre'] === $nombre && $p['idioma'] === $idioma) {
                $datos = array_map(fn ($v) => mb_substr((string) $v, 0, 200), array_slice(array_pad($valores, $p['espacios'], '-'), 0, $p['espacios']));
                $res = (new Mensajeria($db, $ahora))->plantilla($linea, $contacto, $p, $datos, 'tienda');
                return $res['ok'] ? '' : (string) $res['error'];
            }
        }
        $err = 'La plantilla "' . $nombre . '" no está aprobada en Meta';
        $db->prepare('UPDATE lineas SET tienda_error = ? WHERE id = ?')->execute([$err, (int) $linea['id']]);
        return $err;
    }

    /** Últimos carritos (para la pantalla). */
    public static function carritos(PDO $db, int $lineaId, int $limite = 30): array
    {
        $st = $db->prepare('SELECT * FROM tienda_carritos WHERE linea_id = ? ORDER BY creado DESC LIMIT ' . max(1, $limite));
        $st->execute([$lineaId]);
        return $st->fetchAll();
    }
}
