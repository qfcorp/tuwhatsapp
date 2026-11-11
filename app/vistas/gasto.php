<?php
/** @var array $linea @var array $resumen @var array $precios */
use TuWhatsApp\Gasto;
$r = $resumen;
$origenes = ['auto' => 'Respuestas automáticas', 'bot' => 'Agente con IA', 'flujo' => 'Chatbots', 'campania' => 'Campañas', 'tienda' => 'Avisos de la tienda en línea', 'panel' => 'Enviados desde aquí', 'pendiente' => 'Pendientes por el sistema', 'celular' => 'Desde el celular'];
$meses = ['01' => 'enero', '02' => 'febrero', '03' => 'marzo', '04' => 'abril', '05' => 'mayo', '06' => 'junio', '07' => 'julio', '08' => 'agosto', '09' => 'septiembre', '10' => 'octubre', '11' => 'noviembre', '12' => 'diciembre'];
?>
<div class="cabeza tk-titulo-pagina"><h1>Gasto en Meta</h1></div>
<section class="tarjeta tk-tarjeta gasto-grande <?= $r['pct'] >= 100 ? 'lleno' : ($r['pct'] >= (int) $linea['aviso_pct'] ? 'alerta' : '') ?>">
    <p class="suave">Este mes (<?= e($meses[date('m')]) ?>)</p>
    <p class="cifra"><?= e(dinero($r['gastado'])) ?> <small>de <?= e(dinero($r['tope'])) ?> · aproximado</small></p>
    <div class="tope-barra grande"><span style="width: <?= (int) $r['pct'] ?>%"></span></div>
    <p><?= $r['pct'] >= 100 ? '<b>Llegaste al tope.</b> No se envían plantillas pagadas hasta el próximo mes (o hasta que subas el tope). Las respuestas dentro de las 24 horas siguen saliendo gratis.'
        : 'Te quedan <b>' . e(dinero($r['queda'])) . '</b>: unas ' . (int) floor($r['queda'] / max(0.0001, $precios['utilidad'])) . ' plantillas de utilidad o ' . (int) floor($r['queda'] / max(0.0001, $precios['marketing'])) . ' de marketing. Las respuestas dentro de las 24 horas son gratis.' ?></p>
    <div class="aviso aviso-info pequeno gasto-aprox">
        <b>Es un cálculo aproximado</b> de WhatsApp Tukán con los precios de Meta (abajo), para el tope y los avisos. <b>El gasto real y tu factura</b> están en Meta:
        <a href="https://business.facebook.com/billing_hub/" target="_blank" rel="noopener">business.facebook.com → Facturación y pagos</a>
        (o WhatsApp Manager → Información → Precios). Meta le cobra directo a la tarjeta que pusiste ahí.
    </div>
    <?php if ($r['bloqueados'] > 0): ?><p class="suave"><?= (int) $r['bloqueados'] === 1 ? '1 mensaje no se envió' : (int) $r['bloqueados'] . ' mensajes no se enviaron' ?> por el tope este mes.</p><?php endif; ?>
</section>

<?php if (es_admin()): ?>
<form method="post" class="tarjeta tk-tarjeta formulario">
    <?= csrf_campo() ?><input type="hidden" name="accion" value="tope">
    <h2>Tope del mes</h2>
    <p class="suave">Al llegar al tope el sistema deja de enviar mensajes pagados solo. Nunca te pasas.</p>
    <div class="fila-doble">
        <label>Tope (dólares al mes)<input name="tope" inputmode="decimal" value="<?= e(number_format((float) $linea['tope_mensual'], 2, '.', '')) ?>" required></label>
        <label>Avisarme al llegar al (%)<input type="number" name="aviso_pct" min="10" max="100" value="<?= (int) $linea['aviso_pct'] ?>" required></label>
    </div>
    <p class="suave pequeno">El aviso sale arriba en el panel<?= getenv('TELEGRAM_BOT_TOKEN') ? ' y por Telegram' : '' ?>.</p>
    <button class="boton tk-boton">Guardar tope</button>
</form>
<?php endif; ?>

<?php if ($r['por_origen']): ?>
<section class="tarjeta tk-tarjeta">
    <h2>En qué se gastó</h2>
    <table class="tabla tk-tabla">
        <thead><tr><th>De dónde</th><th>Mensajes</th><th>Costo</th></tr></thead>
        <tbody>
        <?php foreach ($r['por_origen'] as $o => $x): ?>
        <tr><td><?= e($origenes[$o] ?? $o) ?></td><td><?= (int) $x['n'] ?></td><td><?= e(dinero($x['costo'], true)) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <table class="tabla tk-tabla">
        <thead><tr><th>Tipo (Meta)</th><th>Mensajes</th><th>Costo</th></tr></thead>
        <tbody>
        <?php foreach ($r['por_categoria'] as $c => $x): ?>
        <tr><td><?= e(Gasto::CATEGORIAS[$c] ?? 'Gratis (celular)') ?></td><td><?= (int) $x['n'] ?></td><td><?= e(dinero($x['costo'], true)) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php endif; ?>

<?php if (count($r['meses']) > 1): ?>
<section class="tarjeta tk-tarjeta">
    <h2>Meses anteriores</h2>
    <table class="tabla tk-tabla">
        <tbody>
        <?php foreach ($r['meses'] as $m): ?>
        <tr><td><?= e(ucfirst($meses[substr($m['mes'], 5, 2)]) . ' ' . substr($m['mes'], 0, 4)) ?></td><td><?= (int) $m['n'] ?> mensajes</td><td><?= e(dinero($m['costo'])) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php endif; ?>

<?php if (es_super()): ?>
<details class="tarjeta tk-tarjeta plegable">
    <summary>Precios de Meta por mensaje</summary>
    <form method="post" class="formulario">
        <?= csrf_campo() ?><input type="hidden" name="accion" value="precios">
        <p class="suave">Precios para números de Ecuador (dólares por mensaje entregado). Si Meta los cambia, actualízalos aquí: los ves en business.facebook.com → WhatsApp Manager → Información sobre precios.</p>
        <p class="suave pequeno"><b>Respuestas normales dentro de las 24 horas:</b> gratis (Meta no las cobra). Solo se pagan las plantillas.</p>
        <input type="hidden" name="precio[servicio]" value="0">
        <?php foreach (Gasto::CATEGORIAS as $k => $n): if ($k === 'servicio') continue; ?>
        <label><?= e($n) ?><input name="precio[<?= $k ?>]" inputmode="decimal" value="<?= e(rtrim(rtrim(number_format($precios[$k], 5, '.', ''), '0'), '.')) ?>" required></label>
        <?php endforeach; ?>
        <button class="boton tk-boton">Guardar precios</button>
    </form>
</details>
<?php endif; ?>
