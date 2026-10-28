<?php
/** @var array $linea @var array $p @var list<array> $items @var list<array> $productos @var bool $ventana */
use TuWhatsApp\Pedidos;
$editable = in_array($p['estado'], ['carrito', 'pendiente'], true) && !in_array($p['origen'], ['shopify', 'woocommerce'], true);
$siguientes = ['carrito' => ['pendiente' => 'Confirmar (pendiente de pago)', 'pagado' => 'Marcar pagado'], 'pendiente' => ['pagado' => 'Marcar pagado', 'carrito' => 'Volver a carrito'],
               'pagado' => ['enviado' => 'Marcar enviado', 'entregado' => 'Marcar entregado'], 'enviado' => ['entregado' => 'Marcar entregado']][$p['estado']] ?? [];
?>
<div class="chat-cabeza">
    <a href="<?= e(url('pedidos')) ?>" class="volver" aria-label="Volver a pedidos">‹</a>
    <div><h1>Pedido #<?= (int) $p['numero'] ?></h1>
    <p class="suave"><?= u(Pedidos::nombreCliente($p)) ?> · <small class="chip estado-<?= e($p['estado']) ?>"><?= e(Pedidos::ESTADOS[$p['estado']]) ?></small>
    <?php if ($p['contacto_id']): ?> · <a href="<?= e(url('chat', ['c' => $p['contacto_id']])) ?>">Abrir chat</a><?php endif; ?></p></div>
</div>
<?php if (in_array($p['origen'], ['shopify', 'woocommerce'], true)): ?>
<p class="nota">Pedido de <?= $p['origen'] === 'shopify' ? 'Shopify' : 'WooCommerce' ?>: los productos y el estado los manda la tienda.<?= $p['externo_url'] ? ' <a href="' . e($p['externo_url']) . '" target="_blank" rel="noopener">Ver en la tienda</a>' : '' ?></p>
<?php endif; ?>

<section class="tarjeta">
    <h2>Productos</h2>
    <?php if (!$items): ?><p class="suave">Todavía no tiene productos.</p><?php endif; ?>
    <ul class="lista-simple items-pedido">
        <?php foreach ($items as $i): ?>
        <li><span><?= u($i['nombre']) ?><br><span class="suave pequeno"><?= e(dinero($i['precio'])) ?> c/u</span></span>
            <?php if ($editable): ?>
            <form method="post" class="cantidad"><?= csrf_campo() ?><input type="hidden" name="accion" value="cantidad"><input type="hidden" name="item" value="<?= (int) $i['id'] ?>">
                <label class="oculto" for="cant<?= (int) $i['id'] ?>">Cantidad</label>
                <input id="cant<?= (int) $i['id'] ?>" name="cantidad" type="number" min="0" max="999" value="<?= (int) $i['cantidad'] ?>"><button class="boton boton-claro">✓</button></form>
            <?php else: ?><span>x <?= (int) $i['cantidad'] ?></span><?php endif; ?>
            <b class="derecha"><?= e(dinero((float) $i['precio'] * (int) $i['cantidad'])) ?></b></li>
        <?php endforeach; ?>
    </ul>
    <p class="totales"><?php if ((float) $p['iva'] > 0): ?>Subtotal <?= e(dinero($p['subtotal'])) ?> · IVA <?= e(dinero($p['iva'])) ?> · <?php endif; ?><b>Total <?= e(dinero($p['total'])) ?></b></p>
    <?php if ($editable): ?>
    <form method="post" class="formulario fila-agregar"><?= csrf_campo() ?><input type="hidden" name="accion" value="agregar">
        <div class="fila-doble">
            <label>Producto<select name="producto_id" required><option value="">Elegir…</option>
                <?php foreach ($productos as $x): ?><option value="<?= (int) $x['id'] ?>"><?= e($x['nombre']) ?> · <?= e(dinero($x['precio'])) ?><?= $x['stock'] !== null ? ' (' . (int) $x['stock'] . ')' : '' ?></option><?php endforeach; ?>
            </select></label>
            <label>Cantidad<input name="cantidad" type="number" min="1" max="999" value="1"></label>
        </div>
        <button class="boton boton-claro">Agregar</button>
    </form>
    <details class="plegable-simple"><summary>Agregar algo que no está en el catálogo (envío, servicio)</summary>
        <form method="post" class="formulario"><?= csrf_campo() ?><input type="hidden" name="accion" value="libre">
            <div class="fila-doble"><label>Qué es<input name="nombre" maxlength="120" required placeholder="Envío a Quito"></label>
            <label>Precio ($)<input name="precio" inputmode="decimal" required placeholder="3,50"></label></div>
            <button class="boton boton-claro">Agregar</button></form>
    </details>
    <?php endif; ?>
