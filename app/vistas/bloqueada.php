<?php /** @var ?array $empresa */ use TuWhatsApp\Empresas; ?>
<section class="bloqueada tarjeta">
    <h1>Cuenta no disponible</h1>
    <p><?= e($empresa ? Empresas::motivoBloqueo($empresa) : 'Tu cuenta no existe.') ?></p>
    <p class="suave">Los mensajes que te escriban se siguen guardando, pero no se contesta nada solo ni se puede enviar desde aquí. Para seguir usando el programa, escribe a Tukán.</p>
    <p><a class="boton boton-grande boton-gratis" href="https://wa.me/593996408397?text=<?= rawurlencode('Hola, quiero renovar mi cuenta de WhatsApp Tukán' . ($empresa ? ' (' . $empresa['nombre'] . ')' : '') . '.') ?>" target="_blank" rel="noopener">Escribir a Tukán por WhatsApp</a></p>
    <p><a href="<?= e(url('salir')) ?>">Salir</a></p>
</section>
