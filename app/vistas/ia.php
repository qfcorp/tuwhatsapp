<?php
/** @var array $linea @var bool $disponible @var float $gastado @var float $tope */
$pct = $tope > 0 ? min(100, (int) round($gastado * 100 / $tope)) : 100;
?>
<div class="cabeza"><h1>Asistente con IA</h1><span class="suave"><?= e(dinero($gastado, true)) ?> de <?= e(dinero($tope)) ?> este mes</span></div>
<div class="tope-barra grande"><span style="width:<?= $pct ?>%"></span></div>
<?php if (!$disponible): ?>
<p class="aviso aviso-info">La IA todavía no está encendida en el servidor. Tukán la activa poniendo la clave de la IA en la configuración.</p>
<?php endif; ?>
<p>En cada chat aparecen dos botones:</p>
<ul>
    <li><b>✨ Sugerir respuesta</b>: la IA lee la conversación y lo que escribas abajo, y deja un borrador en la caja de texto. <b>Tú lo revisas y lo mandas</b>: la IA nunca contesta sola.</li>
    <li><b>Resumir</b>: en 4 líneas, qué quiere el cliente y cuál es el siguiente paso. Útil cuando otra persona del equipo toma la conversación.</li>
</ul>
<p class="suave pequeno">Cada uso cuesta centavos (aprox. $0,001 a $0,005). Al llegar al tope del mes se detiene sola y se renueva el día 1.</p>

<form method="post" class="tarjeta formulario">
    <?= csrf_campo() ?>
    <h2>Lo que sabe la IA de tu negocio</h2>
    <p class="suave pequeno">Escribe como si le explicaras a un empleado nuevo: qué vendes, precios, horarios, dirección, formas de pago, envíos y las preguntas que más te hacen.
    La IA usa <b>solo</b> esto; si le preguntan algo que no está aquí, dirá que lo confirmas.</p>
    <label class="oculto" for="conocimiento">Información del negocio</label>
    <textarea id="conocimiento" name="conocimiento" rows="14" maxlength="8000" placeholder="Somos Tukán Software, en Quito.
Planes: Básico $25 al mes (1 número, 3 usuarios), Pro $45 al mes...
Horario: lunes a viernes de 8:00 a 18:00.
Pagos: transferencia Banco Pichincha o tarjeta con Payphone.
Preguntas frecuentes:
- ¿Tiene contrato? No, se paga mes a mes."><?= e((string) $linea['ia_conocimiento']) ?></textarea>
    <button class="boton">Guardar</button>
</form>
