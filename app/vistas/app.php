<?php
/** @var string $vapid @var int $suscripciones */
?>
<div class="cabeza"><h1>App en el celular</h1></div>
<section class="tarjeta">
    <h2>1. Instálalo como app</h2>
    <p>Así se abre con un toque desde la pantalla de inicio, a pantalla completa, como una app.</p>
    <button type="button" class="boton" id="instalar-app" hidden>📲 Instalar la app</button>
    <ul class="pasos-cortos">
        <li><b>Android (Chrome):</b> toca los tres puntos ⋮ arriba a la derecha → <b>Instalar app</b> (o "Agregar a la pantalla principal").</li>
        <li><b>iPhone (Safari):</b> toca el botón Compartir ⬆️ → <b>Agregar a inicio</b>. Ábrelo desde ese ícono para recibir avisos.</li>
        <li><b>Computadora (Chrome o Edge):</b> el ícono de instalar en la barra de direcciones.</li>
    </ul>
</section>
<section class="tarjeta" id="caja-push" data-vapid="<?= e($vapid) ?>">
    <h2>2. Avisos aunque el navegador esté cerrado</h2>
    <p>Te llega un aviso al celular cada vez que te escriben (si la conversación está asignada a otra persona, le llega a ella).</p>
    <button type="button" class="boton" id="activar-push">🔔 Activar avisos en este celular</button>
    <button type="button" class="boton boton-claro" id="probar-push" hidden>Mandarme un aviso de prueba</button>
    <button type="button" class="enlace" id="quitar-push" hidden>Desactivar en este celular</button>
    <p class="suave pequeno" id="estado-push" aria-live="polite"><?= $suscripciones > 0 ? 'Tienes avisos activos en ' . $suscripciones . ($suscripciones === 1 ? ' dispositivo.' : ' dispositivos.') : '' ?></p>
</section>
