<?php
/** @var array $linea @var ?string $secreto */
$base = rtrim((string) (getenv('TUWA_URL') ?: ''), '/');
?>
<div class="cabeza"><h1>Integraciones</h1></div>

<section class="tarjeta">
    <h2>Descargar a Excel</h2>
    <p class="suave pequeno">Archivo que abre Excel o Google Sheets, con tildes y separado por punto y coma.</p>
    <div class="fila-botones">
        <a class="boton boton-claro" href="<?= e(url('integraciones', ['bajar' => 'contactos'])) ?>">Contactos</a>
        <a class="boton boton-claro" href="<?= e(url('integraciones', ['bajar' => 'mensajes', 'd' => 30])) ?>">Mensajes (30 días)</a>
        <a class="boton boton-claro" href="<?= e(url('integraciones', ['bajar' => 'mensajes', 'd' => 365])) ?>">Mensajes (1 año)</a>
    </div>
</section>

<form method="post" class="tarjeta formulario">
    <?= csrf_campo() ?>
    <h2>Avisar a otro programa cuando llega un mensaje</h2>
    <p class="suave pequeno">Para conectar con Make, Zapier, n8n, Google Sheets o tu propio sistema. Cada mensaje que llega se envía a esta dirección (JSON por POST),
    firmado con la cabecera <code>X-Tukan-Firma: sha256=…</code> (HMAC con tu clave secreta).</p>
    <label>Dirección (https://…) · vacío = apagado<input name="url" type="url" maxlength="500" value="<?= e((string) $linea['aviso_url']) ?>" placeholder="https://hook.make.com/abc123"></label>
    <?php if ($secreto !== null && $linea['aviso_url'] !== ''): ?>
    <p class="aviso aviso-info">Clave secreta (cópiala ahora, no se vuelve a mostrar): <code><?= e($secreto) ?></code></p>
    <?php endif; ?>
    <?php if ($linea['aviso_error'] !== ''): ?><p class="aviso aviso-error">Último problema al avisar: <?= e($linea['aviso_error']) ?></p><?php endif; ?>
    <div class="fila-botones">
        <button class="boton" name="accion" value="guardar">Guardar</button>
        <?php if ($linea['aviso_url'] !== ''): ?>
        <button class="boton boton-claro" name="accion" value="probar">Mandar aviso de prueba</button>
        <button class="boton boton-claro" name="accion" value="secreto">Clave secreta nueva</button>
        <?php endif; ?>
    </div>
</form>

<section class="tarjeta">
    <h2>API (para que otro programa mande mensajes)</h2>
    <p class="suave pequeno">Crea tu token en <a href="<?= e(url('cuenta')) ?>">Mi cuenta</a>. Ejemplo para dejar un mensaje en Pendientes:</p>
    <pre class="codigo">curl -H "Authorization: Bearer TU_TOKEN" -d '{"telefono":"0991234567","texto":"Hola"}' <?= e($base) ?>/api.php?accion=pendiente</pre>
</section>
