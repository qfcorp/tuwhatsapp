<?php
/** @var array $linea @var list<array> $plantillas @var ?string $errorMeta @var list<array> $carritos @var list<array> $pedidosTienda */
use TuWhatsApp\{Pedidos, Tiendas};
$tipo = (string) $linea['tienda_tipo'];
$opcionesPlantilla = function (string $sel) use ($plantillas): string {
    $h = '<option value="">No avisar</option>';
    foreach ($plantillas as $p) {
        $v = $p['nombre'] . '|' . $p['idioma'];
        $h .= '<option value="' . e($v) . '"' . ($sel === $v ? ' selected' : '') . '>' . e($p['nombre'] . ' (' . $p['idioma'] . ') · ' . $p['espacios'] . ' datos') . '</option>';
    }
    return $h;
};
$estadosCarrito = ['esperando' => 'Esperando', 'avisado' => 'Avisado', 'compro' => 'Compró', 'sin_telefono' => 'Sin teléfono', 'omitido' => 'No se avisó', 'fallido' => 'Falló'];
?>
<div class="cabeza"><h1>Tienda en línea</h1></div>
<p class="suave">Conecta tu tienda de <b>Shopify</b> o <b>WooCommerce</b>: cada pedido aparece en Pedidos y al cliente le llega un WhatsApp
("recibimos tu pedido", "tu pedido va en camino"). Con Shopify, además, se recupera el <b>carrito abandonado</b>: si no termina de comprar, le llega un recordatorio con el enlace.</p>
<?php if ($linea['tienda_error'] !== ''): ?><p class="aviso aviso-error">Último problema: <?= e($linea['tienda_error']) ?></p><?php endif; ?>
<?php if ($errorMeta): ?><p class="aviso aviso-error">No se pudieron traer las plantillas de Meta: <?= e($errorMeta) ?></p><?php endif; ?>

<form method="post" class="tarjeta formulario">
    <?= csrf_campo() ?>
    <label>Tu tienda<select name="tienda_tipo">
        <option value="">Sin tienda conectada</option>
        <?php foreach (Tiendas::TIPOS as $k => $n): ?><option value="<?= e($k) ?>" <?= $tipo === $k ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?>
    </select></label>
    <label>Clave secreta con la que firma tu tienda<input name="tienda_secreto" type="password" autocomplete="off" maxlength="300" placeholder="<?= $linea['tienda_secreto'] !== '' ? 'Guardada (escribe otra para cambiarla)' : 'Pégala aquí' ?>"></label>
    <h2>Mensajes que se mandan (plantillas aprobadas)</h2>
    <p class="suave pequeno">Los datos de la plantilla se llenan en orden: <code>{{1}}</code> nombre, <code>{{2}}</code> número de pedido (o total en el carrito), <code>{{3}}</code> total (o enlace en el carrito), <code>{{4}}</code> enlace del pedido.
    Créalas en <a href="<?= e(url('plantillas')) ?>">Plantillas</a> (pedido y envío: categoría Utilidad; carrito: Marketing).</p>
    <label>Pedido recibido<select name="tienda_plantilla_pedido"><?= $opcionesPlantilla((string) $linea['tienda_plantilla_pedido']) ?></select></label>
    <label>Pedido enviado<select name="tienda_plantilla_envio"><?= $opcionesPlantilla((string) $linea['tienda_plantilla_envio']) ?></select></label>
    <div class="fila-doble">
        <label>Carrito abandonado (solo Shopify)<select name="tienda_plantilla_carrito"><?= $opcionesPlantilla((string) $linea['tienda_plantilla_carrito']) ?></select></label>
        <label>Avisar después de (horas)<input name="tienda_carrito_horas" type="number" min="1" max="72" value="<?= (int) $linea['tienda_carrito_horas'] ?>"></label>
    </div>
    <button class="boton">Guardar</button>
</form>

<?php if ($tipo !== '' && $linea['tienda_clave'] !== ''): ?>
<section class="tarjeta">
    <h2>Pega esta dirección en tu tienda</h2>
    <p><code class="copiar"><?= e(Tiendas::url($linea)) ?></code></p>
    <?php if ($tipo === 'shopify'): ?>
    <ol class="pasos-cortos">
        <li>En Shopify entra a <b>Configuración → Notificaciones → Webhooks</b> y toca <b>Crear webhook</b>.</li>
        <li>Crea uno por cada evento: <b>Creación de pedido</b>, <b>Pago de pedido</b>, <b>Preparación de pedido</b> (enviado), <b>Cancelación de pedido</b>, <b>Creación de pantalla de pago</b> y <b>Actualización de pantalla de pago</b> (carritos).</li>
        <li>Formato <b>JSON</b> y la dirección de arriba.</li>
        <li>Abajo de la lista, Shopify dice <i>"Tus webhooks serán firmados con…"</i>: copia esa clave y pégala aquí arriba en "Clave secreta".</li>
    </ol>
    <?php else: ?>
    <ol class="pasos-cortos">
        <li>En WordPress entra a <b>WooCommerce → Ajustes → Avanzado → Webhooks</b> y toca <b>Añadir webhook</b>.</li>
        <li>Crea dos: tema <b>Pedido creado</b> y tema <b>Pedido actualizado</b>, estado <b>Activo</b>.</li>
        <li>En "URL de entrega" pega la dirección de arriba.</li>
        <li>En "Secreto" escribe una clave larga y pega la misma aquí arriba en "Clave secreta".</li>
    </ol>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($pedidosTienda): ?>
<h2 class="separador">Últimos pedidos de la tienda</h2>
<ul class="lista-simple">
    <?php foreach ($pedidosTienda as $p): ?>
    <li><a href="<?= e(url('pedido', ['id' => $p['id']])) ?>"><span><b>#<?= (int) $p['numero'] ?> · <?= u(Pedidos::nombreCliente($p)) ?></b><br><span class="suave pequeno"><?= u($p['notas']) ?></span></span>
        <span class="derecha"><b><?= e(dinero($p['total'])) ?></b><br><small class="chip"><?= e(Pedidos::ESTADOS[$p['estado']]) ?></small></span></a></li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>
<?php if ($carritos): ?>
<h2 class="separador">Carritos abandonados</h2>
<ul class="lista-simple">
    <?php foreach ($carritos as $c): ?>
    <li><span><b><?= u($c['nombre'] ?: 'Sin nombre') ?></b> · <?= e(dinero($c['total'])) ?><br><span class="suave pequeno"><?= e(hora_corta($c['creado'])) ?><?= $c['resultado'] ? ' · ' . e($c['resultado']) : '' ?></span></span>
        <small class="chip"><?= e($estadosCarrito[$c['estado']] ?? $c['estado']) ?></small></li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>
