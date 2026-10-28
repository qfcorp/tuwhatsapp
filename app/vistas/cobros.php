<?php
/** @var array $linea */
$tienePayphone = (string) $linea['payphone_token'] !== '';
?>
<div class="cabeza"><h1>Cobros y cotizaciones</h1></div>
<form method="post" class="formulario">
    <?= csrf_campo() ?>
    <section class="tarjeta">
        <h2>Datos del negocio (salen en la cotización)</h2>
        <div class="fila-doble">
            <label>RUC<input name="negocio_ruc" maxlength="20" value="<?= e($linea['negocio_ruc']) ?>"></label>
            <label>IVA que se suma (%)<input name="iva_pct" inputmode="decimal" maxlength="6" value="<?= e(rtrim(rtrim(number_format((float) $linea['iva_pct'], 2, ',', ''), '0'), ',')) ?>"></label>
        </div>
        <p class="suave pequeno">IVA en 0 = tus precios ya son finales. Si pones 15, al subtotal se le suma el 15 %.</p>
        <label>Dirección<input name="negocio_direccion" maxlength="200" value="<?= e($linea['negocio_direccion']) ?>"></label>
        <div class="fila-doble">
            <label>La cotización vale (días)<input name="cotizacion_dias" type="number" min="1" max="365" value="<?= (int) $linea['cotizacion_dias'] ?>"></label>
        </div>
        <label>Condiciones al pie de la cotización<textarea name="cotizacion_pie" rows="2" maxlength="1000" placeholder="Precios en dólares. El pedido se confirma con el pago."><?= e($linea['cotizacion_pie']) ?></textarea></label>
    </section>

    <section class="tarjeta">
        <h2>Formas de pago</h2>
        <label>Datos para transferencia<textarea name="pago_transferencia" rows="3" maxlength="1000" placeholder="Banco Pichincha · Cuenta de ahorros 2201234567&#10;A nombre de: Mi Negocio · RUC 1790000000001"><?= e($linea['pago_transferencia']) ?></textarea></label>
        <label>Enlace de pago fijo (DeUna, PayPal, Kushki…)<input name="pago_enlace" maxlength="500" value="<?= e($linea['pago_enlace']) ?>" placeholder="https://paypal.me/minegocio/{total}"></label>
        <p class="suave pequeno">Puedes usar <code>{total}</code> y <code>{pedido}</code> en el enlace. Si conectas Payphone, se usa Payphone (un enlace por pedido con el monto exacto).</p>
        <h2>Payphone (enlace de cobro por cada pedido)</h2>
        <div class="fila-doble">
            <label>Token de Payphone Developer<input name="payphone_token" type="password" autocomplete="off" maxlength="2000" placeholder="<?= $tienePayphone ? 'Guardado (escribe otro para cambiarlo)' : 'Pega el token' ?>"></label>
            <label>Store ID (opcional)<input name="payphone_store" maxlength="80" value="<?= e($linea['payphone_store']) ?>"></label>
        </div>
        <?php if ($tienePayphone): ?><label class="check"><input type="checkbox" name="quitar_payphone" value="1"> Quitar Payphone</label><?php endif; ?>
        <p class="suave pequeno">En <b>Payphone Developer</b> → tu aplicación (tipo API) → copia el <b>token</b> y el <b>Store ID</b>. El token se guarda cifrado.</p>
    </section>
    <button class="boton boton-grande">Guardar</button>
</form>
