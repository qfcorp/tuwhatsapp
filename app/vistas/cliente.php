<?php
/** @var array $empresa @var list<array> $usuarios @var list<array> $numeros @var ?array $claveNueva @var list<array> $registroCentral */
use TuWhatsApp\Empresas;
use TuWhatsApp\Telefono;
$e = $empresa;
$esTukan = (int) $e['id'] === Empresas::TUKAN;
?>
<div class="chat-cabeza">
    <a href="<?= e(url('clientes')) ?>" class="volver" aria-label="Volver a clientes">‹</a>
    <div><h1><?= u($e['nombre']) ?></h1><p class="suave"><?= Empresas::alDia($e) ? 'Al día' : e(Empresas::motivoBloqueo($e)) ?> · creado <?= e((new DateTimeImmutable($e['creado']))->format('d/m/Y')) ?></p></div>
</div>
<?php require __DIR__ . '/_clave_nueva.php'; ?>

<p class="suave pequeno">Términos: <?= $esTukan ? 'no aplica' : ($e['terminos_version'] !== '' ? 'aceptados el ' . e((new DateTimeImmutable((string) $e['terminos_aceptados_en']))->format('d/m/Y H:i')) . ' (versión ' . e($e['terminos_version']) . ')' : 'todavía no los acepta') ?></p>
<form method="post" action="<?= e(url('ver_cuenta')) ?>">
    <?= csrf_campo() ?><input type="hidden" name="empresa_id" value="<?= (int) $e['id'] ?>">
    <button class="boton boton-grande"><?= $esTukan ? 'Ir a mi cuenta' : 'Entrar a su cuenta (soporte)' ?></button>
</form>

<form method="post" class="tarjeta formulario" style="margin-top:14px">
    <?= csrf_campo() ?><input type="hidden" name="accion" value="datos">
    <h2>Plan y datos</h2>
    <label>Nombre del negocio<input name="nombre" required maxlength="100" value="<?= e($e['nombre']) ?>"></label>
    <div class="fila-doble">
        <label>Estado<select name="estado">
            <?php foreach (Empresas::ESTADOS as $k => $n): if ($esTukan && $k !== 'activa') continue; ?>
            <option value="<?= $k ?>" <?= $e['estado'] === $k ? 'selected' : '' ?>><?= e($n) ?></option>
            <?php endforeach; ?>
        </select></label>
        <label>Vence (vacío = sin fecha)<input type="date" name="vence" value="<?= e((string) $e['vence']) ?>"></label>
    </div>
    <label>Motivo de suspensión (lo ve el cliente)<input name="motivo_suspension" maxlength="300" value="<?= e($e['motivo_suspension']) ?>" placeholder="Pago pendiente de octubre"></label>
    <div class="fila-doble">
        <label>Números permitidos<input type="number" name="max_numeros" min="0" max="100" value="<?= (int) $e['max_numeros'] ?>"></label>
        <label>Usuarios permitidos<input type="number" name="max_usuarios" min="1" max="500" value="<?= (int) $e['max_usuarios'] ?>"></label>
    </div>
    <label>Persona de contacto<input name="contacto" maxlength="100" value="<?= e($e['contacto']) ?>"></label>
    <div class="fila-doble">
        <label>Teléfono<input name="telefono" inputmode="tel" value="<?= e($e['telefono'] !== '' ? Telefono::bonito($e['telefono']) : '') ?>"></label>
        <label>Correo<input type="email" name="correo" value="<?= e($e['correo']) ?>"></label>
    </div>
    <label>Notas internas (solo Tukán)<textarea name="notas" rows="3"><?= u($e['notas']) ?></textarea></label>
    <button class="boton">Guardar</button>
    <?php if ($e['telefono'] !== ''): ?>
    <a class="boton boton-gratis" href="<?= e(Telefono::enlace($e['telefono'])) ?>" target="_blank" rel="noopener">Escribirle por WhatsApp</a>
    <?php endif; ?>
</form>

