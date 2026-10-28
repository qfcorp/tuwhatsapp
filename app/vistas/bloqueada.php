<?php /** @var ?array $empresa */ use TuWhatsApp\Empresas; ?>
<section class="bloqueada tarjeta">
    <h1>Cuenta no disponible</h1>
    <p><?= e($empresa ? Empresas::motivoBloqueo($empresa) : 'Tu cuenta no existe.') ?></p>
    <?php if (en_app_android()): /* app de Google Play: sin enlaces para renovar o pagar afuera */ ?>
    <p class="suave">Los mensajes que te escriban se siguen guardando, pero no se contesta nada solo ni se puede enviar desde aquí. El administrador de tu negocio puede revisarlo con Tukán Software.</p>
    <?php else: ?>
    <p class="suave">Los mensajes que te escriban se siguen guardando, pero no se contesta nada solo ni se puede enviar desde aquí. Para seguir usando el programa, escribe a Tukán.</p>
    <p><a class="boton boton-grande boton-gratis" href="https://wa.me/593996408397?text=<?= rawurlencode('Hola, quiero renovar mi cuenta de WhatsApp Tukán' . ($empresa ? ' (' . $empresa['nombre'] . ')' : '') . '.') ?>" target="_blank" rel="noopener">Escribir a Tukán por WhatsApp</a></p>
    <?php endif; ?>
    <p><a href="<?= e(url('salir')) ?>">Salir</a></p>
</section>
