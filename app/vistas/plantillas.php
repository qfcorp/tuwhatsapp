<?php
/** @var array $linea @var list<array> $plantillas @var ?string $errorMeta @var array $precios @var array $form @var ?array $volverChat */
use TuWhatsApp\Contactos;
use TuWhatsApp\Gasto;
use TuWhatsApp\Meta;
use TuWhatsApp\Plantillas;
$f = fn (string $k, string $def = '') => (string) ($form[$k] ?? $def);
$fa = fn (string $k, int $i, string $def = '') => (string) ($form[$k][$i] ?? $def);
$claseEstado = ['APPROVED' => 'ok', 'PENDING' => 'espera', 'IN_APPEAL' => 'espera', 'REJECTED' => 'mal', 'PAUSED' => 'mal', 'DISABLED' => 'mal'];
?>
<?php if ($volverChat): ?>
<div class="chat-cabeza">
    <a href="<?= e(url('plantilla', ['c' => $volverChat['id']])) ?>" class="volver" aria-label="Volver">‹</a>
    <div><h1>Plantillas</h1><p class="suave">Volver a enviar a <?= u(Contactos::nombre($volverChat)) ?></p></div>
</div>
<?php else: ?>
<div class="cabeza"><h1>Plantillas</h1></div>
<?php endif; ?>
<p class="suave">Las plantillas son mensajes que Meta aprueba antes. Sirven para escribir a alguien que no te ha escrito en 24 horas (recordatorios, cobros, promociones). Meta revisa cada una: normalmente tarda de minutos a 24 horas.</p>

