<?php
/** @var array $empresa @var list<array> $usuarios @var ?array $claveNueva @var ?string $tokenNuevo @var int $numeros @var array $plan */
use TuWhatsApp\Empresas;
$yo = usuario();
$activos = count(array_filter($usuarios, fn ($x) => $x['activo']));
?>
<div class="cabeza"><h1>Mi cuenta</h1></div>
<?php require __DIR__ . '/_clave_nueva.php'; ?>

<section class="tarjeta">
    <h2><?= u($empresa['nombre']) ?></h2>
    <p class="suave">
        Plan: <b><?= e(Empresas::ESTADOS[$empresa['estado']]) ?></b><?= $empresa['vence'] ? ' hasta el ' . e((new DateTimeImmutable($empresa['vence']))->format('d/m/Y')) : '' ?> ·
        Números: <?= $numeros ?> de <?= (int) $empresa['max_numeros'] ?> · Usuarios: <?= $activos ?> de <?= (int) $empresa['max_usuarios'] ?>
    </p>
    <p class="suave pequeno">Entraste como <b><?= u($yo['nombre']) ?></b> (<?= e($yo['correo']) ?>) · <?= es_super() ? 'Personal de Tukán' : e(Empresas::ROLES[$yo['rol']] ?? $yo['rol']) ?></p>
</section>

<?php if ((int) $empresa['id'] !== 1 && !es_super()): $planNombre = \TuWhatsApp\Modulos::PLANES[$plan['plan']][0] ?? 'Todo incluido'; $cancela = !empty($empresa['cancelar_en']); ?>
<section class="tarjeta plan-cuenta" id="plan">
    <div class="pp-cabeza">
        <h2>Tu plan: <?= e($planNombre) ?></h2>
        <?php if (!en_app_android()): ?><span class="plan-precio"><b><?= e(dinero($plan['mensual'])) ?></b> al mes</span><?php endif; ?>
    </div>
    <?php if ($cancela): ?>
    <div class="nota">Pediste cancelar: sigues usando todo hasta el <b><?= e((new DateTimeImmutable($empresa['cancelar_en']))->format('d/m/Y')) ?></b> y ya no se renueva.</div>
    <?php elseif ($empresa['estado'] === 'demo'): ?>
    <p class="suave">Estás en la <b>prueba gratis</b><?= $empresa['vence'] ? ' hasta el <b>' . e((new DateTimeImmutable($empresa['vence']))->format('d/m/Y')) . '</b>' : '' ?>. No se cobra nada hasta que decidas seguir.</p>
    <?php else: ?>
    <p class="suave"><?= $empresa['vence'] ? 'Próxima renovación: <b>' . e((new DateTimeImmutable($empresa['vence']))->format('d/m/Y')) . '</b>.' : 'Mes a mes, sin contrato.' ?>
    <?= en_app_android() ? '' : 'Sin contrato y sin recargo sobre lo que cobra Meta (Meta te cobra directo a tu tarjeta).' ?></p>
    <?php endif; ?>
    <details class="plegable-simple">
        <summary>Qué incluye</summary>
        <ul class="incluye">
            <?php foreach (\TuWhatsApp\Modulos::BASE as $b): ?><li><?= e($b) ?></li><?php endforeach; ?>
            <?php foreach (\TuWhatsApp\Modulos::LISTA as $k => $m): ?>
            <li class="<?= in_array($k, $plan['activos'], true) ? '' : 'no-incluye' ?>"><?= e($m[0]) ?> <?= in_array($k, $plan['activos'], true) ? '' : '<small class="suave">(extra' . (en_app_android() ? '' : ' de ' . e(dinero(\TuWhatsApp\Modulos::PRECIOS[$k] ?? 0))) . ' al mes)</small>' ?></li>
            <?php endforeach; ?>
        </ul>
    </details>
    <?php if (es_admin()): ?>
    <?php if ($cancela): ?>
    <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="seguir_plan"><button class="boton">Seguir con mi plan</button></form>
    <?php elseif ($empresa['estado'] !== 'demo'): ?>
    <details class="plegable-simple cancelar-plan">
        <summary>Cancelar mi plan</summary>
        <form method="post" class="formulario">
            <?= csrf_campo() ?><input type="hidden" name="accion" value="cancelar_plan">
            <p class="suave pequeno">Sigues usando todo hasta el fin del período que ya pagaste y después la cuenta se pausa. Tus chats y contactos se guardan 90 días por si vuelves.</p>
            <label>¿Por qué te vas? (opcional, nos ayuda a mejorar)<textarea name="motivo" rows="2" maxlength="500"></textarea></label>
            <label class="check"><input type="checkbox" name="confirmo" value="1" required> Sí, quiero cancelar mi plan</label>
            <button class="boton boton-peligro">Cancelar mi plan</button>
        </form>
    </details>
    <?php endif; ?>
    <?php endif; ?>
</section>
<?php endif; ?>

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
        <span><b><?= u($x['nombre']) ?></b> <?= $x['activo'] ? '' : '<small class="chip chip-estado-suspendida">Desactivado</small>' ?><br>
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
