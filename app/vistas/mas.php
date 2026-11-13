<?php
/**
 * Todo lo que no cabe en la barra de abajo. Todo el programa viene incluido; solo la IA se cobra aparte
 * (si no la tiene, se ve con candado y el botón para pedirla).
 * [ruta, nombre, para qué sirve, solo administrador]
 */
$grupos = menu_mas_grupos();   // (10 nov 2026) la misma lista arma el menú de la izquierda (app/bootstrap.php)
$conIa = modulo('ia');
?>
<div class="cabeza tk-titulo-pagina"><h1>Más</h1></div>
<?php foreach ($grupos as $titulo => $items): ?>
<h2 class="separador"><?= e($titulo) ?></h2>
<ul class="mosaico">
    <?php foreach ($items as [$ruta, $nombre, $texto, $soloAdmin]): if ($soloAdmin && !es_admin()) continue; ?>
    <?php if ($ruta === 'tufactura' && !modulo('facturacion')): ?>
    <li><a href="<?= e(url($ruta)) ?>"><b><?= e($nombre) ?> <small class="chip">Próximamente</small></b><span><?= e($texto) ?></span></a></li>
    <?php elseif (in_array($ruta, ['ia', 'asistente'], true) && !$conIa): ?>
    <li class="bloqueado"><div><b><?= e($nombre) ?> <small class="chip">Extra</small></b><span><?= e($texto) ?></span>
        <?php if (en_app_tienda()): /* app de Google Play o de iPhone: sin enlaces para pedir o pagar el programa afuera */ ?><span class="pequeno suave">No está activo en tu cuenta.</span>
        <?php else: ?><a class="pequeno" href="https://wa.me/593983662181?text=<?= rawurlencode('Hola, quiero activar el Agente con IA en WhatsApp Tukán.') ?>" target="_blank" rel="noopener">Pedir a Tukán</a><?php endif; ?></div></li>
    <?php else: ?>
    <li><a href="<?= e(url($ruta)) ?>"><b><?= e($nombre) ?><?= in_array($ruta, ['ia', 'asistente'], true) ? ' <small class="chip chip-cliente">Activo</small>' : '' ?></b><span><?= e($texto) ?></span></a></li>
    <?php endif; ?>
    <?php endforeach; ?>
</ul>
<?php endforeach; ?>
