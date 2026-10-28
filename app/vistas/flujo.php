<?php
/** @var array $linea @var array $f @var list<array> $pasos @var list<array> $campos @var ?list<array> $prueba @var string $entradas */
use TuWhatsApp\{Embudo, Flujos};
$etiquetaPaso = function (array $p, int $n): string {
    return 'Paso ' . $n . ': ' . mb_strimwidth(trim(preg_replace('/\s+/', ' ', (string) $p['texto'])) ?: Flujos::TIPOS[$p['tipo']], 0, 34, '…');
};
$destinos = function (string $actual, string $sel, bool $conSiguiente = true) use ($pasos, $etiquetaPaso): string {
    $h = $conSiguiente ? '<option value=""' . ($sel === '' ? ' selected' : '') . '>El paso que sigue</option>' : '<option value="">Elegir…</option>';
    foreach ($pasos as $k => $p) {
        if ($p['id'] !== $actual) {
            $h .= '<option value="' . e($p['id']) . '"' . ($sel === $p['id'] ? ' selected' : '') . '>' . e($etiquetaPaso($p, $k + 1)) . '</option>';
        }
    }
    return $h . '<option value="fin"' . ($sel === 'fin' ? ' selected' : '') . '>Terminar</option>';
};
?>
<div class="chat-cabeza">
    <a href="<?= e(url('flujos')) ?>" class="volver" aria-label="Volver a chatbots">‹</a>
    <div><h1><?= u($f['nombre']) ?></h1><p class="suave"><?= $f['activo'] ? 'Encendido' : 'Apagado' ?> · usado <?= (int) $f['usos'] ?> veces</p></div>
</div>

<form method="post" class="formulario editor-flujo">
    <?= csrf_campo() ?>
    <section class="tarjeta">
        <label>Nombre<input name="nombre" maxlength="80" required value="<?= e($f['nombre']) ?>"></label>
        <div class="fila-doble">
            <label>Se activa con<select name="disparador">
                <option value="palabras" <?= $f['disparador'] === 'palabras' ? 'selected' : '' ?>>Palabras clave</option>
                <option value="nuevo" <?= $f['disparador'] === 'nuevo' ? 'selected' : '' ?>>El primer mensaje del cliente</option>
            </select></label>
            <label>Palabras clave<input name="palabras" maxlength="500" value="<?= e($f['palabras']) ?>" placeholder="hola, info, precios"></label>
        </div>
        <label class="check"><input type="checkbox" name="activo" value="1" <?= $f['activo'] ? 'checked' : '' ?>> Encendido</label>
        <p class="suave pequeno">En los textos puedes usar <code>{negocio}</code> para el nombre de tu negocio.</p>
    </section>

    <?php foreach ($pasos as $i => $p): $base = 'paso[' . $i . ']'; ?>
    <fieldset class="tarjeta paso paso-<?= e($p['tipo']) ?>">
        <legend><b>Paso <?= $i + 1 ?></b></legend>
        <input type="hidden" name="<?= $base ?>[id]" value="<?= e($p['id']) ?>">
        <div class="fila-doble">
            <label>Qué hace<select name="<?= $base ?>[tipo]">
                <?php foreach (Flujos::TIPOS as $k => $n): ?><option value="<?= e($k) ?>" <?= $p['tipo'] === $k ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?>
            </select></label>
            <?php if (in_array($p['tipo'], ['mensaje', 'pregunta'], true)): ?>
            <label>Después va a<select name="<?= $base ?>[siguiente]"><?= $destinos($p['id'], (string) ($p['siguiente'] ?? '')) ?></select></label>
            <?php endif; ?>
        </div>
        <label>Mensaje<textarea name="<?= $base ?>[texto]" rows="2" maxlength="1000"><?= e($p['texto']) ?></textarea></label>
        <?php if ($p['tipo'] === 'pregunta'): ?>
        <label>Guardar la respuesta en<select name="<?= $base ?>[guardar]">
            <?php foreach (['nombre' => 'Nombre del contacto', 'correo' => 'Correo (en notas)', 'notas' => 'Notas del contacto'] as $k => $n): ?><option value="<?= $k ?>" <?= ($p['guardar'] ?? '') === $k ? 'selected' : '' ?>><?= $n ?></option><?php endforeach; ?>
            <?php foreach ($campos as $c): ?><option value="campo:<?= e($c['clave']) ?>" <?= ($p['guardar'] ?? '') === 'campo:' . $c['clave'] ? 'selected' : '' ?>>Campo: <?= e($c['nombre']) ?></option><?php endforeach; ?>
        </select></label>
        <?php endif; ?>
        <?php if ($p['tipo'] === 'botones'): $ops = $p['opciones'] ?? []; $ops = array_pad($ops, min(10, count($ops) + 2), ['titulo' => '', 'ir_a' => '']); ?>
        <p class="suave pequeno">Opciones (hasta 3 salen como botones; de 4 a 10, como lista). Máximo 24 letras cada una.</p>
        <?php foreach ($ops as $k => $o): ?>
        <div class="fila-doble opcion-flujo">
            <label>Opción <?= $k + 1 ?><input name="<?= $base ?>[op_titulo][<?= $k ?>]" maxlength="24" value="<?= e($o['titulo']) ?>"></label>
            <label>Lleva a<select name="<?= $base ?>[op_ir][<?= $k ?>]"><?= $destinos($p['id'], (string) $o['ir_a']) ?></select></label>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
        <details class="plegable-simple"><summary>Etiqueta y embudo (opcional)</summary>
            <div class="fila-doble">
                <label>Poner etiqueta<input name="<?= $base ?>[etiqueta]" maxlength="60" value="<?= e($p['etiqueta'] ?? '') ?>" placeholder="interesado"></label>
                <label>Mover en el embudo a<select name="<?= $base ?>[etapa]"><option value="">No mover</option>
                    <?php foreach (Embudo::ETAPAS as $k => $n): ?><option value="<?= e($k) ?>" <?= ($p['etapa'] ?? '') === $k ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select></label>
            </div>
        </details>
        <label class="check pequeno"><input type="checkbox" name="<?= $base ?>[borrar]" value="1"> Borrar este paso</label>
    </fieldset>
    <?php endforeach; ?>

    <div class="fila-botones">
        <button class="boton" name="accion" value="guardar">Guardar</button>
        <button class="boton boton-claro" name="accion" value="agregar_paso">+ Agregar paso</button>
    </div>
    <p class="suave pequeno">Al cambiar "Qué hace" de un paso, guarda para ver sus opciones.</p>
</form>

<section class="tarjeta">
    <h2>Probar (no manda nada)</h2>
    <form method="post" class="formulario"><?= csrf_campo() ?><input type="hidden" name="accion" value="probar">
        <label>Lo que escribiría el cliente, una línea por mensaje<textarea name="entradas" rows="3" maxlength="2000" placeholder="hola&#10;1&#10;María"><?= e($entradas) ?></textarea></label>
        <button class="boton boton-claro">Probar</button></form>
    <?php if ($prueba !== null): ?>
    <ol class="mensajes prueba-flujo">
        <?php foreach ($prueba as $x): ?>
        <li class="burbuja <?= $x['de'] === 'cliente' ? 'entra' : ($x['de'] === 'bot' ? 'sale' : 'sistema') ?>"><p><?= nl2br(e($x['texto'])) ?></p></li>
        <?php endforeach; ?>
    </ol>
    <?php endif; ?>
</section>

<form method="post" class="tarjeta" onsubmit="return confirm('¿Borrar este chatbot?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="borrar">
    <button class="enlace enlace-rojo">Borrar este chatbot</button></form>
