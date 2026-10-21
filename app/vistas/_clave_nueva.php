<?php /** @var ?array $claveNueva */ if (!empty($claveNueva)): ?>
<div class="clave-nueva" role="status">
    <b>Datos para entrar<?= !empty($claveNueva['empresa']) ? ' de ' . e($claveNueva['empresa']) : '' ?></b> (cópialos ahora: la contraseña no se vuelve a mostrar)<br>
    Página: <code><?= e(rtrim((string) (getenv('TUWA_URL') ?: ''), '/') ?: 'https://whatsapp.tukanec.com') ?></code><br>
    Correo: <code><?= e($claveNueva['correo']) ?></code><br>
    Contraseña: <code><?= e($claveNueva['clave']) ?></code>
    <p class="suave pequeno">Puede cambiarla en Mi cuenta (ícono de la persona arriba).</p>
</div>
<?php endif; ?>
