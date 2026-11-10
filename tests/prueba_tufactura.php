<?php
declare(strict_types=1);

/**
 * Factura electrónica con TuFactura (extra «facturacion», 9 nov 2026), contra el TuFactura SIMULADO (tests/tufactura_simulado.php):
 * viene apagado (ni la demo ni ningún plan lo trae), se enciende, se conecta la clave del número, cada pedido PAGADO se factura
 * (a mano o solo al pagarse), nunca dos veces, con la cédula de los campos del cliente; apagado no factura.
 * Uso: bash tests/correr.sh (después de la lógica, en la misma base de prueba).
 */
putenv('TUWA_LLAVE=' . str_repeat('ab', 32));
require dirname(__DIR__) . '/app/bootstrap.php';

use TuWhatsApp\{Catalogo, Contactos, Empresas, FacturaTukan, Lineas, Modulos, Pedidos, TukanCentralV2};

$bien = 0; $mal = 0;
function igual(mixed $a, mixed $b, string $que): void
{
    global $bien, $mal;
    $ok = is_float($a) || is_float($b) ? abs((float) $a - (float) $b) < 0.001 : $a === $b;
    if ($ok) { $bien++; echo "  OK    $que\n"; } else { $mal++; echo "  FALLA $que (esperaba " . var_export($b, true) . ', salió ' . var_export($a, true) . ")\n"; }
}
if (!str_contains((string) getenv('TUWA_DB_NAME'), 'prueba')) exit("Por seguridad, solo en una base de prueba.\n");
$db = db();

$libre = stream_socket_server('tcp://127.0.0.1:0');   // un puerto libre (las pruebas de otros programas pueden correr a la vez)
$puerto = (int) (getenv('TF_SIM_PUERTO') ?: substr(strrchr(stream_socket_get_name($libre, false), ':'), 1));
fclose($libre);
$simDir = sys_get_temp_dir() . '/tf_sim_tuwa_' . getmypid();
@mkdir($simDir);
$proc = proc_open(['php', '-S', "127.0.0.1:$puerto", __DIR__ . '/tufactura_simulado.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pp, null, ['TF_SIM_DIR' => $simDir] + getenv());
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $puerto); $i++) usleep(100000);
$sim = "http://127.0.0.1:$puerto";

echo "== Factura electrónica con TuFactura (extra facturacion)\n";
igual(isset(Modulos::LISTA['facturacion']), true, 'El extra existe');
igual(in_array('facturacion', Modulos::todos(), true), false, 'La demo con todo no lo trae');
igual(in_array('facturacion', Modulos::PLANES['todo_ia'][4], true), false, 'Ningún plan lo trae (ni Todo incluido + IA)');
igual((float) Modulos::PRECIOS['facturacion'], 0.0, 'Precio en el programa: $0 (TuFactura se paga aparte)');
$tag = bin2hex(random_bytes(3));
$e = Empresas::crear($db, ['nombre' => 'Tienda Factura ' . $tag, 'estado' => 'activa', 'admin_correo' => "tf$tag@tienda.ec", 'admin_nombre' => 'Dueña'])['empresa_id'];
$lid = Lineas::guardarConexion($db, null, ['nombre' => 'Tienda Factura', 'telefono' => '0996402222', 'phone_number_id' => '9' . random_int(10000, 99999), 'waba_id' => '201', 'token' => 'EAAGtokenFactura1234567890'], $e);
$linea = Lineas::buscar($db, $lid);
Modulos::aplicarPlan($db, $e, 'todo_ia');
igual(FacturaTukan::activo($db, $lid), false, 'Viene APAGADO');
$prod = Catalogo::guardar($db, $lid, ['nombre' => 'Vestido azul', 'precio' => '23', 'stock' => '', 'activo' => '1']);
$cli = Contactos::guardar($db, $lid, '0981000077', 'Lucía Andrade', 'cliente');
$db->prepare('UPDATE contactos SET datos = ? WHERE id = ?')->execute([json_encode(['cedula' => '1710034065', 'correo' => 'lucia@ejemplo.com', 'ciudad' => 'Quito']), (int) $cli['id']]);
$pedido = function (int $cant = 1) use ($db, &$linea, $cli, $prod): int {
    $car = Pedidos::agregar($db, $linea, (int) $cli['id'], $prod, $cant);
    return (int) $car['id'];
};
$p0 = $pedido();
igual(FacturaTukan::datos($db, $lid, (string) $p0), null, 'Carrito: no hay nada que facturar');
Pedidos::cambiarEstado($db, $linea, $p0, 'pagado');
$tf = FacturaTukan::enlace($db);
igual($tf->deOrigen($lid, 'pedido', (string) $p0), null, 'Con el extra apagado, pagar no factura');

