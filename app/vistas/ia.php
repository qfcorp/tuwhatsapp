<?php
/** @var array $linea @var bool $disponible @var float $gastado @var float $tope @var list<array> $prueba */
?>
<div class="cabeza tk-titulo-pagina"><h1>Agente con IA</h1></div>
<?php if (es_super()): ?>
<p class="aviso aviso-info pequeno">Solo lo ve Tukán: esta cuenta lleva <?= e(dinero($gastado, true)) ?> de IA este mes (tope <?= e(dinero($tope)) ?>).
<?= $disponible ? 'Usa la IA compartida de Tukán.' : '<b>La IA compartida de Tukán no está encendida en el servidor</b> (cd /var/www/tukan-central && bash deploy/configurar_ia.sh --usar-clave-tuhospedaje).' ?></p>
<?php endif; ?>
<form method="post" class="tarjeta tk-tarjeta formulario">
    <?= csrf_campo() ?><input type="hidden" name="accion" value="bot">
    <h2>Agente que contesta solo</h2>
    <p class="suave pequeno">Contesta a tus clientes las 24 horas con lo que escribas abajo y con tu <a href="<?= e(url('catalogo')) ?>">catálogo</a> (precios y stock).
    Puede armar el carrito y, cuando el cliente confirma, mandarle el resumen con cómo pagar. Si le piden hablar con alguien, el cliente está molesto
    o le preguntan algo que no sabe, pasa la conversación a una persona del equipo. Cuando alguien del equipo contesta, el agente se calla.</p>
    <label class="check"><input type="checkbox" name="bot_activo" value="1" <?= $linea['bot_activo'] ? 'checked' : '' ?>> <b>Encendido</b></label>
    <label>Contesta<select name="bot_cuando">
        <option value="siempre" <?= $linea['bot_cuando'] === 'siempre' ? 'selected' : '' ?>>Siempre (las 24 horas)</option>
        <option value="fuera_horario" <?= $linea['bot_cuando'] === 'fuera_horario' ? 'selected' : '' ?>>Solo fuera de mi horario de atención</option>
    </select></label>
    <div class="fila-doble">
        <label>Cómo se presenta (opcional)<input name="bot_nombre" maxlength="60" value="<?= e($linea['bot_nombre']) ?>" placeholder="Sofía"></label>
        <label>Máximo de respuestas al día por persona<input name="bot_max_dia" type="number" min="1" max="100" value="<?= (int) $linea['bot_max_dia'] ?>"></label>
    </div>
    <label class="check"><input type="checkbox" name="bot_vende" value="1" <?= $linea['bot_vende'] ? 'checked' : '' ?>> Puede armar el carrito con el catálogo</label>
    <button class="boton tk-boton">Guardar</button>
    <p class="suave pequeno">El horario se cambia en <a href="<?= e(url('reglas')) ?>">Respuestas automáticas</a>. Si el agente no puede contestar, siguen las respuestas automáticas de siempre.</p>
</form>

