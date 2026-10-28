<?php
/** @var array $linea @var ?array $consulta @var list<array> $historial @var ?array $resumen */
use TuWhatsApp\Copiloto;
?>
<div class="cabeza"><h1>Pregúntale a la IA</h1></div>
<p class="suave">No hagas clic, solo pregunta. La IA lee las cifras y las conversaciones de este número y te contesta; si le pides personas o una campaña, te las deja listas.</p>

<form method="post" class="tarjeta formulario pregunta-ia">
    <?= csrf_campo() ?><input type="hidden" name="accion" value="preguntar">
    <label class="oculto" for="pregunta">Tu pregunta</label>
    <textarea id="pregunta" name="pregunta" rows="2" maxlength="500" required placeholder="¿Quiénes están listos para comprar?"><?= e($consulta['pregunta'] ?? '') ?></textarea>
    <div class="chips-ia">
        <?php foreach (Copiloto::SUGERENCIAS as $s): ?>
        <button type="button" class="chip chip-boton" onclick="var t=document.getElementById('pregunta'); t.value=this.textContent; t.form.submit()"><?= e($s) ?></button>
        <?php endforeach; ?>
    </div>
    <button class="boton">✨ Preguntar</button>
</form>

<?php if ($consulta): ?>
<section class="tarjeta respuesta-ia" id="respuesta">
    <p class="suave pequeno">Preguntaste: <b><?= u($consulta['pregunta']) ?></b></p>
    <div class="texto-ia"><?= nl2br(u($consulta['respuesta'] ?? '')) ?></div>
    <?php if (!empty($consulta['contactos'])): ?>
    <h2><?= count($consulta['contactos']) ?> clientes</h2>
    <ul class="lista-ia">
        <?php foreach ($consulta['contactos'] as $c): ?>
        <li><a href="<?= e(url('chat', ['c' => $c['id']])) ?>"><b><?= u($c['nombre']) ?></b></a><?= $c['motivo'] !== '' ? ' <span class="suave pequeno">· ' . u($c['motivo']) . '</span>' : '' ?></li>
        <?php endforeach; ?>
    </ul>
    <?php if (es_admin()): ?>
    <form method="post" class="fila-botones">
        <?= csrf_campo() ?><input type="hidden" name="accion" value="etiqueta"><input type="hidden" name="q" value="<?= (int) $consulta['id'] ?>">
        <label class="en-linea">Guardarlos con la etiqueta <input name="etiqueta" maxlength="40" value="<?= e($consulta['etiqueta'] ?? 'grupo-ia') ?>"></label>
        <button class="boton boton-claro">Guardar etiqueta</button>
    </form>
    <?php endif; ?>
    <?php endif; ?>
    <?php if (!empty($consulta['campania'])): ?>
    <h2>Campaña propuesta</h2>
    <div class="burbuja-propuesta"><?= nl2br(u($consulta['campania']['texto'])) ?></div>
    <p class="suave pequeno">Plantilla «<?= e($consulta['campania']['nombre']) ?>» · <?= $consulta['campania']['categoria'] === 'UTILITY' ? 'Utilidad' : 'Marketing' ?>. Meta tiene que aprobarla antes de mandarla a muchos.</p>
    <?php if (es_admin()): ?>
    <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="campania"><input type="hidden" name="q" value="<?= (int) $consulta['id'] ?>">
        <button class="boton">Crear esta campaña</button></form>
    <?php endif; ?>
    <?php endif; ?>
</section>
<?php endif; ?>

<section class="tarjeta">
    <h2>☀️ Resumen de hoy</h2>
    <?php if ($resumen): ?>
    <div class="texto-ia"><?= nl2br(u($resumen['texto'])) ?></div>
    <p class="suave pequeno">Hecho a las <?= e((new DateTimeImmutable((string) $resumen['creado']))->format('H:i')) ?><?= $resumen['con_ia'] ? '' : ' (solo cifras: la IA no estaba disponible)' ?>.</p>
    <?php else: ?>
    <p class="suave">Todavía no hay resumen de hoy.</p>
    <?php endif; ?>
    <form method="post" class="fila-botones"><?= csrf_campo() ?><input type="hidden" name="accion" value="resumen">
        <button class="boton boton-claro"><?= $resumen ? 'Hacerlo de nuevo' : 'Hacer el resumen ahora' ?></button></form>
    <?php if (es_admin()): ?>
    <form method="post" class="formulario">
        <?= csrf_campo() ?><input type="hidden" name="accion" value="ajustes">
        <label class="check"><input type="checkbox" name="resumen_diario" value="1" <?= $linea['resumen_diario'] ? 'checked' : '' ?>> Mandarme el resumen al celular cada mañana</label>
        <label>A qué hora<input name="resumen_hora" type="time" value="<?= e($linea['resumen_hora']) ?>"></label>
        <p class="suave pequeno">Le llega a los administradores que activaron la app en el celular (Más → App en el celular). Cuenta cómo fue ayer, lo que quedó pendiente y 3 cosas para hacer hoy.</p>
        <button class="boton boton-claro">Guardar</button>
    </form>
    <?php endif; ?>
</section>

<?php if ($historial): ?>
<section class="tarjeta">
    <h2>Lo último que preguntaron</h2>
    <ul class="lista-ia">
        <?php foreach ($historial as $h): ?>
        <li><a href="<?= e(url('asistente', ['q' => $h['id']])) ?>#respuesta"><?= u($h['pregunta']) ?></a> <span class="suave pequeno">· <?= e(hora_corta((string) $h['creado'])) ?></span></li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>