$db->prepare("UPDATE empresas SET modulos = 'ia,facturacion' WHERE id = ?")->execute([$e]);
igual(FacturaTukan::activo($db, $lid), true, 'Encendido desde el Panel');
$cat = (new TukanCentralV2($db))->modulos();
igual(in_array('facturacion', array_column($cat['modulos'], 'id'), true), true, 'Panel: aparece en el catálogo de extras');
igual($cat['planes'][1]['todos'] ?? null, true, 'Panel: «Todo incluido + IA» sigue siendo «todo» (la factura va a pedido)');

[$c, $items, $extra] = FacturaTukan::datos($db, $lid, (string) $p0);
igual($c['identificacion'], '1710034065', 'Datos: la cédula de los campos del cliente');
igual($c['correo'], 'lucia@ejemplo.com', 'Datos: el correo de los campos');
igual($c['nombre'], 'Lucía Andrade', 'Datos: el nombre del cliente');
igual([$items[0]['descripcion'], (float) $items[0]['precio'], $items[0]['cantidad']], ['Vestido azul', 23.0, 1], 'Datos: el producto con precio final (IVA incluido)');
igual(FacturaTukan::datos($db, $lid + 999, (string) $p0), null, 'Otro número no ve el pedido');
igual(FacturaTukan::cobros($db, $lid)[0][0] ?? null, (string) $p0, 'Pedidos pagados: aparece');

$con = $tf->conectar($lid, 'tf_' . str_repeat('c3', 20), $sim);
igual($con['empresa'], 'EMPRESA DE PRUEBA S.A.', 'Conectado con TuFactura (simulado)');
$d = $tf->facturar($lid, 'pedido', (string) $p0, ...FacturaTukan::datos($db, $lid, (string) $p0));
igual($d['estado'], 'autorizado', 'Factura a mano: autorizada');
igual(abs((float) $d['total'] - 23.0) <= 0.011, true, 'Factura: total = lo cobrado (23,00) o 1 centavo menos');
igual(json_decode((string) $d['envio'], true)['cliente']['identificacion'], '1710034065', 'A nombre de la cédula del cliente');
igual((int) $tf->facturar($lid, 'pedido', (string) $p0, ...FacturaTukan::datos($db, $lid, (string) $p0))['id'], (int) $d['id'], 'El mismo pedido no se factura dos veces');

// Número que suma el IVA aparte: precios sin IVA con su tarifa
$db->prepare('UPDATE lineas SET iva_pct = 15 WHERE id = ?')->execute([$lid]);
$linea = Lineas::buscar($db, $lid);
$p1 = $pedido(2);
Pedidos::cambiarEstado($db, $linea, $p1, 'pagado');
igual($tf->deOrigen($lid, 'pedido', (string) $p1), null, 'Sin «automático» no factura solo');
$tf->opciones($lid, true, true, 15);
$p2 = $pedido(2);
Pedidos::cambiarEstado($db, $linea, $p2, 'pendiente');
Pedidos::cambiarEstado($db, $linea, $p2, 'pagado');
$d2 = $tf->deOrigen($lid, 'pedido', (string) $p2);
igual($d2['estado'] ?? null, 'autorizado', 'Automático: al pagar sale la factura sola');
igual((float) ($d2['total'] ?? 0), (float) Pedidos::buscar($db, $lid, $p2)['total'], 'IVA aparte: la factura suma lo mismo que el pedido (46 + 15 %)');
Pedidos::cambiarEstado($db, $linea, $p2, 'enviado');
igual(count(array_filter($tf->lista($lid), fn($x) => $x['origen_id'] === (string) $p2)), 1, 'Enviar el pedido no factura otra vez');
$p3 = $pedido();
Pedidos::cambiarEstado($db, $linea, $p3, 'cancelado');
igual($tf->deOrigen($lid, 'pedido', (string) $p3), null, 'Pedido cancelado: no se factura');

// Sin cédula: consumidor final
$db->prepare("UPDATE contactos SET datos = '{}' WHERE id = ?")->execute([(int) $cli['id']]);
$p4 = $pedido();
Pedidos::cambiarEstado($db, $linea, $p4, 'pagado');
igual(json_decode((string) ($tf->deOrigen($lid, 'pedido', (string) $p4)['envio'] ?? '{}'), true)['cliente']['identificacion'] ?? null, '9999999999999', 'Cliente sin cédula: consumidor final');

$db->prepare("UPDATE empresas SET modulos = 'ia' WHERE id = ?")->execute([$e]);
$p5 = $pedido();
Pedidos::cambiarEstado($db, $linea, $p5, 'pagado');
igual($tf->deOrigen($lid, 'pedido', (string) $p5), null, 'Apagado: ya no factura solo');
igual(count($tf->lista($lid)), 3, 'Apagado: las facturas hechas se quedan');

proc_terminate($proc);
array_map('unlink', glob("$simDir/*") ?: []);
@rmdir($simDir);
echo "\nTuFactura: $bien bien, $mal mal\n";
exit($mal ? 1 : 0);