</section>

<section class="tarjeta">
    <h2>Enviar al cliente</h2>
    <?php if (!$p['contacto_id']): ?><p class="suave">Este pedido no tiene un número de WhatsApp.</p>
    <?php else: ?>
    <div class="fila-botones">
        <form method="post" class="en-linea"><?= csrf_campo() ?><input type="hidden" name="accion" value="enviar_resumen">
            <button class="boton" <?= $ventana && $items ? '' : 'disabled' ?>>Mandar resumen con cómo pagar</button></form>
        <form method="post" class="en-linea"><?= csrf_campo() ?><input type="hidden" name="accion" value="enviar_cotizacion">
            <button class="boton boton-claro" <?= $ventana && $items ? '' : 'disabled' ?>>Mandar cotización PDF</button></form>
        <a class="boton boton-claro" href="<?= e(url('cotizacion', ['id' => $p['id']])) ?>">Descargar PDF</a>
    </div>
    <?php if (!$ventana): ?><p class="suave pequeno">Pasaron más de 24 horas desde su último mensaje: descarga el PDF y mándalo desde el celular, o envíale una plantilla primero.</p><?php endif; ?>
    <?php if ($p['enlace_pago']): ?><p class="pequeno">Enlace de pago: <a href="<?= e($p['enlace_pago']) ?>" target="_blank" rel="noopener"><?= e($p['enlace_pago']) ?></a></p><?php endif; ?>
    <?php endif; ?>
    <?php $pasoPlux = ['otp' => 'el cliente está escribiendo el código de su banco', '3ds' => 'el cliente está en la validación de su banco',
                       'fallido' => 'el último intento con tarjeta no se aprobó', 'rechazado' => 'Plux avisó que el pago fue rechazado'][$p['plux_paso']] ?? ''; ?>
    <?php if ($p['metodo'] === 'plux' && $p['plux_paso'] === 'pagado'): ?>
    <p class="aviso aviso-ok pequeno">💳 Pagado con tarjeta por Plux<?= $p['plux_transaccion'] !== '' ? ' · transacción ' . e($p['plux_transaccion']) : '' ?><?= $p['pagado_en'] ? ' · ' . e(hora_corta((string) $p['pagado_en'])) : '' ?></p>
    <?php elseif ($pasoPlux !== '' && in_array($p['estado'], ['carrito', 'pendiente'], true)): ?>
    <p class="aviso aviso-info pequeno">💳 Plux: <?= e($pasoPlux) ?>.</p>
    <?php endif; ?>
</section>

<?php if ($siguientes || $p['estado'] !== 'cancelado'): ?>
<section class="tarjeta">
    <h2>Estado</h2>
    <form method="post" class="fila-botones"><?= csrf_campo() ?><input type="hidden" name="accion" value="estado">
        <?php foreach ($siguientes as $k => $n): ?><button class="boton <?= $k === 'carrito' ? 'boton-claro' : '' ?>" name="estado" value="<?= e($k) ?>"><?= e($n) ?></button><?php endforeach; ?>
        <?php if (!in_array($p['estado'], ['entregado', 'cancelado'], true)): ?><button class="boton boton-peligro" name="estado" value="cancelado" onclick="return confirm('¿Cancelar el pedido? Si ya se descontó el stock, se devuelve.')">Cancelar pedido</button><?php endif; ?>
    </form>
    <p class="suave pequeno">Al confirmar se descuenta el stock; al cancelar se devuelve.<?= $p['pagado_en'] ? ' Pagado el ' . e((new DateTimeImmutable($p['pagado_en']))->format('d/m/Y H:i')) . '.' : '' ?></p>
</section>
<?php endif; ?>

<form method="post" class="tarjeta formulario"><?= csrf_campo() ?><input type="hidden" name="accion" value="notas">
    <label>Notas (salen en la cotización)<textarea name="notas" rows="2" maxlength="1000"><?= e($p['notas']) ?></textarea></label>
    <button class="boton boton-claro">Guardar notas</button></form>