<details class="tarjeta plegable" id="nueva" <?= $form || !$plantillas ? 'open' : '' ?>>
    <summary>Crear plantilla nueva</summary>
    <form method="post" class="formulario" id="form-nueva-plantilla">
        <?= csrf_campo() ?><input type="hidden" name="accion" value="crear">
        <label>Nombre (solo para ti; minúsculas y _)
            <input name="nombre" required maxlength="100" value="<?= e($f('nombre')) ?>" placeholder="recordatorio_pago">
        </label>
        <label>Tipo
            <select name="categoria" required>
                <?php foreach (Plantillas::CATEGORIAS as $k => $n): ?>
                <option value="<?= $k ?>" <?= $f('categoria', 'UTILITY') === $k ? 'selected' : '' ?>><?= e($n) ?> · <?= e(dinero($precios[$k === 'MARKETING' ? 'marketing' : 'utilidad'], true)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <p class="suave pequeno">Ojo: si el texto vende o promociona algo, Meta lo cambia a Marketing aunque elijas Utilidad.</p>
        <label>Idioma
            <select name="idioma"><?php foreach (Plantillas::IDIOMAS as $k => $n): ?><option value="<?= $k ?>" <?= $f('idioma', 'es') === $k ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select>
        </label>
        <label>Encabezado (opcional, máximo 60 letras)
            <input name="encabezado" maxlength="60" value="<?= e($f('encabezado')) ?>" placeholder="Recordatorio de pago" data-vista="encabezado">
        </label>
        <label>Texto del mensaje
            <textarea name="cuerpo" rows="5" maxlength="1024" required data-vista="cuerpo" placeholder="Hola {{1}}, te recordamos que tu plan vence el {{2}}. ¿Te ayudamos con la renovación?"><?= e($f('cuerpo')) ?></textarea>
        </label>
        <p class="suave pequeno">Para lo que cambia en cada envío (nombre, fecha, valor) escribe <b>{{1}}</b>, <b>{{2}}</b>… en orden. No pueden ir al principio ni al final del texto.</p>
        <div id="ejemplos">
            <?php for ($n = 1; $n <= Plantillas::MAX_VARIABLES; $n++): ?>
            <label data-n="<?= $n ?>">Ejemplo para {{<?= $n ?>}}<input name="ejemplo[<?= $n ?>]" maxlength="200" value="<?= e($fa('ejemplo', $n)) ?>" placeholder="<?= $n === 1 ? 'Ana' : ($n === 2 ? '30 de octubre' : '') ?>"></label>
            <?php endfor; ?>
        </div>
        <label>Pie (opcional, máximo 60 letras)
            <input name="pie" maxlength="60" value="<?= e($f('pie')) ?>" placeholder="Tukán Software" data-vista="pie">
        </label>
        <fieldset class="botones-plantilla">
            <legend>Botones (opcionales)</legend>
            <?php for ($i = 0; $i < 3; $i++): $t = $fa('boton_tipo', $i, 'NINGUNO'); ?>
            <div class="fila-boton">
                <select name="boton_tipo[<?= $i ?>]" aria-label="Botón <?= $i + 1 ?>: tipo">
                    <option value="NINGUNO">Sin botón</option>
                    <option value="QUICK_REPLY" <?= $t === 'QUICK_REPLY' ? 'selected' : '' ?>>Respuesta rápida</option>
                    <option value="URL" <?= $t === 'URL' ? 'selected' : '' ?>>Abrir enlace</option>
                    <option value="PHONE_NUMBER" <?= $t === 'PHONE_NUMBER' ? 'selected' : '' ?>>Llamar</option>
                </select>
                <input name="boton_texto[<?= $i ?>]" maxlength="25" value="<?= e($fa('boton_texto', $i)) ?>" placeholder="Texto del botón" aria-label="Botón <?= $i + 1 ?>: texto">
                <input name="boton_destino[<?= $i ?>]" value="<?= e($fa('boton_destino', $i)) ?>" placeholder="https://… o 0991234567" aria-label="Botón <?= $i + 1 ?>: enlace o teléfono">
            </div>
            <?php endfor; ?>
        </fieldset>
        <p class="suave pequeno">Así se verá:</p>
        <div class="burbuja-vista" id="vista-plantilla" aria-live="polite">
            <b class="vp-encabezado"></b><span class="vp-cuerpo"></span><small class="vp-pie"></small><span class="vp-botones"></span>
        </div>
        <button class="boton boton-grande">Enviar a Meta para aprobar</button>
    </form>
</details>

<?php if ($errorMeta): ?>
<div class="aviso aviso-error"><?= e($errorMeta) ?></div>
<?php elseif (!$plantillas): ?>
<div class="vacio"><p><b>Todavía no tienes plantillas.</b></p><p class="suave">Crea la primera arriba.</p></div>
<?php else: ?>
<h2 class="separador">Mis plantillas (<?= count($plantillas) ?>)</h2>
<ul class="lista-plantillas-admin">
    <?php foreach ($plantillas as $p): ?>
    <li class="tarjeta">
        <div class="pl-cabeza">
            <b><?= u($p['nombre']) ?></b>
            <span class="chip estado-<?= e($claseEstado[$p['estado']] ?? 'espera') ?>"><?= e(Meta::ESTADOS_PLANTILLA[$p['estado']] ?? $p['estado']) ?></span>
        </div>
        <p class="suave pequeno"><?= e(Gasto::CATEGORIAS[$p['categoria']] ?? $p['categoria']) ?> · <?= e(Plantillas::IDIOMAS[$p['idioma']] ?? $p['idioma']) ?> · <?= e(dinero($precios[$p['categoria']] ?? 0, true)) ?> por envío</p>
        <?php if ($p['estado'] === 'REJECTED'): ?>
        <p class="nota">Meta la rechazó<?= $p['motivo'] !== '' ? ' (' . e($p['motivo']) . ')' : '' ?>. Revisa que no tenga promesas exageradas, enlaces raros o variables al inicio o final, y créala otra vez con otro nombre.</p>
        <?php endif; ?>
        <div class="burbuja-vista">
            <?php if ($p['encabezado'] !== ''): ?><b class="vp-encabezado"><?= e($p['encabezado']) ?></b><?php endif; ?>
            <span class="vp-cuerpo"><?= '<!--notr-->' . nl2br(e($p['cuerpo'])) . '<!--/notr-->' ?></span>
            <?php if ($p['pie'] !== ''): ?><small class="vp-pie"><?= e($p['pie']) ?></small><?php endif; ?>
            <?php if ($p['botones']): ?><span class="vp-botones"><?php foreach ($p['botones'] as $b): ?><span><?= u($b['texto']) ?></span><?php endforeach; ?></span><?php endif; ?>
        </div>
        <details class="borrar-plantilla">
            <summary>Borrar</summary>
            <form method="post">
                <?= csrf_campo() ?><input type="hidden" name="accion" value="borrar"><input type="hidden" name="nombre" value="<?= e($p['nombre']) ?>">
                <p class="suave pequeno">Se borra en Meta (en todos los idiomas) y el nombre no se puede volver a usar por unas semanas.</p>
                <button class="boton boton-peligro">Sí, borrar "<?= u($p['nombre']) ?>"</button>
            </form>
        </details>
    </li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>
