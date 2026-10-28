<?php /** @var array $empresa */ ?>
<section class="tarjeta" style="max-width:560px;margin:4vh auto 0">
    <h1>Antes de empezar</h1>
    <p>Hola, <b><?= u(usuario()['nombre']) ?></b>. Para usar <?= e(nombre_programa()) ?> en <b><?= u($empresa['nombre']) ?></b> necesitamos que el administrador acepte:</p>
    <ul>
        <li><a href="/terminos.php" target="_blank" rel="noopener">Términos y condiciones</a> (qué pagas a Tukán, qué pagas a Meta y las reglas de uso).</li>
        <li><a href="/tratamiento-datos.php" target="_blank" rel="noopener">Acuerdo de tratamiento de datos</a> (cómo cuidamos los datos de tus clientes, según la ley de Ecuador).</li>
    </ul>
    <p class="nota">Lo más importante: manda promociones solo a quien aceptó recibirlas y respeta a quien pide la baja. Si no, Meta puede bloquear tu número.</p>
    <?php if (es_admin()): ?>
    <form method="post" class="formulario">
        <?= csrf_campo() ?>
        <label class="check"><input type="checkbox" name="acepto" value="1" required> Leí y acepto los términos y condiciones y el acuerdo de tratamiento de datos en nombre de <?= u($empresa['nombre']) ?>.</label>
        <button class="boton boton-grande">Aceptar y entrar</button>
    </form>
    <?php else: ?>
    <p class="nota">El administrador de tu cuenta tiene que aceptarlos primero. Pídeselo y vuelve a entrar.</p>
    <?php endif; ?>
    <p><a href="<?= e(url('salir')) ?>">Salir</a></p>
</section>
