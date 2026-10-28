<?php
/** @var array $linea @var array $pluxAviso */
$tienePayphone = (string) $linea['payphone_token'] !== '';
$tienePlux = \TuWhatsApp\Plux::configurado($linea);
$cobro = (string) $linea['cobro_tarjeta'];
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
        <p class="suave pequeno">Puedes usar <code>{total}</code> y <code>{pedido}</code> en el enlace. Si conectas Plux o Payphone, se usa eso (un cobro por pedido con el monto exacto).</p>
        <label>Cobrar con tarjeta usando<select name="cobro_tarjeta">
            <option value="" <?= $cobro === '' ? 'selected' : '' ?>>Lo que esté conectado</option>
            <option value="plux" <?= $cobro === 'plux' ? 'selected' : '' ?>>Pago Plux<?= $tienePlux ? '' : ' (falta conectarlo)' ?></option>
            <option value="payphone" <?= $cobro === 'payphone' ? 'selected' : '' ?>>Payphone<?= $tienePayphone ? '' : ' (falta conectarlo)' ?></option>
        </select></label>
    </section>

    <section class="tarjeta" id="plux">
        <h2>Pago Plux (tarjeta de crédito o débito)</h2>
        <p class="suave pequeno">Cada pedido tiene su <b>página de pago</b> con el valor exacto. El cliente paga con su tarjeta y el pedido pasa solo a <b>Pagado</b>;
        le llega un WhatsApp de confirmación y a tu equipo un aviso en el celular. El dinero cae en <b>tu</b> cuenta de Plux.</p>
        <?php if ($tienePlux): ?>
        <p class="aviso <?= \TuWhatsApp\Plux::ambiente($linea) === 'produccion' ? 'aviso-ok' : 'aviso-info' ?> pequeno"><?= \TuWhatsApp\Plux::ambiente($linea) === 'produccion'
            ? '✅ Plux conectado y cobrando de verdad (producción).' : 'Plux conectado en <b>pruebas</b>: no cobra de verdad. Cuando Plux certifique tu instalación, cambia a Producción.' ?></p>
        <?php endif; ?>
        <?php if ((string) $linea['plux_error'] !== ''): ?><p class="aviso aviso-error pequeno"><?= e($linea['plux_error']) ?></p><?php endif; ?>
        <details <?= $tienePlux ? '' : 'open' ?>>
            <summary><b>Cómo inscribirte en Plux (paso a paso)</b></summary>
            <ol class="pequeno">
                <li>Entra a <a href="https://www.plux.ec/contactos/" target="_blank" rel="noopener">plux.ec/contactos</a> y pide abrir tu cuenta de <b>Pago Plux</b> como comercio
                    (soporte de Plux: 099 845 2058). Plux te pide los documentos de tu negocio y te dice la lista exacta.</li>
                <li>Elige tu plan con Plux. En plux.ec (octubre 2026) hay dos: <b>porcentual</b> (arranque $99 + IVA; crédito 1,50 %, débito 0,50 % + IVA)
                    o <b>fijo</b> (arranque $99 + IVA, $49,99 + IVA al mes y $0,40 + IVA por pago). Confirma los valores con Plux.</li>
                <li>Diles que vas a cobrar con la <b>integración por API de tarjetas</b> (paymentCardResource) de <b>WhatsApp Tukán</b> y pásales los datos del cuadro de abajo.</li>
                <li>Plux te entrega 4 datos: <b>id de establecimiento</b>, <b>id de cliente</b>, <b>clave secreta</b> y <b>llave pública</b>. Pégalos aquí abajo y guarda.</li>
                <li>En el panel de Plux → <b>Developers → Web Hook</b>, registra el aviso de pago (dirección, usuario y clave de abajo).</li>
                <li>Haz un pago de prueba en <b>Pruebas</b> (Plux te da tarjetas de prueba). Plux revisa y <b>certifica</b> tu instalación.</li>
                <li>Cuando Plux te confirme, cambia aquí a <b>Producción</b>. Desde ahí cobra de verdad.</li>
            </ol>
            <div class="datos-plux">
                <p><b>Datos para darle a Plux</b> (cópialos tal cual):</p>
                <ul>
                    <li>Plataforma: WhatsApp Tukán (Tukán Software, QFCORP S.A.S.) · Integración: API de tarjetas v1.3 (credentials/paymentCardResource) con OTP y 3D Secure</li>
                    <li>Dominio del cobro: <code><?= e(parse_url($pluxAviso['url'], PHP_URL_HOST)) ?></code></li>
                    <li>Página de pago: <code><?= e($pluxAviso['pagina']) ?></code> (una por pedido)</li>
                    <li>Regreso del banco (3D Secure), <b>sin parámetros</b>: <code><?= e($pluxAviso['retorno3ds']) ?></code></li>
                    <li>Aviso de pago (Web Hook): <code><?= e($pluxAviso['url']) ?></code> · Usuario: <code><?= e($pluxAviso['usuario']) ?></code> · Clave: <code><?= e($pluxAviso['clave']) ?></code></li>
                    <li>Manejo de la tarjeta: los datos se cifran en el navegador del comprador (AES-256 con una clave al azar, y esa clave con la llave pública RSA de Plux)
                        y van cifrados a Plux. WhatsApp Tukán <b>no guarda</b> el número, la fecha ni el CVV en ninguna base de datos ni registro.</li>
                </ul>
            </div>
        </details>
        <div class="fila-doble">
            <label>Id de establecimiento<input name="plux_establecimiento" maxlength="100" autocomplete="off" value="<?= e($linea['plux_establecimiento']) ?>"></label>
            <label>Id de cliente<input name="plux_cliente" maxlength="200" autocomplete="off" value="<?= e($linea['plux_cliente']) ?>"></label>
        </div>
        <label>Clave secreta<input name="plux_clave" type="password" autocomplete="off" maxlength="500" placeholder="<?= (string) $linea['plux_clave'] !== '' ? 'Guardada (escribe otra para cambiarla)' : 'Pega la clave secreta' ?>"></label>
        <label>Llave pública<textarea name="plux_llave" rows="3" maxlength="4000" placeholder="MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA…"><?= e($linea['plux_llave']) ?></textarea></label>
        <label>Ambiente<select name="plux_ambiente">
            <option value="sandbox" <?= \TuWhatsApp\Plux::ambiente($linea) === 'sandbox' ? 'selected' : '' ?>>Pruebas (no cobra de verdad)</option>
            <option value="produccion" <?= \TuWhatsApp\Plux::ambiente($linea) === 'produccion' ? 'selected' : '' ?>>Producción (cobra de verdad: solo después de que Plux certifique)</option>
        </select></label>
        <?php if ($tienePlux): ?><label class="check"><input type="checkbox" name="quitar_plux" value="1"> Quitar Plux</label><?php endif; ?>
        <p class="suave pequeno">La clave secreta se guarda cifrada.</p>
    </section>

    <section class="tarjeta">
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
