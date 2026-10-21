<?php
/** @var ?array $editar @var ?array $prueba */
use TuWhatsApp\Registro;

$base = rtrim((string) (getenv('TUWA_URL') ?: ((($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? ''))), '/');
$verify = (string) getenv('META_VERIFY_TOKEN');
$sinSecreto = (string) getenv('META_APP_SECRET') === '';
$qrListo = Registro::listo();
$esQr = $editar && $editar['conectado_por'] === 'qr';
?>
<div class="cabeza"><h1><?= $editar ? 'Editar número' : 'Conectar número' ?></h1></div>

<?php if ($prueba): ?>
<div class="aviso <?= $prueba['ok'] ? 'aviso-ok' : 'aviso-error' ?>"><?= $prueba['ok'] ? 'Conexión correcta: ' . e($prueba['numero']) . ' · ' . e($prueba['nombre']) . ($prueba['calidad'] ? ' · calidad ' . e($prueba['calidad']) : '') : e($prueba['error']) ?></div>
<?php endif; ?>

<?php if ($esQr): ?>
<section class="tarjeta">
    <h2>Conectado con código QR</h2>
    <p><?= $editar['coexistencia'] ? 'Este número <b>sigue funcionando en la app WhatsApp Business del celular</b> y también aquí. Lo que mandas desde el celular aparece en el chat (gratis).' : 'Número nuevo conectado solo a este programa.' ?></p>
    <?php if ($editar['conectado_en']): ?><p class="suave pequeno">Conectado el <?= e((new DateTimeImmutable($editar['conectado_en']))->format('j/n/Y H:i')) ?></p><?php endif; ?>
    <?php if ($editar['coexistencia']): ?>
    <ul class="lista-simple">
        <li><span><b>Contactos del celular</b><br><span class="suave"><?= e(Registro::estadoSync((string) $editar['sync_contactos'])) ?></span></span>
            <?php if (!str_starts_with((string) $editar['sync_contactos'], 'ok:')): ?>
            <form method="post"><?= csrf_campo() ?><button class="boton boton-claro" name="accion" value="sync_contactos">Traer contactos</button></form>
            <?php endif; ?></li>
        <li><span><b>Historial de chats (hasta 6 meses)</b><br><span class="suave"><?= e(Registro::estadoSync((string) $editar['sync_historial'])) ?>
            <?php if ($editar['historial_estado'] === 'recibiendo'): ?> · llegando <?= (int) $editar['historial_progreso'] ?> %<?php endif; ?>
            <?php if ($editar['historial_estado'] === 'completo'): ?> · completo<?php endif; ?>
            <?php if ($editar['historial_estado'] === 'rechazado'): ?> · en el celular se eligió no compartir el historial<?php endif; ?></span></span>
            <?php if (!str_starts_with((string) $editar['sync_historial'], 'ok:')): ?>
            <form method="post"><?= csrf_campo() ?><button class="boton boton-claro" name="accion" value="sync_historial">Traer historial</button></form>
            <?php endif; ?></li>
    </ul>
    <p class="suave pequeno">Meta deja traer contactos e historial <b>una sola vez</b> y solo en las <b>24 horas</b> después de conectar. Deja abierta la app en el celular mientras llegan.</p>
    <?php endif; ?>
</section>
<?php elseif (!$editar): ?>
<section class="tarjeta qr">
    <h2>Conectar con WhatsApp Business (código QR) <span class="insignia">recomendado</span></h2>
    <p>Si tu número ya está en la app <b>WhatsApp Business</b> del celular, <b>lo sigues usando ahí</b> y además aparece aquí, con tus contactos y el historial de chats.</p>
    <ol class="pasos-cortos">
        <li>Ten a mano el celular con WhatsApp Business actualizado.</li>
        <li>Toca el botón: se abre una ventana de Meta. Entra con tu Facebook y elige tu negocio.</li>
        <li>Escribe tu número. Meta te muestra un <b>código QR</b>: escanéalo desde WhatsApp Business en el celular y acepta compartir el historial.</li>
        <li>Llega hasta <b>Finalizar</b>. Este programa guarda todo solo.</li>
    </ol>
    <?php if ($qrListo): ?>
    <label>Nombre para reconocerlo<input id="qr-nombre" maxlength="60" value="Tukán ventas"></label>
    <button type="button" class="boton boton-grande" id="btn-qr" data-app="<?= e(Registro::appId()) ?>" data-config="<?= e(Registro::configId()) ?>"
            data-version="<?= e(Registro::version()) ?>" data-csrf="<?= e(csrf()) ?>">Conectar con WhatsApp Business</button>
    <p id="qr-estado" class="suave" role="status" aria-live="polite"></p>
    <?php else: ?>
    <div class="aviso aviso-info">Falta configurar el botón en el servidor (identificador de la app y de la configuración de Meta). En el servidor pega: <code>bash /var/www/tuwhatsapp/deploy/clave_meta.sh</code></div>
    <?php endif; ?>
</section>
<?php endif; ?>

<details class="tarjeta plegable" <?= $editar && !$esQr ? 'open' : '' ?>>
    <summary><b><?= $editar ? 'Datos de conexión' : 'Conectar a mano (con token de Meta)' ?></b></summary>
    <form method="post" class="formulario">
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
</details>
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
    <p class="suave pequeno">En Meta suscríbete a los campos <b>messages</b>, <b>smb_message_echoes</b>, <b>history</b>, <b>smb_app_state_sync</b> y <b>account_update</b>.</p>
</section>
