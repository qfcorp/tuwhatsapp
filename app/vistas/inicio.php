<?php
/** @var array $linea @var array $cifras @var list<array> $pasos @var ?array $resumen @var string $usuarioNombre */
$hechos = count(array_filter($pasos, fn ($p) => $p['hecho']));
$total = count($pasos);
$hora = (int) date('G');
$saludo = $hora < 12 ? 'Buenos días' : ($hora < 19 ? 'Buenas tardes' : 'Buenas noches');
$tarjetas = [
    ['bandeja', ['f' => 'sin_leer'], $cifras['sin_leer'], 'Mensajes sin leer', $cifras['sin_leer'] > 0 ? 'naranja' : ''],
    ['bandeja', [], $cifras['sin_responder'], 'Esperan respuesta', $cifras['sin_responder'] > 0 ? 'naranja' : ''],
    ['bandeja', [], $cifras['escribieron'], 'Te escribieron hoy', ''],
    ['contactos', [], $cifras['nuevos'], 'Clientes nuevos hoy', ''],
];
if (modulo('catalogo')) {
    $tarjetas[] = ['pedidos', [], dinero($cifras['pagados_total']), 'Cobrado hoy (' . $cifras['pagados_n'] . ' pedidos)', $cifras['pagados_n'] > 0 ? 'verde' : ''];
    $tarjetas[] = ['pedidos', [], $cifras['pendientes_n'], 'Pedidos por cobrar', ''];
}
if (modulo('embudo')) {
    $tarjetas[] = ['embudo', [], dinero($cifras['en_juego']), 'En juego en el embudo', ''];
}
if (!empty($linea['llamadas_activo'])) {
    $tarjetas[] = ['llamadas', [], $cifras['perdidas'], 'Llamadas sin contestar hoy', $cifras['perdidas'] > 0 ? 'naranja' : ''];
}
?>
<div class="cabeza">
    <div>
        <h1><?= e($saludo) ?>, <?= u(explode(' ', $usuarioNombre)[0]) ?></h1>
        <p class="suave"><?= u($linea['nombre']) ?> · <?= e(fecha_larga(new DateTimeImmutable())) ?></p>
    </div>
    <a class="boton" href="<?= e(url('bandeja')) ?>">Ir a la bandeja</a>
</div>

<section class="cifras-inicio" aria-label="Hoy">
    <?php foreach ($tarjetas as [$ruta, $param, $valor, $texto, $tono]): ?>
    <a class="cifra-tarjeta <?= $tono ? 'tono-' . $tono : '' ?>" href="<?= e(url($ruta, $param)) ?>">
        <b><?= is_int($valor) ? (int) $valor : e($valor) ?></b>
        <span><?= e($texto) ?></span>
    </a>
    <?php endforeach; ?>
</section>

<?php if (es_admin() && $hechos < $total && !cuenta_muestra(empresa_actual())): ?>
<section class="tarjeta primeros-pasos">
    <div class="pp-cabeza">
        <h2>Primeros pasos</h2>
        <span class="suave pequeno"><?= $hechos ?> de <?= $total ?> listos</span>
    </div>
    <div class="tope-barra grande" role="progressbar" aria-valuemin="0" aria-valuemax="<?= $total ?>" aria-valuenow="<?= $hechos ?>"><span style="width: <?= (int) round($hechos * 100 / max(1, $total)) ?>%"></span></div>
    <ol class="pasos-lista">
        <?php foreach ($pasos as $p): ?>
        <li class="<?= $p['hecho'] ? 'hecho' : '' ?>">
            <span class="paso-check" aria-hidden="true"><?= $p['hecho'] ? '✓' : '' ?></span>
            <span class="paso-texto"><b><?= e($p['titulo']) ?></b><br><span class="suave pequeno"><?= e($p['texto']) ?></span></span>
            <?php if (!$p['hecho']): ?><a class="boton boton-claro" href="<?= e(url($p['ruta'])) ?>">Hacerlo</a><?php else: ?><span class="pequeno paso-listo">Listo</span><?php endif; ?>
        </li>
        <?php endforeach; ?>
    </ol>
</section>
<?php endif; ?>

<?php if ($resumen !== null): ?>
<section class="tarjeta">
    <h2>☀️ Resumen de hoy</h2>
    <p><?= nl2br(e($resumen['texto'])) ?></p>
    <a class="pequeno" href="<?= e(url('asistente')) ?>">Pregúntale a la IA</a>
</section>
<?php elseif (modulo('ia')): ?>
<section class="tarjeta">
    <h2>✨ Pregúntale a la IA</h2>
    <p class="suave">"¿Quiénes están listos para comprar?", "¿Cómo nos fue esta semana?"</p>
    <a class="boton boton-claro" href="<?= e(url('asistente')) ?>">Preguntar</a>
</section>
<?php endif; ?>

<section class="accesos">
    <a class="acceso" href="<?= e(url('bandeja')) ?>"><span aria-hidden="true">💬</span>Bandeja</a>
    <a class="acceso" href="<?= e(url('contactos')) ?>"><span aria-hidden="true">👥</span>Contactos</a>
    <?php if (modulo('catalogo')): ?><a class="acceso" href="<?= e(url('pedidos')) ?>"><span aria-hidden="true">🛒</span>Pedidos</a><?php endif; ?>
    <?php if (es_admin()): ?><a class="acceso" href="<?= e(url('campanias')) ?>"><span aria-hidden="true">📣</span>Campañas</a><?php endif; ?>
    <?php if (es_admin()): ?><a class="acceso" href="<?= e(url('reportes')) ?>"><span aria-hidden="true">📊</span>Reportes</a><?php endif; ?>
    <a class="acceso" href="<?= e(url('gasto')) ?>"><span aria-hidden="true">💲</span>Gasto en Meta</a>
</section>
