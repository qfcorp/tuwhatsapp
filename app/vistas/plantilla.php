<?php
/** @var array $linea @var array $c @var list<array> $plantillas @var ?string $errorMeta @var array $precios */
use TuWhatsApp\Contactos;
use TuWhatsApp\Gasto;
?>
<div class="chat-cabeza">
    <a href="<?= e(url('chat', ['c' => $c['id']])) ?>" class="volver" aria-label="Volver al chat">‹</a>
    <div><h1>Enviar plantilla</h1><p class="suave">a <?= e(Contactos::nombre($c)) ?></p></div>
</div>
<p class="suave">Aquí salen solo las plantillas que Meta ya aprobó. <a href="<?= e(url('plantillas', ['c' => $c['id']])) ?>">Crear o ver todas las plantillas</a>.</p>
<?php if ($errorMeta): ?>
<div class="aviso aviso-error"><?= e($errorMeta) ?></div>
<?php elseif (!$plantillas): ?>
<div class="vacio"><p><b>No tienes plantillas aprobadas todavía.</b></p><p><a class="boton" href="<?= e(url('plantillas', ['c' => $c['id']])) ?>">Crear una plantilla</a></p></div>
<?php else: ?>
<form method="post" class="formulario" id="form-plantilla">
    <?= csrf_campo() ?>
    <fieldset class="lista-plantillas">
        <legend>Elige una</legend>
        <?php foreach ($plantillas as $i => $p): ?>
        <label class="opcion-plantilla">
            <input type="radio" name="plantilla" value="<?= e($p['nombre'] . '|' . $p['idioma']) ?>" data-espacios="<?= (int) $p['espacios'] ?>" <?= $i === 0 ? 'checked' : '' ?>>
            <span>
                <b><?= e($p['nombre']) ?></b> <small class="chip"><?= e(Gasto::CATEGORIAS[$p['categoria']] ?? $p['categoria']) ?> · <?= e(dinero($precios[$p['categoria']] ?? 0, true)) ?></small>
                <span class="cuerpo-plantilla"><?= nl2br(e($p['cuerpo'])) ?></span>
            </span>
        </label>
        <?php endforeach; ?>
    </fieldset>
    <div id="datos-plantilla">
        <?php for ($i = 1; $i <= max(array_column($plantillas, 'espacios') ?: [0]); $i++): ?>
        <label data-n="<?= $i ?>">Dato {{<?= $i ?>}}<input name="dato[]" maxlength="500"></label>
        <?php endfor; ?>
    </div>
    <button class="boton boton-pago">Enviar plantilla</button>
</form>
<?php endif; ?>
