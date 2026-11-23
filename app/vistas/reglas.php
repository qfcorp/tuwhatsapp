<?php
/** @var array $linea */
use TuWhatsApp\Lineas;
use TuWhatsApp\Reglas;
use TuWhatsApp\Traducir;
use TuWhatsApp\IdiomaCliente;
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
    $pIdioma = IdiomaCliente::valido((string) ($_GET['p_idioma'] ?? 'es'));
    $prueba = Reglas::decidir($linea, ['tipo' => $tipo, 'idioma' => $pIdioma], (string) $_GET['p_texto'], $cuando, (int) ($_GET['p_ya'] ?? 0));
}
$trLinea = Traducir::datos($linea);
$idiomasLinea = Traducir::idiomasLinea($linea);
$conIa = modulo('ia');
?>
<div class="cabeza tk-titulo-pagina"><div class="titulo"><h1>Respuestas automáticas</h1><?= ayuda_inicio() ?>Recibir mensajes es gratis. Solo pagas lo que el sistema contesta solo (unos <?= e(dinero(\TuWhatsApp\Gasto::precios(db())['servicio'], true)) ?> por respuesta). Estas reglas deciden a quién se le contesta.<?= ayuda_fin() ?></div></div>

<section class="tarjeta tk-tarjeta orden-auto">
    <h2>Qué contesta primero</h2>
    <ol class="pasos-cortos">
        <li><b>Catálogo</b>: si el cliente escribe <i>catálogo</i> le llega la lista de productos; con <i>comprar</i> confirma su carrito. <a href="<?= e(url('catalogo')) ?>">Catálogo</a></li>
        <li><b>Chatbots</b> por pasos (si alguno se activa con lo que escribió). <a href="<?= e(url('flujos')) ?>">Chatbots</a></li>
        <li><b>Agente con IA</b> (extra), si está encendido. <a href="<?= e(url('ia')) ?>">Agente con IA</a></li>
        <li><b>El menú de abajo</b>, con estas reglas.</li>
    </ol>
    <p class="suave pequeno">Ninguno contesta a contactos personales, ni mientras alguien del equipo atiende la conversación.</p>
</section>

<form method="post" class="formulario reglas-form">
    <?= csrf_campo() ?>
    <div class="tarjeta tk-tarjeta">
        <?= $interruptor('auto_activo', 'Contestar automáticamente', 'Interruptor general del menú. Apagado, el menú nunca se manda solo.') ?>
        <?= $interruptor('callar_al_atender', 'Callarse cuando contesta alguien del equipo', 'Si alguien del equipo escribe (desde aquí o desde el celular), lo automático no interrumpe durante las horas de abajo.') ?>
    </div>

    <h2 class="separador">Reglas (la primera que dice "no" gana)</h2>
    <div class="tarjeta tk-tarjeta">
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
    <div class="tarjeta tk-tarjeta horario">
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
    <div class="tarjeta tk-tarjeta">
        <label>Separadas por comas (no importan mayúsculas ni tildes)
            <textarea name="palabras" rows="4"><?= e($linea['palabras']) ?></textarea>
        </label>
    </div>

    <h2 class="separador">Menú que se envía</h2>
    <div class="tarjeta tk-tarjeta">
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

    <h2 class="separador" id="idiomas">🌐 Contestar en el idioma del cliente</h2>
    <div class="tarjeta tk-tarjeta">
        <input type="hidden" name="idiomas_form" value="1">
        <label class="regla"><input type="checkbox" role="switch" name="idioma_auto" value="1" <?= Traducir::idiomaAuto($linea) ? 'checked' : '' ?>>
            <span><b>Contestar en el idioma del cliente</b><small>Si el cliente escribe en inglés, portugués, francés o italiano, el menú, los chatbots, el agente con IA y los mensajes de pedidos y pagos le llegan en su idioma. El idioma se detecta solo por lo que escribe (un «ok» o un emoji no lo cambian) y lo ves en su ficha.</small></span></label>
        <p><b>Idiomas que contestas</b> <small class="suave">(a un cliente de un idioma sin marcar se le contesta en español)</small></p>
        <div class="fila-checks">
            <label class="check"><input type="checkbox" checked disabled> <?= IdiomaCliente::BANDERAS['es'] ?> Español</label>
            <?php foreach (Traducir::OTROS as $l): ?>
            <label class="check"><input type="checkbox" name="idiomas[]" value="<?= $l ?>" <?= in_array($l, $idiomasLinea, true) ? 'checked' : '' ?>> <?= IdiomaCliente::BANDERAS[$l] ?> <?= e(IdiomaCliente::IDIOMAS[$l]) ?></label>
            <?php endforeach; ?>
        </div>
        <?php if ($conIa): ?>
        <p><button class="boton boton-claro tk-boton secundario" name="traducir" value="1">🌐 Traducir automáticamente a inglés, portugués, francés e italiano</button></p>
        <p class="suave pequeno">Guarda lo que escribiste y traduce el saludo, las opciones y las palabras de negocio con la IA (una sola vez). Después puedes corregir cada traducción abajo. Al contestar no se gasta IA.</p>
        <?php else: ?>
        <p class="suave pequeno">Escribe abajo cada traducción a mano (lo que dejes vacío sale en español). <b>Con el extra de IA se traducen solos</b> con un botón.</p>
        <?php endif; ?>
        <?php foreach (Traducir::OTROS as $l): $x = (array) ($trLinea[$l] ?? []); ?>
        <details class="plegable idioma-tr">
            <summary><?= IdiomaCliente::BANDERAS[$l] ?> <?= e(IdiomaCliente::IDIOMAS[$l]) ?> <?= trim((string) ($x['saludo'] ?? '')) !== '' ? '<small class="suave">(traducido)</small>' : '<small class="suave">(sale en español)</small>' ?></summary>
            <label>Saludo<textarea name="tr[<?= $l ?>][saludo]" rows="2" maxlength="1000"><?= e((string) ($x['saludo'] ?? '')) ?></textarea></label>
            <?php foreach ($opciones as $i => $o): $xo = (array) ($x['opciones'][$i] ?? []); ?>
            <fieldset class="opcion-menu">
                <legend>Opción <?= $i + 1 ?> · <!--notr--><?= e($o['titulo']) ?><!--/notr--></legend>
                <label>Título<input name="tr[<?= $l ?>][op_titulo][<?= $i ?>]" maxlength="60" value="<?= e((string) ($xo['titulo'] ?? '')) ?>"></label>
                <label>Respuesta<textarea name="tr[<?= $l ?>][op_respuesta][<?= $i ?>]" rows="2" maxlength="1000"><?= e((string) ($xo['respuesta'] ?? '')) ?></textarea></label>
            </fieldset>
            <?php endforeach; ?>
            <label>Palabras de negocio en este idioma <small class="suave">(además, se entienden palabras comunes como precio, pedido o catálogo en cada idioma)</small><textarea name="tr[<?= $l ?>][palabras]" rows="2"><?= e((string) ($x['palabras'] ?? '')) ?></textarea></label>
            <?php if (trim((string) ($x['saludo'] ?? '')) !== ''): ?>
            <p class="suave pequeno">Así le llega el menú:</p>
            <pre class="vista-previa"><!--notr--><?= e(Reglas::textoMenu($linea, $l)) ?><!--/notr--></pre>
            <?php endif; ?>
        </details>
        <?php endforeach; ?>
    </div>

    <button class="boton boton-grande boton-fijo">Guardar reglas</button>
