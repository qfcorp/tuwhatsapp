<?php
/** @var ?array $editar @var ?array $prueba */
$base = rtrim((string) (getenv('TUWA_URL') ?: ((($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? ''))), '/');
$verify = (string) getenv('META_VERIFY_TOKEN');
$sinSecreto = (string) getenv('META_APP_SECRET') === '';
?>
<div class="cabeza"><h1><?= $editar ? 'Editar número' : 'Conectar número' ?></h1></div>

<?php if ($prueba): ?>
<div class="aviso <?= $prueba['ok'] ? 'aviso-ok' : 'aviso-error' ?>"><?= $prueba['ok'] ? 'Conexión correcta: ' . e($prueba['numero']) . ' · ' . e($prueba['nombre']) . ($prueba['calidad'] ? ' · calidad ' . e($prueba['calidad']) : '') : e($prueba['error']) ?></div>
<?php endif; ?>

<form method="post" class="tarjeta formulario">
    <?= csrf_campo() ?>
    <label>Nombre para reconocerlo<input name="nombre" required maxlength="60" value="<?= e($editar['nombre'] ?? 'Tukán ventas') ?>"></label>
    <label>Número de WhatsApp<input name="telefono" inputmode="tel" value="<?= e($editar['telefono'] ?? '') ?>" placeholder="0996408397"></label>
    <label>Identificador del número de teléfono (Phone number ID)<input name="phone_number_id" inputmode="numeric" required value="<?= e($editar['phone_number_id'] ?? '') ?>"></label>
    <label>Identificador de la cuenta de WhatsApp Business (WABA ID)<input name="waba_id" inputmode="numeric" value="<?= e($editar['waba_id'] ?? '') ?>"></label>
    <label>Token de acceso permanente<?= $editar ? ' (déjalo vacío para no cambiarlo)' : '' ?>
        <input name="token" autocomplete="off" spellcheck="false" <?= $editar ? '' : 'required' ?> placeholder="<?= $editar && $editar['token_cifrado'] !== '' ? 'guardado ●●●●●●' : 'EAAG…' ?>">
    </label>
    <button class="boton boton-grande">Guardar y probar</button>
</form>
<?php if ($editar): ?>
<form method="post"><?= csrf_campo() ?><button class="boton boton-claro" name="accion" value="probar">Probar conexión otra vez</button></form>
<?php endif; ?>

<section class="tarjeta">
    <h2>Datos para pegar en Meta (Webhook)</h2>
    <?php if ($sinSecreto): ?><div class="aviso aviso-error">Falta META_APP_SECRET en el servidor: sin eso no se aceptan los mensajes que llegan. Revisa la guía de instalación.</div><?php endif; ?>
    <label>URL de devolución de llamada (Callback URL)
        <span class="copiar"><input readonly value="<?= e($base . '/webhook.php') ?>"><button type="button" class="boton boton-claro btn-copiar">Copiar</button></span>
    </label>
    <label>Token de verificación (Verify token)
        <span class="copiar"><input readonly value="<?= e($verify) ?>"><button type="button" class="boton boton-claro btn-copiar">Copiar</button></span>
    </label>
    <p class="suave pequeno">En Meta suscríbete a los campos <b>messages</b> y <b>smb_message_echoes</b> (este último solo si usas el mismo número en la app del celular).</p>
</section>
