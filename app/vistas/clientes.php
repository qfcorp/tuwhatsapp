<?php
/** @var list<array> $empresas */
use TuWhatsApp\Empresas;
$hoy = new DateTimeImmutable('today');
$en30 = $hoy->modify('+30 days')->format('Y-m-d');
?>
<div class="cabeza"><h1>Clientes de Tukán</h1><span class="suave"><?= count($empresas) ?> cuentas</span></div>

<details class="tarjeta plegable" <?= count($empresas) <= 1 ? 'open' : '' ?>>
    <summary>Crear cliente nuevo</summary>
    <form method="post" class="formulario">
        <?= csrf_campo() ?>
        <label>Nombre del negocio<input name="nombre" required maxlength="100" placeholder="Salón Bella"></label>
        <div class="fila-doble">
            <label>Estado<select name="estado"><option value="demo">Demo</option><option value="activa">Activa (pagando)</option></select></label>
            <label>Vence<input type="date" name="vence" value="<?= e($hoy->modify('+7 days')->format('Y-m-d')) ?>"></label>
        </div>
        <div class="fila-doble">
            <label>Números permitidos<input type="number" name="max_numeros" min="0" max="100" value="1"></label>
            <label>Usuarios permitidos<input type="number" name="max_usuarios" min="1" max="500" value="3"></label>
        </div>
        <label>Persona de contacto<input name="contacto" maxlength="100"></label>
        <div class="fila-doble">
            <label>Teléfono<input name="telefono" inputmode="tel" placeholder="0991234567"></label>
            <label>Correo del negocio<input type="email" name="correo"></label>
        </div>
        <h3>Primer usuario (administrador)</h3>
        <label>Su nombre<input name="admin_nombre" maxlength="100"></label>
        <label>Correo con el que va a entrar<input type="email" name="admin_correo" required></label>
        <label>Notas internas (solo Tukán)<textarea name="notas" rows="2"></textarea></label>
        <button class="boton boton-grande">Crear cliente</button>
        <p class="suave pequeno">Al crearlo te muestro la contraseña una sola vez para que se la pases.</p>
    </form>
</details>

<ul class="lista-simple" style="background:none;border:0">
<?php foreach ($empresas as $x):
    $alDia = Empresas::alDia($x);
    $etiqueta = $x['estado'] === 'suspendida' ? 'Suspendida' : (!$alDia ? 'Vencida' : Empresas::ESTADOS[$x['estado']]);
    $claseEt = $x['estado'] === 'suspendida' ? 'suspendida' : (!$alDia ? 'vencida' : $x['estado']);
    $porVencer = $alDia && $x['vence'] && $x['vence'] <= $en30;
?>
    <li style="padding:0;border:0;display:block">
        <a class="tarjeta tarjeta-cliente" href="<?= e(url('cliente', ['id' => $x['id']])) ?>">
            <div class="pl-cabeza"><b><?= e($x['nombre']) ?><?= (int) $x['id'] === Empresas::TUKAN ? ' (nosotros)' : '' ?></b><span class="chip chip-estado-<?= e($claseEt) ?>"><?= e($etiqueta) ?></span></div>
            <div class="cifras">
                <span><?= (int) $x['numeros'] ?>/<?= (int) $x['max_numeros'] ?> números</span>
                <span><?= (int) $x['usuarios'] ?>/<?= (int) $x['max_usuarios'] ?> usuarios</span>
                <span><?= (int) $x['mensajes_mes'] ?> mensajes este mes</span>
                <span>Meta: <?= e(dinero($x['gasto_mes'])) ?></span>
                <?php if ($x['vence']): ?><span class="<?= $porVencer ? 'texto-alerta' : '' ?>">Vence <?= e((new DateTimeImmutable($x['vence']))->format('d/m/Y')) ?></span><?php endif; ?>
                <span>Último mensaje: <?= $x['ultimo_mensaje'] ? e(hora_corta($x['ultimo_mensaje'])) : 'nunca' ?></span>
            </div>
        </a>
    </li>
<?php endforeach; ?>
</ul>