</form>

<p class="suave"><a href="<?= e(url('respuestas')) ?>">Respuestas rápidas del equipo</a> (textos para pegar en el chat).</p>

<h2 class="separador" id="probar">Probar las reglas</h2>
<form method="get" action="/#probar" class="tarjeta tk-tarjeta formulario">
    <input type="hidden" name="r" value="reglas">
    <label>Si me escribe<input name="p_texto" value="<?= e($_GET['p_texto'] ?? 'Hola, ¿cuál es el precio?') ?>"></label>
    <div class="fila-doble">
        <label>Que es<select name="p_tipo"><?php foreach (['cliente' => 'Cliente', 'desconocido' => 'Desconocido', 'personal' => 'Personal'] as $t => $n): ?><option value="<?= $t ?>" <?= ($_GET['p_tipo'] ?? 'cliente') === $t ? 'selected' : '' ?>><?= $n ?></option><?php endforeach; ?></select></label>
        <label>Idioma del cliente<select name="p_idioma"><?php foreach (IdiomaCliente::IDIOMAS as $l => $n): ?><option value="<?= $l ?>" <?= ($_GET['p_idioma'] ?? 'es') === $l ? 'selected' : '' ?>><?= IdiomaCliente::BANDERAS[$l] ?> <?= e($n) ?></option><?php endforeach; ?></select></label>
    </div>
    <div class="fila-doble">
        <label>Ya le contestó solo<select name="p_ya"><?php for ($i = 0; $i <= 3; $i++): ?><option value="<?= $i ?>" <?= (int) ($_GET['p_ya'] ?? 0) === $i ? 'selected' : '' ?>><?= $i ?> veces</option><?php endfor; ?></select></label>
    </div>
    <div class="fila-doble">
        <label>Día<select name="p_dia"><?php foreach (Reglas::DIAS as $d => $n): ?><option value="<?= $d ?>" <?= (int) ($_GET['p_dia'] ?? 1) === $d ? 'selected' : '' ?>><?= $n ?></option><?php endforeach; ?></select></label>
        <label>Hora<input type="time" name="p_hora" value="<?= e($_GET['p_hora'] ?? '20:00') ?>"></label>
    </div>
    <button class="boton boton-claro tk-boton secundario">Probar (no envía nada)</button>
    <?php if ($prueba): ?>
    <div class="resultado-prueba <?= $prueba['responder'] ? 'si' : 'no' ?>" role="status">
        <b><?= $prueba['responder'] ? 'Sí se contesta solo' : 'No se contesta' ?></b> — <?= e($prueba['motivo']) ?>
        <?php if ($prueba['texto']): ?><pre><?= u($prueba['texto']) ?></pre><?php endif; ?>
    </div>
    <?php endif; ?>
</form>
