<?php
/** @var array $empresa @var list<array> $usuarios @var ?array $claveNueva @var ?string $tokenNuevo @var int $numeros */
use TuWhatsApp\Empresas;
$yo = usuario();
$activos = count(array_filter($usuarios, fn ($x) => $x['activo']));
?>
<div class="cabeza"><h1>Mi cuenta</h1></div>
<?php require __DIR__ . '/_clave_nueva.php'; ?>

<section class="tarjeta">
    <h2><?= e($empresa['nombre']) ?></h2>
    <p class="suave">
        Plan: <b><?= e(Empresas::ESTADOS[$empresa['estado']]) ?></b><?= $empresa['vence'] ? ' hasta el ' . e((new DateTimeImmutable($empresa['vence']))->format('d/m/Y')) : '' ?> ·
        Números: <?= $numeros ?> de <?= (int) $empresa['max_numeros'] ?> · Usuarios: <?= $activos ?> de <?= (int) $empresa['max_usuarios'] ?>
    </p>
    <p class="suave pequeno">Entraste como <b><?= e($yo['nombre']) ?></b> (<?= e($yo['correo']) ?>) · <?= es_super() ? 'Personal de Tukán' : e(Empresas::ROLES[$yo['rol']] ?? $yo['rol']) ?></p>
</section>

<details class="tarjeta plegable">
    <summary>Cambiar mi contraseña</summary>
    <form method="post" class="formulario">
        <?= csrf_campo() ?><input type="hidden" name="accion" value="mi_clave">
        <label>Contraseña actual<input type="password" name="actual" required autocomplete="current-password"></label>
        <label>Contraseña nueva (mínimo 8)<input type="password" name="nueva" required minlength="8" autocomplete="new-password"></label>
        <label>Repite la nueva<input type="password" name="repetir" required minlength="8" autocomplete="new-password"></label>
        <button class="boton">Cambiar contraseña</button>
    </form>
</details>

<?php if (es_admin()): ?>
<h2 class="separador">Equipo</h2>
<p class="suave"><b>Administrador</b>: configura todo (reglas, gasto, números, equipo). <b>Agente</b>: solo atiende la bandeja, pendientes y contactos.</p>
<ul class="lista-simple lista-usuarios">
    <?php foreach ($usuarios as $x): ?>
    <li>
        <span><b><?= e($x['nombre']) ?></b> <?= $x['activo'] ? '' : '<small class="chip chip-estado-suspendida">Desactivado</small>' ?><br>
            <span class="suave"><?= e($x['correo']) ?> · <?= $x['super'] ? 'Personal de Tukán' : e(Empresas::ROLES[$x['rol']] ?? $x['rol']) ?></span></span>
        <?php if ((int) $x['id'] !== (int) $yo['id'] && !$x['super']): ?>
        <form method="post">
            <?= csrf_campo() ?><input type="hidden" name="accion" value="usuario"><input type="hidden" name="id" value="<?= (int) $x['id'] ?>">
            <?php if ($x['activo']): ?>
            <button class="boton boton-claro" name="que" value="<?= $x['rol'] === 'admin' ? 'agente' : 'admin' ?>">Hacer <?= $x['rol'] === 'admin' ? 'agente' : 'administrador' ?></button>
            <button class="boton boton-claro" name="que" value="clave">Contraseña nueva</button>
            <button class="boton boton-claro" name="que" value="desactivar">Desactivar</button>
            <?php else: ?>
            <button class="boton boton-claro" name="que" value="activar">Activar</button>
            <?php endif; ?>
        </form>
        <?php endif; ?>
    </li>
    <?php endforeach; ?>
</ul>
<?php if ($activos < (int) $empresa['max_usuarios']): ?>
<details class="tarjeta plegable">
    <summary>Agregar una persona</summary>
    <form method="post" class="formulario">
        <?= csrf_campo() ?><input type="hidden" name="accion" value="agregar_usuario">
        <label>Nombre<input name="nombre" required maxlength="100"></label>
        <label>Correo<input type="email" name="correo" required></label>
        <label>Rol<select name="rol"><option value="agente">Agente (solo atiende)</option><option value="admin">Administrador</option></select></label>
        <button class="boton">Crear usuario</button>
    </form>
</details>
<?php else: ?>
<p class="nota">Tu plan permite <?= (int) $empresa['max_usuarios'] ?> usuarios. Para agregar más, escribe a Tukán.</p>
<?php endif; ?>

<h2 class="separador">Conectar otros programas (API)</h2>
<section class="tarjeta">
    <p class="suave">Para que tu otro sistema (por ejemplo TuSalón) agregue mensajes a Pendientes o registre clientes. <?= $empresa['api_token_hash'] !== '' ? 'Ya tienes un token creado.' : 'Todavía no tienes token.' ?></p>
    <?php if ($tokenNuevo): ?>
    <div class="clave-nueva">Tu token (cópialo ahora, no se vuelve a mostrar):<br><code><?= e($tokenNuevo) ?></code></div>
    <?php endif; ?>
    <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="token_api">
        <button class="boton boton-claro"><?= $empresa['api_token_hash'] !== '' ? 'Crear token nuevo (el anterior deja de servir)' : 'Crear token' ?></button>
    </form>
</section>
<?php endif; ?>
