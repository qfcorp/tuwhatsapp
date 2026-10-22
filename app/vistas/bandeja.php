<?php
/** @var array $linea @var string $filtro @var string $buscar @var list<array> $conversaciones */
use TuWhatsApp\Contactos;
$filtros = ['todos' => 'Todos', 'sin_leer' => 'Sin leer', 'mios' => 'Míos', 'sin_asignar' => 'Sin asignar', 'cliente' => 'Clientes', 'desconocido' => 'Desconocidos', 'personal' => 'Personales'];
if (!modulo('agentes')) {
    unset($filtros['mios'], $filtros['sin_asignar']);
}
?>
<div class="cabeza">
    <h1>Bandeja</h1>
    <span class="estado-auto <?= $linea['auto_activo'] ? 'si' : 'no' ?>"><?php if (es_admin()): ?><a href="<?= e(url('reglas')) ?>">Automático: <?= $linea['auto_activo'] ? 'encendido' : 'apagado' ?></a><?php else: ?><a>Automático: <?= $linea['auto_activo'] ? 'encendido' : 'apagado' ?></a><?php endif; ?></span>
</div>
<div class="avisos-navegador" id="avisos-navegador" hidden>
    <button type="button" class="boton boton-claro" id="activar-avisos">🔔 Avisarme cuando llegue un mensaje</button>
    <span class="suave pequeno" id="avisos-estado"></span>
</div>
<form class="buscar" method="get">
    <input type="hidden" name="r" value="bandeja"><input type="hidden" name="f" value="<?= e($filtro) ?>">
    <input type="search" name="b" value="<?= e($buscar) ?>" placeholder="Buscar nombre o número" aria-label="Buscar">
</form>
<nav class="pestanas" aria-label="Filtro">
    <?php foreach ($filtros as $k => $n): ?>
    <a href="<?= e(url('bandeja', ['f' => $k, 'b' => $buscar ?: null])) ?>" class="<?= $filtro === $k ? 'activo' : '' ?>"><?= e($n) ?></a>
    <?php endforeach; ?>
</nav>
<?php if (!$conversaciones): ?>
<div class="vacio">
    <p><b>Todavía no hay mensajes aquí.</b></p>
    <p class="suave">Cuando alguien escriba a tu número de WhatsApp aparecerá en esta lista.</p>
</div>
<?php else: ?>
<ul class="conversaciones" id="conversaciones" data-linea="<?= (int) $linea['id'] ?>">
    <?php foreach ($conversaciones as $c): ?>
    <li>
        <a href="<?= e(url('chat', ['c' => $c['id']])) ?>" class="<?= $c['sin_leer'] > 0 ? 'nuevo' : '' ?>">
            <span class="avatar tipo-<?= e($c['tipo']) ?>" aria-hidden="true"><?= u(mb_strtoupper(mb_substr(Contactos::nombre($c), 0, 1))) ?></span>
            <span class="conv-centro">
                <span class="conv-nombre"><?= u(Contactos::nombre($c)) ?> <small class="chip chip-<?= e($c['tipo']) ?>"><?= e(tipo_contacto($c['tipo'])) ?></small><?php if (!empty($c['asignado_nombre'])): ?> <small class="chip chip-asignado"><?= u(explode(' ', (string) $c['asignado_nombre'])[0]) ?></small><?php endif; ?></span>
                <span class="conv-ultimo"><?= $c['ultima_direccion'] === 'sale' ? '<span class="tu">Tú:</span> ' : '' ?><?= u(mb_strimwidth((string) $c['ultimo_texto'], 0, 80, '…')) ?></span>
            </span>
            <span class="conv-lado">
                <time><?= e(hora_corta($c['ultimo_en'])) ?></time>
                <?php if ($c['sin_leer'] > 0): ?><b class="globo"><?= (int) $c['sin_leer'] ?></b><?php endif; ?>
            </span>
        </a>
    </li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>
<details class="tarjeta plegable">
    <summary>Escribir a un número nuevo</summary>
    <form method="post" action="<?= e(url('nuevo_chat')) ?>" class="formulario">
        <?= csrf_campo() ?>
        <label>Número<input name="telefono" inputmode="tel" required placeholder="0991234567"></label>
        <label>Nombre (opcional)<input name="nombre" maxlength="100"></label>
        <label>Es<select name="tipo"><option value="cliente">Cliente</option><option value="desconocido">Desconocido</option><option value="personal">Personal</option></select></label>
        <button class="boton">Abrir conversación</button>
    </form>
</details>
