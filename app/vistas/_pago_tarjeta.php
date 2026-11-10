<?php
/**
 * (10 nov 2026) Pagar el plan con tarjeta, sin esperar a Tukán (src/PagosCentral.php). Se usa en «Mi cuenta» y en la pantalla
 * de cuenta vencida. Solo se incluye si el Panel Tukán Central dice que el pago con tarjeta está activo, fuera de la app de Android.
 * @var array $pago lo de PagosCentral::pantalla()  @var array $empresa
 */
$fechaPago = fn (?string $f): string => $f && strtotime(substr($f, 0, 10)) ? date('d/m/Y', strtotime(substr($f, 0, 10))) : '';
$conIva = fn (float $v): float => round($v * (1 + $pago['iva'] / 100), 2);
?>
<div class="pago-tarjeta" id="pago-tarjeta">
    <?php if ($pago['pruebas']): ?>
    <p class="nota">Pago con tarjeta en <b>modo de prueba</b>: no se cobra de verdad.</p>
    <?php endif; ?>
    <?php foreach ($pago['cobros'] as $c): $esSus = ($c['tipo'] ?? '') === 'suscripcion'; $est = (string) ($c['estado'] ?? ''); ?>
    <div class="nota nota-ok cobro-tarjeta">
        💳 <b><?= e((string) ($c['descripcion'] ?? 'Tu plan')) ?></b> — <?= e((string) ($c['estado_texto'] ?? $est)) ?> · <?= e(dinero((float) ($c['total'] ?? 0))) ?> con IVA
        <?php if ($esSus && !empty($c['cobra_sola'])): ?> · se cobra sola a tu tarjeta cada mes<?php endif; ?>
        <?php if ($esSus && !empty($c['pagado_hasta'])): ?> · pagada hasta el <?= e($fechaPago((string) $c['pagado_hasta'])) ?><?php endif; ?>
        <?php if (!empty($c['cancelacion_programada'])): ?><br><small>Cancelación pendiente: se cancela sola en unos minutos.</small><?php endif; ?>
        <?php if (!empty($c['url_pago']) && preg_match('#^https?://#', (string) $c['url_pago'])): ?>
        <br><a class="boton" href="<?= e((string) $c['url_pago']) ?>"><?= $est === 'rechazado' ? 'Intentar con otra tarjeta' : 'Terminar el pago' ?></a>
        <?php endif; ?>
        <?php if ($esSus && in_array($est, ['pendiente', 'rechazado'], true) && empty($c['cancelacion_programada'])): ?>
        <form method="post" class="en-linea"><?= csrf_campo() ?><input type="hidden" name="accion" value="cancelar_tarjeta"><input type="hidden" name="id" value="<?= (int) ($c['id'] ?? 0) ?>">
            <button class="boton boton-claro">Quitar este cobro</button></form>
        <?php elseif ($esSus && in_array($est, ['activa', 'en_gracia'], true) && empty($c['cancelacion_programada'])): ?>
        <br><small>Para que no se cobre más, usa «Cancelar mi plan» aquí abajo: se cancela al momento y sigues usando lo que ya pagaste.</small>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <?php if (!$pago['suscripcion']): ?>
    <form method="post" class="formulario pagar-plan">
        <?= csrf_campo() ?><input type="hidden" name="accion" value="pagar_tarjeta">
        <h3><?= $empresa['estado'] === 'demo' ? 'Sigue con tu cuenta: elige tu plan y paga con tarjeta' : 'Paga tu plan con tarjeta' ?></h3>
        <?php foreach ($pago['planes'] as $k => $p): ?>
        <label class="check plan-opcion"><input type="radio" name="plan" value="<?= e($k) ?>" <?= $k === $pago['elegido'] ? 'checked' : '' ?> required>
            <span><b><?= e($p['nombre']) ?></b> — <?= e(dinero($p['precio'])) ?> al mes + IVA <small class="suave">(<?= e(dinero($conIva($p['precio']))) ?> con IVA)</small><br>
            <small class="suave"><?= e($p['incluye']) ?></small></span></label>
        <?php endforeach; ?>
        <p class="suave pequeno">Es una <b>suscripción mensual</b>: se cobra sola a tu tarjeta cada mes (el valor del plan + IVA) hasta que la canceles aquí mismo, cuando quieras. Sin contrato.
            Los datos de la tarjeta se ponen en la página segura de pagos; aquí no se guardan. La factura te llega al correo.</p>
        <button class="boton boton-grande">Pagar con tarjeta</button>
    </form>
    <?php endif; ?>
</div>
