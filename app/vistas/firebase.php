<?php
/** @var array{configurado:bool, project_id:string, client_email:string, celulares:int} $estado @var list<array> $misCelulares */
?>
<div class="cabeza tk-titulo-pagina"><h1>Avisos de la app (Firebase)</h1><span class="suave">solo el personal de Tukán</span></div>
<?php if (!empty($_SESSION['desde_central'])): ?><p><a href="<?= e(\TuWhatsApp\TukanCentral::urlCentral()) ?>">← Panel Tukán</a></p><?php endif; ?>

<section class="tarjeta tk-tarjeta">
    <h2>Clave de Firebase del servidor</h2>
    <p>Con esta clave el servidor manda los avisos <b>nativos</b> a la app <b>Tukán Mensajes</b> de Android (llegan aunque la app esté cerrada,
    como en WhatsApp). Es la misma para todos los clientes. Se guarda <b>cifrada</b> y nunca se muestra.</p>
    <?php if ($estado['configurado']): ?>
    <p><b>Guardada ✅</b></p>
    <ul class="pasos-cortos">
        <li>Proyecto: <b><?= e($estado['project_id']) ?></b></li>
        <li>Cuenta de servicio: <span class="suave"><?= e($estado['client_email']) ?></span></li>
        <li>Celulares con la app que reciben avisos: <b><?= (int) $estado['celulares'] ?></b></li>
    </ul>
    <form method="post" class="en-linea"><?= csrf_campo() ?><input type="hidden" name="accion" value="prueba">
        <button class="boton tk-boton">Mandarme un aviso de prueba a mis celulares con la app</button></form>
    <form method="post" class="en-linea" onsubmit="return confirm('¿Quitar la clave? La app deja de recibir avisos nativos.');"><?= csrf_campo() ?><input type="hidden" name="accion" value="quitar">
        <button class="enlace">Quitar clave</button></form>
    <?php if (!$misCelulares): ?>
    <p class="suave pequeno">Para probar: abre la app Tukán Mensajes en tu celular con este mismo usuario y toca Permitir cuando pregunte por las notificaciones.</p>
    <?php endif; ?>
    <?php else: ?>
    <p class="tk-aviso aviso aviso-info">Todavía no hay clave: la app no recibe avisos nativos (sí llegan el correo de respaldo y Telegram).</p>
    <?php endif; ?>
</section>

<section class="tarjeta tk-tarjeta">
    <h2><?= $estado['configurado'] ? 'Cambiar la clave' : 'Subir la clave' ?></h2>
    <ol class="pasos-cortos">
        <li>En <b>console.firebase.google.com</b> abre el proyecto de Tukán Mensajes → ⚙️ <b>Configuración del proyecto</b> → <b>Cuentas de servicio</b>.</li>
        <li>Toca <b>Generar nueva clave privada</b> → <b>Generar clave</b>. Se baja un archivo <b>.json</b> a tu computadora (carpeta Descargas).</li>
        <li>Aquí abajo toca <b>Elegir archivo</b>, elige ese archivo y toca <b>Guardar clave</b>.</li>
        <li>Después de guardarla, <b>borra el archivo</b> de Descargas: ya quedó guardada en el servidor.</li>
    </ol>
    <form method="post" enctype="multipart/form-data" class="formulario"><?= csrf_campo() ?><input type="hidden" name="accion" value="subir">
        <label>Archivo .json de la cuenta de servicio<input type="file" name="clave" accept=".json,application/json" required></label>
        <button class="boton tk-boton">Guardar clave</button>
    </form>
</section>
