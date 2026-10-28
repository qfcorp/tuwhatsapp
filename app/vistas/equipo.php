<?php
/** @var array $linea @var array $t @var list<array> $equipo */
use TuWhatsApp\{Reparto, Reportes};
$reglas = Reparto::reglas($linea);
$reglas[] = ['palabras' => '', 'usuario_id' => 0];
$reglas[] = ['palabras' => '', 'usuario_id' => 0];
?>
<div class="cabeza"><h1>Equipo y reparto</h1></div>

<section class="tarjeta">
    <h2>Ahora mismo</h2>
    <p><b><?= (int) $t['sin_asignar'] ?></b> <?= $t['sin_asignar'] === 1 ? 'conversación esperando' : 'conversaciones esperando' ?> sin nadie asignado.
    <?php if ($t['sin_asignar'] > 0): ?><a href="<?= e(url('bandeja', ['f' => 'sin_asignar'])) ?>">Ver</a><?php endif; ?></p>
    <?php if (!$t['personas']): ?>
    <p class="suave">Todavía no hay personas en el equipo. Agrégalas en <a href="<?= e(url('cuenta')) ?>">Mi cuenta</a>.</p>
    <?php else: ?>
    <div class="tabla-desliza"><table class="tabla equipo">
        <thead><tr><th>Persona</th><th>Esperando respuesta</th><th>Asignadas</th><th>Sin leer</th><th>Atendió hoy</th><th>1.ª respuesta (7 días)</th><th>Disponible</th></tr></thead>
        <tbody>
        <?php foreach ($t['personas'] as $p): ?>
        <tr>
            <td><b><?= u($p['nombre']) ?></b><br><small class="suave"><?= $p['rol'] === 'admin' ? 'Administrador' : 'Agente' ?><?= $p['recibe_chats'] ? '' : ' · no recibe chats' ?></small></td>
            <td><?= (int) $p['esperando'] ?><?= $p['espera_desde'] ? '<br><small class="suave">desde ' . e(hora_corta($p['espera_desde'])) . '</small>' : '' ?></td>
            <td><?= (int) $p['asignadas'] ?></td>
            <td><?= (int) $p['sin_leer'] ?></td>
            <td><?= (int) $p['atendidos_hoy'] ?></td>
            <td><?= e(Reportes::duracion($p['primera_respuesta'] !== null ? (int) $p['primera_respuesta'] : null)) ?></td>
            <td><form method="post" class="en-linea"><?= csrf_campo() ?><input type="hidden" name="accion" value="persona"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                <input type="hidden" name="que" value="disponible"><input type="hidden" name="valor" value="<?= $p['disponible'] ? '0' : '1' ?>">
                <button class="chip <?= $p['disponible'] ? 'chip-cliente' : '' ?>" title="Cambiar"><?= $p['disponible'] ? 'Disponible' : 'Ausente' ?></button></form></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</section>

<form method="post" class="tarjeta formulario">
    <?= csrf_campo() ?><input type="hidden" name="accion" value="reparto">
    <h2>Reparto automático</h2>
    <p class="suave pequeno">Cuando escribe alguien que no tiene a nadie asignado (o el chatbot o el agente con IA lo pasan a una persona), se le asigna solo a una persona <b>disponible</b> que recibe chats.</p>
    <?php foreach (Reparto::MODOS as $k => $n): ?>
    <label class="check"><input type="radio" name="reparto" value="<?= e($k) ?>" <?= ($linea['reparto'] ?? 'manual') === $k ? 'checked' : '' ?>> <?= e($n) ?></label>
    <?php endforeach; ?>
    <h2>Por tema (opcional)</h2>
    <p class="suave pequeno">Si el mensaje dice alguna de estas palabras, va a esa persona (si está disponible). La primera regla que coincide gana.</p>
    <?php foreach ($reglas as $i => $r): ?>
    <div class="fila-doble">
        <label>Palabras<input name="regla_palabras[<?= $i ?>]" maxlength="300" value="<?= e($r['palabras']) ?>" placeholder="factura, pago, transferencia"></label>
        <label>Va a<select name="regla_usuario[<?= $i ?>]"><option value="0">Nadie</option>
            <?php foreach ($equipo as $u): ?><option value="<?= (int) $u['id'] ?>" <?= (int) $r['usuario_id'] === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['nombre']) ?></option><?php endforeach; ?>
        </select></label>
    </div>
    <?php endforeach; ?>
    <button class="boton">Guardar reparto</button>
</form>

<form method="post" class="tarjeta formulario">
    <?= csrf_campo() ?><input type="hidden" name="accion" value="recibe">
    <h2>Quién recibe conversaciones</h2>
    <?php foreach ($equipo as $u): ?>
    <label class="check"><input type="checkbox" name="recibe[]" value="<?= (int) $u['id'] ?>" <?= $u['recibe_chats'] ? 'checked' : '' ?>> <?= e($u['nombre']) ?></label>
    <?php endforeach; ?>
    <button class="boton boton-claro">Guardar</button>
</form>

<?php if (count($equipo) > 1): ?>
<form method="post" class="tarjeta formulario">
    <?= csrf_campo() ?><input type="hidden" name="accion" value="pasar">
    <h2>Pasar conversaciones</h2>
    <p class="suave pequeno">Por ejemplo, si alguien se va de vacaciones: todas sus conversaciones pasan a otra persona.</p>
    <div class="fila-doble">
        <label>De<select name="de" required><?php foreach ($equipo as $u): ?><option value="<?= (int) $u['id'] ?>"><?= e($u['nombre']) ?></option><?php endforeach; ?></select></label>
        <label>A<select name="a"><option value="">Nadie (sin asignar)</option><?php foreach ($equipo as $u): ?><option value="<?= (int) $u['id'] ?>"><?= e($u['nombre']) ?></option><?php endforeach; ?></select></label>
    </div>
    <button class="boton boton-claro">Pasar todas</button>
</form>
<?php endif; ?>
