<?php
/** @var array $linea */
use TuWhatsApp\Lineas;
use TuWhatsApp\Reglas;
$horario = Lineas::horario($linea);
$opciones = Lineas::opciones($linea);
$interruptor = function (string $campo, string $titulo, string $explica) use ($linea): string {
    return '<label class="regla"><input type="checkbox" role="switch" name="' . $campo . '" value="1" ' . ($linea[$campo] ? 'checked' : '') . '>'
         . '<span><b>' . e($titulo) . '</b><small>' . e($explica) . '</small></span></label>';
};
// Probador
$prueba = null;
if (isset($_GET['p_texto'])) {
    $tipo = in_array($_GET['p_tipo'] ?? '', ['cliente', 'personal', 'desconocido'], true) ? $_GET['p_tipo'] : 'desconocido';
    $dia = max(1, min(7, (int) ($_GET['p_dia'] ?? 1)));
    $hora = Lineas::hora($_GET['p_hora'] ?? '') ? $_GET['p_hora'] : '20:00';
    $cuando = (new DateTimeImmutable('monday this week'))->modify('+' . ($dia - 1) . ' days')->modify($hora);
    $prueba = Reglas::decidir($linea, ['tipo' => $tipo], (string) $_GET['p_texto'], $cuando, (int) ($_GET['p_ya'] ?? 0));
}
?>
<div class="cabeza"><h1>Respuestas automáticas</h1></div>
<p class="suave">Recibir mensajes es gratis. Solo pagas lo que el sistema contesta solo (unos <?= e(dinero(\TuWhatsApp\Gasto::precios(db())['servicio'], true)) ?> por respuesta). Estas reglas deciden a quién se le contesta.</p>

<form method="post" class="formulario reglas-form">
    <?= csrf_campo() ?>
    <div class="tarjeta">
        <?= $interruptor('auto_activo', 'Contestar automáticamente', 'Interruptor general. Apagado, nunca se contesta solo.') ?>
    </div>

    <h2 class="separador">Reglas (la primera que dice "no" gana)</h2>
    <div class="tarjeta">
        <label class="regla fija"><input type="checkbox" checked disabled><span><b>Contactos personales: nunca</b><small>Familia y amigos marcados como Personal jamás reciben respuesta automática. Siempre activa.</small></span></label>
        <?= $interruptor('regla_solo_clientes', 'Solo clientes registrados', 'Solo se contesta a números marcados como Cliente en Contactos. A desconocidos no.') ?>
        <?= $interruptor('regla_horario', 'Solo fuera de mi horario', 'En tu horario de atención contestas tú; fuera de horario contesta el sistema.') ?>
        <?= $interruptor('regla_palabras', 'Solo si habla de negocio', 'Se contesta únicamente si el mensaje tiene alguna de las palabras clave de abajo (o elige una opción del menú).') ?>
        <?= $interruptor('regla_una_vez', 'Una sola respuesta', 'Se manda el menú una vez; después solo se contestan las opciones que elija, y luego la conversación queda para ti.') ?>
        <div class="fila-doble">
            <label>Máximo de respuestas automáticas<input type="number" name="max_auto" min="1" max="10" value="<?= (int) $linea['max_auto'] ?>"></label>
            <label>…en cuántas horas<input type="number" name="horas_silencio" min="1" max="168" value="<?= (int) $linea['horas_silencio'] ?>"></label>
        </div>
    </div>

    <h2 class="separador">Mi horario de atención</h2>
    <div class="tarjeta horario">
        <?php foreach (Reglas::DIAS as $d => $nombre): $h = $horario[$d]; ?>
        <div class="dia">
            <label class="dia-nombre"><input type="checkbox" name="trabaja[<?= $d ?>]" value="1" <?= $h ? 'checked' : '' ?>> <?= e($nombre) ?></label>
            <input type="time" name="abre[<?= $d ?>]" value="<?= e($h[0] ?? '08:00') ?>" aria-label="<?= e($nombre) ?> abre">
            <span>a</span>
            <input type="time" name="cierra[<?= $d ?>]" value="<?= e($h[1] ?? '18:00') ?>" aria-label="<?= e($nombre) ?> cierra">
        </div>
        <?php endforeach; ?>
        <p class="suave pequeno">Días sin marcar = no atiendes (el sistema puede contestar todo el día).</p>
    </div>

    <h2 class="separador">Palabras de negocio</h2>
    <div class="tarjeta">
        <label>Separadas por comas (no importan mayúsculas ni tildes)
            <textarea name="palabras" rows="4"><?= e($linea['palabras']) ?></textarea>
        </label>
    </div>

    <h2 class="separador">Menú que se envía</h2>
    <div class="tarjeta">
        <label>Saludo<textarea name="mensaje_menu" rows="3" maxlength="1000" required><?= e($linea['mensaje_menu']) ?></textarea></label>
        <label class="check"><input type="checkbox" name="menu_botones" value="1" <?= ($linea['menu_botones'] ?? true) ? 'checked' : '' ?>>
            Mostrar las opciones como <b>botones de WhatsApp</b> (el cliente toca en vez de escribir el número; con más de 3 opciones sale una lista)</label>
        <?php for ($i = 0; $i < Lineas::MAX_OPCIONES; $i++): $o = $opciones[$i] ?? ['titulo' => '', 'respuesta' => '', 'humano' => false]; ?>
        <fieldset class="opcion-menu">
            <legend>Opción <?= $i + 1 ?></legend>
            <label>Título <small class="suave">(en botón se ven 20 letras)</small><input name="op_titulo[<?= $i ?>]" maxlength="60" value="<?= e($o['titulo']) ?>" placeholder="<?= $i < 3 ? '' : '(vacío = no se usa)' ?>"></label>
            <label>Respuesta<textarea name="op_respuesta[<?= $i ?>]" rows="2" maxlength="1000"><?= e($o['respuesta']) ?></textarea></label>
            <label class="check"><input type="checkbox" name="op_humano[<?= $i ?>]" value="1" <?= $o['humano'] ? 'checked' : '' ?>> Después de esta respuesta, pasa a una persona (deja de contestar solo)</label>
        </fieldset>
        <?php endfor; ?>
        <p class="suave pequeno">Así se ve el menú:</p>
        <pre class="vista-previa"><?= e(Reglas::textoMenu($linea)) ?></pre>
    </div>

    <button class="boton boton-grande boton-fijo">Guardar reglas</button>