<section class="tarjeta tk-tarjeta prueba-ia" id="probar">
    <div class="pp-cabeza">
        <h2>💬 Pruébalo aquí</h2>
        <?php if ($prueba): ?>
        <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="probar_borrar"><button class="enlace pequeno">Empezar de nuevo</button></form>
        <?php endif; ?>
    </div>
    <p class="suave pequeno">Escribe como si fueras un cliente y mira qué contestaría el agente con lo que sabe ahora. <b>No se manda nada por WhatsApp</b> ni se tocan carritos ni pedidos.
    Si no te gusta una respuesta, mejora "Lo que sabe la IA de tu negocio" (abajo) y vuelve a probar.</p>
    <div class="prueba-chat" aria-live="polite">
        <?php if (!$prueba): ?>
        <p class="suave pequeno prueba-vacia">Ejemplos: "¿Cuánto cuesta…?", "¿Hacen envíos?", "Quiero 2", "Quiero hablar con alguien".</p>
        <?php endif; ?>
        <?php foreach ($prueba as $m): ?>
        <?php if ($m['de'] === 'cliente'): ?>
        <div class="burbuja entra"><?= nl2br(e($m['texto'])) ?></div>
        <?php else: ?>
        <div class="burbuja sale origen-bot"><?= nl2br(e($m['texto'])) ?>
            <?php if (!empty($m['agregar'])): ?><div class="prueba-extra">🛒 Pondría en el carrito: <?= e(implode(', ', $m['agregar'])) ?></div><?php endif; ?>
            <?php if (!empty($m['confirmar'])): ?><div class="prueba-extra">🧾 Aquí mandaría el resumen del pedido con cómo pagar</div><?php endif; ?>
            <?php if (!empty($m['persona'])): ?><div class="prueba-extra">🙋 Pasaría el chat a una persona del equipo<?= !empty($m['nota']) ? '. Nota para el equipo: <i>' . e($m['nota']) . '</i>' : '' ?></div><?php endif; ?>
        </div>
        <?php endif; ?>
        <?php endforeach; ?>
    </div>
    <form method="post" class="prueba-escribir">
        <?= csrf_campo() ?><input type="hidden" name="accion" value="probar">
        <label class="oculto" for="texto-prueba">Mensaje del cliente</label>
        <input id="texto-prueba" name="texto" maxlength="1000" required placeholder="Escribe como un cliente…" autocomplete="off" <?= $prueba ? 'autofocus' : '' ?>>
        <button class="boton tk-boton" <?= $disponible ? '' : 'disabled' ?>>Enviar</button>
    </form>
    <?php if (!$disponible): ?><p class="suave pequeno">La IA no está disponible en este momento.</p><?php endif; ?>
</section>

<section class="tarjeta tk-tarjeta">
<h2>✨ Pregúntale a la IA</h2>
<p class="pequeno">Pregunta en palabras sobre tu negocio: "¿quiénes están listos para comprar?", "arma una campaña para los que no escriben hace un mes".
Te deja la lista de clientes, la etiqueta y la campaña listas, y cada mañana un resumen al celular. <a href="<?= e(url('asistente')) ?>">Abrir</a></p>
</section>

<section class="tarjeta tk-tarjeta">
<h2>En cada chat</h2>
<ul>
    <li><b>✨ Sugerir respuesta</b>: la IA lee la conversación y deja un borrador en la caja de texto. Tú lo revisas y lo mandas.</li>
    <li><b>Resumir</b>: en 4 líneas, qué quiere el cliente y cuál es el siguiente paso. Útil cuando otra persona del equipo toma la conversación.</li>
</ul>
</section>

<form method="post" class="tarjeta tk-tarjeta formulario">
    <?= csrf_campo() ?><input type="hidden" name="accion" value="conocimiento">
    <h2>Lo que sabe la IA de tu negocio</h2>
    <p class="suave pequeno">Escribe como si le explicaras a un empleado nuevo: qué vendes, precios, horarios, dirección, formas de pago, envíos y las preguntas que más te hacen.
    La IA usa <b>solo</b> esto; si le preguntan algo que no está aquí, dirá que lo confirmas.</p>
    <label class="oculto" for="conocimiento">Información del negocio</label>
    <textarea id="conocimiento" name="conocimiento" rows="14" maxlength="8000" placeholder="Somos Tukán Software, en Quito.
Vendemos: cortes, tintes y tratamientos (los precios están en el catálogo).
Horario: lunes a viernes de 8:00 a 18:00.
Pagos: transferencia o tarjeta (Plux).
Preguntas frecuentes:
- ¿Tiene contrato? No, se paga mes a mes."><?= e((string) $linea['ia_conocimiento']) ?></textarea>
    <label for="ia_tono">Tono de tu marca (opcional)</label>
    <input id="ia_tono" name="ia_tono" maxlength="300" value="<?= e((string) $linea['ia_tono']) ?>" placeholder="Cercano y alegre, de tú, con pocos emojis. Nunca prometemos descuentos.">
    <p class="suave pequeno">La IA escribe siempre con este tono: al contestar, al sugerir respuestas y al armar campañas. Contesta en el idioma del cliente.</p>
    <button class="boton tk-boton">Guardar</button>
</form>