<form method="post" class="tarjeta formulario">
    <?= csrf_campo() ?>
    <h2>Módulos del plan</h2>
    <?php if ($esTukan): ?>
    <p class="suave">La cuenta de Tukán tiene todos los módulos siempre.</p>
    <?php else: $activos = \TuWhatsApp\Modulos::deEmpresa($e); ?>
    <div class="fila-botones planes">
        <?php foreach (\TuWhatsApp\Modulos::PLANES as $k => [$nombre, $precio, $nums, $usus]): ?>
        <button class="boton boton-claro" name="accion" value="plan:<?= e($k) ?>"><?= e($nombre) ?> <small>$<?= (int) $precio ?>/mes · <?= (int) $nums ?> nº · <?= (int) $usus ?> usuarios</small></button>
        <?php endforeach; ?>
    </div>
    <p class="suave pequeno">Un toque aplica el plan completo. O marca a mano lo que incluye:</p>
    <p class="suave pequeno">Lo básico (bandeja, chat con fotos y audios, contactos, pendientes, respuestas automáticas con botones, plantillas, respuestas rápidas y gasto) siempre está. Marca lo que incluye su plan:</p>
    <?php foreach (\TuWhatsApp\Modulos::LISTA as $k => [$nombre, $texto]): ?>
    <label class="casilla"><input type="checkbox" name="modulos[]" value="<?= e($k) ?>" <?= in_array($k, $activos, true) ? 'checked' : '' ?>>
        <span><b><?= e($nombre) ?></b><br><small class="suave"><?= e($texto) ?></small></span></label>
    <?php endforeach; ?>
    <button class="boton" name="accion" value="modulos">Guardar módulos</button>
    <?php endif; ?>
</form>

<section class="tarjeta">
    <h2>Números (<?= count($numeros) ?>/<?= (int) $e['max_numeros'] ?>)</h2>
    <?php if (!$numeros): ?><p class="suave">Todavía no conecta ningún número. Lo hace él mismo con el botón "Conectar con WhatsApp Business" (o entra a su cuenta y ayúdale).</p><?php endif; ?>
    <?php foreach ($numeros as $n): ?>
    <p><b><?= u($n['nombre']) ?></b> · <?= e($n['telefono'] !== '' ? Telefono::bonito($n['telefono']) : 'sin número') ?> · <?= $n['coexistencia'] ? 'con la app del celular' : 'solo API' ?> · automático <?= $n['auto_activo'] ? 'encendido' : 'apagado' ?> · tope <?= e(dinero($n['tope_mensual'])) ?></p>
    <?php endforeach; ?>
</section>

<h2 class="separador">Usuarios</h2>
<ul class="lista-simple lista-usuarios">
    <?php foreach ($usuarios as $x): ?>
    <li>
        <span><b><?= u($x['nombre']) ?></b> <?= $x['activo'] ? '' : '<small class="chip chip-estado-suspendida">Desactivado</small>' ?><br>
            <span class="suave"><?= e($x['correo']) ?> · <?= $x['super'] ? 'Personal de Tukán' : e(Empresas::ROLES[$x['rol']] ?? $x['rol']) ?></span></span>
        <?php if (!$x['super']): ?>
        <form method="post">
            <?= csrf_campo() ?><input type="hidden" name="accion" value="usuario"><input type="hidden" name="id" value="<?= (int) $x['id'] ?>">
            <button class="boton boton-claro" name="que" value="clave">Contraseña nueva</button>
            <button class="boton boton-claro" name="que" value="<?= $x['activo'] ? 'desactivar' : 'activar' ?>"><?= $x['activo'] ? 'Desactivar' : 'Activar' ?></button>
        </form>
        <?php endif; ?>
    </li>
    <?php endforeach; ?>
</ul>
<?php if (!empty($registroCentral) || ($e['origen'] ?? '') === 'central'): ?>
<section class="tarjeta">
    <h2>Desde el Panel Tukán Central</h2>
    <?php if (($e['origen'] ?? '') === 'central'): ?><p class="suave pequeno">Esta demo se creó desde el Panel Tukán Central.</p><?php endif; ?>
    <?php foreach ($registroCentral ?? [] as $r): ?>
    <p class="pequeno"><b><?= e((new DateTimeImmutable($r['momento']))->format('d/m/Y H:i')) ?></b> · <?= e($r['texto']) ?><br><small class="suave"><?= e($r['quien']) ?></small></p>
    <?php endforeach; ?>
</section>
<?php endif; ?>
<details class="tarjeta plegable">
    <summary>Agregar usuario a este cliente</summary>
    <form method="post" class="formulario">
        <?= csrf_campo() ?><input type="hidden" name="accion" value="agregar_usuario">
        <label>Nombre<input name="nombre" required maxlength="100"></label>
        <label>Correo<input type="email" name="correo" required></label>
        <label>Rol<select name="rol"><option value="admin">Administrador</option><option value="agente">Agente</option></select></label>
        <button class="boton">Crear usuario</button>
    </form>
</details>