</form>

<p class="suave"><a href="<?= e(url('respuestas')) ?>">Respuestas rápidas del equipo</a> (textos para pegar en el chat).</p>

<h2 class="separador" id="probar">Probar las reglas</h2>
<form method="get" action="/#probar" class="tarjeta formulario">
    <input type="hidden" name="r" value="reglas">
    <label>Si me escribe<input name="p_texto" value="<?= e($_GET['p_texto'] ?? 'Hola, ¿cuál es el precio?') ?>"></label>
    <div class="fila-doble">
        <label>Que es<select name="p_tipo"><?php foreach (['cliente' => 'Cliente', 'desconocido' => 'Desconocido', 'personal' => 'Personal'] as $t => $n): ?><option value="<?= $t ?>" <?= ($_GET['p_tipo'] ?? 'cliente') === $t ? 'selected' : '' ?>><?= $n ?></option><?php endforeach; ?></select></label>
        <label>Ya le contestó solo<select name="p_ya"><?php for ($i = 0; $i <= 3; $i++): ?><option value="<?= $i ?>" <?= (int) ($_GET['p_ya'] ?? 0) === $i ? 'selected' : '' ?>><?= $i ?> veces</option><?php endfor; ?></select></label>
    </div>
    <div class="fila-doble">
        <label>Día<select name="p_dia"><?php foreach (Reglas::DIAS as $d => $n): ?><option value="<?= $d ?>" <?= (int) ($_GET['p_dia'] ?? 1) === $d ? 'selected' : '' ?>><?= $n ?></option><?php endforeach; ?></select></label>
        <label>Hora<input type="time" name="p_hora" value="<?= e($_GET['p_hora'] ?? '20:00') ?>"></label>
    </div>
    <button class="boton boton-claro">Probar (no envía nada)</button>
    <?php if ($prueba): ?>
    <div class="resultado-prueba <?= $prueba['responder'] ? 'si' : 'no' ?>" role="status">
        <b><?= $prueba['responder'] ? 'Sí se contesta solo' : 'No se contesta' ?></b> — <?= e($prueba['motivo']) ?>
        <?php if ($prueba['texto']): ?><pre><?= u($prueba['texto']) ?></pre><?php endif; ?>
    </div>
    <?php endif; ?>
</form>
