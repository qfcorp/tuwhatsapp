<?php
/** @var array $linea @var array $prep */
use TuWhatsApp\Gasto;
$p = $prep['plantilla'];
$noAlcanza = $prep['precio'] > 0 && $prep['alcanza'] < $prep['total'];
?>
<div class="chat-cabeza">
    <a href="<?= e(url('campanias')) ?>" class="volver" aria-label="Volver">‹</a>
    <div><h1>Revisar campaña</h1><p class="suave"><?= u($prep['nombre']) ?></p></div>
</div>
<section class="tarjeta">
    <p class="cifra-grande"><b><?= (int) $prep['total'] ?></b> contactos</p>
    <p class="suave"><?= e(\TuWhatsApp\Campanias::FILTROS[$prep['filtro_tipo']]) ?><?= $prep['filtro_etiqueta'] !== '' ? ' con la etiqueta "' . e($prep['filtro_etiqueta']) . '"' : '' ?><?= $prep['solo_acepta'] ? ', solo los que aceptaron promociones' : ' (incluye a quien no ha aceptado promociones)' ?>. Sin personales ni quien pidió la baja.</p>
    <p>Costo aproximado: <b><?= e(dinero($prep['costo'])) ?></b> (<?= e(Gasto::CATEGORIAS[$p['categoria']] ?? $p['categoria']) ?>, <?= e(dinero($prep['precio'], true)) ?> cada uno) · tardará unos <?= (int) $prep['minutos'] ?> minutos.</p>
    <p>Te quedan <b><?= e(dinero($prep['queda'])) ?></b> del tope del mes.</p>
    <?php if ($noAlcanza): ?>
    <p class="nota">Con el tope actual alcanza para unos <b><?= (int) $prep['alcanza'] ?></b> mensajes. Cuando llegue al tope la campaña se pausa sola; puedes subir el tope en Gasto y reanudarla.</p>
    <?php endif; ?>
    <?php if (!$prep['solo_acepta']): ?>
    <p class="nota">Ojo: vas a escribir también a quien no aceptó promociones. Si te reportan, Meta puede limitar tu número.</p>
    <?php endif; ?>
    <p class="suave pequeno">Así le llega a "Ana López":</p>
    <div class="burbuja-vista"><span class="vp-cuerpo"><?= '<!--notr-->' . nl2br(e($prep['ejemplo'])) . '<!--/notr-->' ?></span></div>
    <?php if ($prep['total'] > 0): ?>
    <form method="post">
        <?= csrf_campo() ?><input type="hidden" name="accion" value="lanzar"><input type="hidden" name="total" value="<?= (int) $prep['total'] ?>">
        <button class="boton boton-grande boton-pago">Enviar a <?= (int) $prep['total'] ?> contactos</button>
    </form>
    <?php else: ?>
    <p class="nota">No hay contactos con esas condiciones. Marca en Contactos quién aceptó promociones o cambia el filtro.</p>
    <?php endif; ?>
    <p><a href="<?= e(url('campanias')) ?>">Cambiar algo</a></p>
</section>
