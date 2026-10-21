<?php
/** @var array $linea @var list<array> $abiertos @var list<array> $recientes */
use TuWhatsApp\Telefono;
?>
<div class="cabeza"><h1>Pendientes</h1><span class="suave"><?= count($abiertos) ?> por enviar</span></div>
<p class="suave">Mensajes para mandar <b>gratis</b> desde tu celular. Toca <b>Abrir en WhatsApp</b>: se abre el chat con el texto listo y solo presionas <b>Enviar</b>.</p>

<?php if (!$abiertos): ?>
<div class="vacio"><p><b>No tienes mensajes pendientes.</b></p></div>
<?php else: ?>
<ul class="pendientes">
    <?php foreach ($abiertos as $p): ?>
    <li class="tarjeta pendiente" data-id="<?= (int) $p['id'] ?>">
        <div class="pend-cabeza">
            <b><?= e($p['nombre'] !== '' ? $p['nombre'] : Telefono::bonito($p['telefono'])) ?></b>
            <span class="suave"><?= e(Telefono::bonito($p['telefono'])) ?><?= $p['origen'] !== 'manual' ? ' · ' . e($p['origen']) : '' ?></span>
        </div>
        <p class="pend-texto"><?= nl2br(e($p['texto'])) ?></p>
        <a class="boton boton-gratis boton-grande abrir-wa" href="<?= e(Telefono::enlace($p['telefono'], $p['texto'])) ?>" target="_blank" rel="noopener">Abrir en WhatsApp</a>
        <div class="pend-pregunta" hidden>
            <span>¿Lo enviaste?</span>
            <button type="button" class="boton marcar" data-estado="enviado">Sí, enviado</button>
            <button type="button" class="boton boton-claro no-aun">Todavía no</button>
        </div>
        <form method="post" class="pend-acciones">
            <?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
            <button class="enlace" name="accion" value="enviado">Marcar enviado</button>
            <button class="enlace enlace-rojo" name="accion" value="descartado">Descartar</button>
        </form>
    </li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>

<details class="tarjeta plegable" <?= !$abiertos ? 'open' : '' ?>>
    <summary>Agregar un mensaje a la lista</summary>
    <form method="post" class="formulario">
        <?= csrf_campo() ?><input type="hidden" name="accion" value="agregar">
        <label>Número<input name="telefono" inputmode="tel" required placeholder="0991234567"></label>
        <label>Nombre (opcional)<input name="nombre" maxlength="100"></label>
        <label>Mensaje<textarea name="texto" rows="4" required maxlength="4000"></textarea></label>
        <button class="boton">Agregar</button>
    </form>
</details>

<?php if ($recientes): ?>
<h2 class="separador">Últimos hechos</h2>
<ul class="lista-simple">
    <?php foreach ($recientes as $p): ?>
    <li>
        <span><b><?= e($p['nombre'] !== '' ? $p['nombre'] : Telefono::bonito($p['telefono'])) ?></b> · <?= $p['estado'] === 'enviado' ? 'enviado' : 'descartado' ?> <?= e(hora_corta($p['hecho_en'])) ?><br>
        <span class="suave"><?= e(mb_strimwidth($p['texto'], 0, 90, '…')) ?></span></span>
        <form method="post"><?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int) $p['id'] ?>"><button class="enlace" name="accion" value="pendiente">Volver a pendientes</button></form>
    </li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>
