<?php /** @var ?string $error @var bool $hayUsuarios */ ?>
<section class="entrar">
    <div class="entrar-marca">
        <svg viewBox="0 0 32 32" width="56" height="56" aria-hidden="true"><rect width="32" height="32" rx="8" fill="#F28C28"/><path d="M8 24.5l1.7-4.6a8.6 8.6 0 1 1 3.3 3.2z" fill="#14211F"/></svg>
        <h1><?= en_app_android() ? 'Tukán <b>Mensajes</b>' : 'WhatsApp <b>Tukán</b>' ?></h1>
        <p class="suave"><?= en_app_android() ? 'Tu bandeja de chats de WhatsApp Business con respuestas automáticas y tope de gasto. Entra con la cuenta que te dio Tukán Software.' : 'Tu bandeja de WhatsApp con respuestas automáticas y tope de gasto.' ?></p>
    </div>
    <?php if (!$hayUsuarios): ?>
    <div class="aviso aviso-info">Todavía no hay usuarios. En el servidor pega: <code>php /var/www/tuwhatsapp/bin/crear_usuario.php</code></div>
    <?php endif; ?>
    <?php if ($error): ?><div class="aviso aviso-error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="post" class="tarjeta">
        <?= csrf_campo() ?>
        <label>Correo<input type="email" name="correo" required autocomplete="username" value="<?= e($_POST['correo'] ?? '') ?>"></label>
        <label>Contraseña<input type="password" name="clave" required autocomplete="current-password"></label>
        <button class="boton boton-grande">Entrar</button>
    </form>
</section>
<p class="suave pequeno" style="text-align:center"><a href="/terminos.php">Términos</a> · <a href="/privacidad.php">Privacidad</a> · <a href="/tratamiento-datos.php">Tratamiento de datos</a></p>
