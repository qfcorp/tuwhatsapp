<?php /** @var array $linea @var list<array> $respuestas */ ?>
<div class="cabeza"><h1>Respuestas rápidas</h1></div>
<p class="suave">Textos que tu equipo usa seguido (precios, dirección, horarios, cuentas para pagar). En el chat se eligen de una lista y se pegan solos.</p>
<details class="tarjeta plegable" <?= !$respuestas ? 'open' : '' ?>>
    <summary>Nueva respuesta</summary>
    <form method="post" class="formulario">
        <?= csrf_campo() ?>
        <label>Nombre corto<input name="atajo" required maxlength="40" placeholder="precios"></label>
        <label>Texto<textarea name="texto" rows="4" required maxlength="4000" placeholder="Nuestros planes empiezan en..."></textarea></label>
        <button class="boton">Guardar</button>
    </form>
</details>
<?php foreach ($respuestas as $rr): ?>
<details class="tarjeta plegable">
    <summary>/<?= e($rr['atajo']) ?> <span class="suave">· <?= e(mb_strimwidth($rr['texto'], 0, 50, '…')) ?></span></summary>
    <form method="post" class="formulario">
        <?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int) $rr['id'] ?>">
        <label>Nombre corto<input name="atajo" required maxlength="40" value="<?= e($rr['atajo']) ?>"></label>
        <label>Texto<textarea name="texto" rows="4" required maxlength="4000"><?= e($rr['texto']) ?></textarea></label>
        <div class="fila-botones">
            <button class="boton">Guardar</button>
            <button class="boton boton-claro" name="accion" value="borrar" formnovalidate>Borrar</button>
        </div>
    </form>
</details>
<?php endforeach; ?>
