<?php
/** @var array $linea @var array $cifras @var list<array> $pasos @var ?array $resumen @var string $usuarioNombre @var array $recientes */
// Inicio con la identidad común de Tukán (10 nov 2026): el mismo orden en todos los programas (docs/LINEA_GRAFICA.md):
// saludo → aviso → primeros pasos → cifras → tarjetas de lo importante del día → accesos rápidos.
$hechos = count(array_filter($pasos, fn ($p) => $p['hecho']));
$total = count($pasos);
[$emoji, $saludo] = saludo_hora();
$empresa = empresa_actual();
$verPasos = es_admin() && $hechos < $total && !cuenta_muestra($empresa) && empty($empresa['pasos_ocultos']);
// Cifras principales (las de siempre de Inicio): las 4 de la conversación + lo cobrado hoy si tiene catálogo
$cifrasKit = [
    ['bandeja', ['f' => 'sin_leer'], $cifras['sin_leer'], 'Mensajes sin leer', $cifras['sin_leer'] > 0 ? 'aviso' : 'ok', 'Toca para verlos'],
    ['bandeja', [], $cifras['sin_responder'], 'Esperan respuesta', $cifras['sin_responder'] > 0 ? 'mal' : 'ok', 'En las últimas 24 horas'],
    ['bandeja', [], $cifras['escribieron'], 'Te escribieron hoy', 'programa', 'Clientes distintos'],
    ['contactos', [], $cifras['nuevos'], 'Clientes nuevos hoy', '', 'Contactos nuevos'],
];
if (modulo('catalogo')) {
    $cifrasKit[] = ['pedidos', [], dinero($cifras['pagados_total']), 'Cobrado hoy (' . $cifras['pagados_n'] . ' pedidos)', $cifras['pagados_n'] > 0 ? 'ok' : '', 'Pedidos pagados hoy'];
}
// Lo demás que ya mostraba el Inicio va en la tarjeta «Ventas y llamadas»
$otros = [];
if (modulo('catalogo')) $otros[] = ['pedidos', '🛒', 'Pedidos por cobrar', (string) $cifras['pendientes_n']];
if (modulo('embudo')) $otros[] = ['embudo', '🎯', 'En juego en el embudo', dinero($cifras['en_juego'])];
if (!empty($linea['llamadas_activo'])) $otros[] = ['llamadas', '📞', 'Llamadas sin contestar hoy', (string) $cifras['perdidas']];
?>
<div class="tk-saludo">
    <h1><?= $emoji ?> <span><?= e($saludo) ?></span>, <?= u(explode(' ', $usuarioNombre)[0]) ?> 👋</h1>
    <p><span><?= u($linea['nombre']) ?> · <?= e(fecha_larga(new DateTimeImmutable())) ?></span> · <span>Tus chats, tus ventas y lo que hay que contestar hoy</span></p>
</div>

<?php if ($verPasos): ?>
<section class="tk-pasos primeros-pasos" aria-labelledby="pp">
    <div class="tk-tarjeta-cabeza"><div><h2 id="pp">🚀 Primeros pasos</h2><p><span>Deja tu cuenta lista: cada paso se tacha solo cuando está hecho.</span> (<?= $hechos ?> de <?= $total ?> listos)</p></div>
        <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="ocultar_pasos"><button type="submit" class="tk-ocultar">Ocultar</button></form></div>
    <div class="tk-barra" role="progressbar" aria-valuemin="0" aria-valuemax="<?= $total ?>" aria-valuenow="<?= $hechos ?>"><i style="width: <?= (int) round($hechos * 100 / max(1, $total)) ?>%"></i></div>
    <ol class="pasos-lista">
        <?php foreach ($pasos as $n => $p): ?>
        <li class="<?= $p['hecho'] ? 'hecho' : '' ?>">
            <span class="tk-check" aria-hidden="true"><?= $p['hecho'] ? '✓' : $n + 1 ?></span>
            <span class="tk-paso-texto"><b><?= e($p['titulo']) ?></b> <small class="suave">· <?= e($p['texto']) ?></small></span>
            <?php if (!$p['hecho']): ?><a class="tk-boton chico" href="<?= e(url($p['ruta'])) ?>">Hacerlo</a><?php endif; ?>
        </li>
        <?php endforeach; ?>
    </ol>
</section>
<?php endif; ?>

<section class="tk-cifras cifras-inicio" aria-label="Hoy">
    <?php foreach ($cifrasKit as [$ruta, $param, $valor, $texto, $tono, $detalle]): ?>
    <a class="tk-cifra <?= e($tono) ?>" href="<?= e(url($ruta, $param)) ?>"><div class="tk-cifra-titulo"><?= e($texto) ?></div><div class="tk-cifra-valor"><?= is_int($valor) ? (int) $valor : e($valor) ?></div><div class="tk-cifra-detalle"><?= e($detalle) ?></div></a>
    <?php endforeach; ?>
</section>

<div class="tk-columnas">
    <section class="tk-tarjeta">
        <div class="tk-tarjeta-cabeza"><div><h2>💬 Últimas conversaciones</h2><p>Los chats que se movieron hace poco</p></div><a class="tk-boton chico" href="<?= e(url('bandeja')) ?>">Ir a la bandeja</a></div>
        <?php if (!$recientes): ?>
        <div class="tk-vacio"><b>Todavía no tienes conversaciones.</b>Cuando un cliente te escriba, aparece aquí. <br><br><a class="tk-boton" href="<?= e(url('nuevo_chat')) ?>">＋ Escribir a un cliente</a></div>
        <?php else: ?>
        <div class="tk-tabla-caja"><table class="tk-tabla tarjetas"><thead><tr><th>Cliente</th><th>Último mensaje</th><th class="num">Sin leer</th></tr></thead><tbody>
            <?php foreach ($recientes as $c): ?>
            <tr><td data-titulo="Cliente"><a href="<?= e(url('chat', ['c' => $c['id']])) ?>"><?= u($c['nombre'] ?: $c['telefono']) ?></a><small><?= e(hora_corta($c['ultimo_en'] ?? null)) ?></small></td>
                <td data-titulo="Último mensaje"><?= ($c['ultima_direccion'] ?? '') === 'sale' ? '↪ ' : '' ?><?= u(mb_strimwidth((string) ($c['ultimo_texto'] ?? ''), 0, 70, '…')) ?></td>
                <td data-titulo="Sin leer" class="num"><?= (int) $c['sin_leer'] > 0 ? '<span class="tk-chip aviso">' . (int) $c['sin_leer'] . '</span>' : '—' ?></td></tr>
            <?php endforeach; ?>
        </tbody></table></div>
        <?php endif; ?>
    </section>

    <?php if ($otros): ?>
    <section class="tk-tarjeta info">
        <div class="tk-tarjeta-cabeza"><div><h2>📈 Ventas y llamadas</h2><p>Pedidos por cobrar, el embudo y las llamadas de hoy</p></div></div>
        <div class="tk-tabla-caja"><table class="tk-tabla tarjetas"><thead><tr><th>Qué</th><th class="num">Hoy</th><th>Acción</th></tr></thead><tbody>
            <?php foreach ($otros as [$ruta, $ico, $texto, $valor]): ?>
            <tr><td data-titulo="Qué"><?= $ico ?> <?= e($texto) ?></td><td data-titulo="Hoy" class="num tk-dinero"><?= e($valor) ?></td><td data-titulo="Acción"><a class="tk-boton secundario chico" href="<?= e(url($ruta)) ?>">Ver</a></td></tr>
            <?php endforeach; ?>
        </tbody></table></div>
    </section>
    <?php endif; ?>

    <?php if ($resumen !== null): ?>
    <section class="tk-tarjeta">
        <div class="tk-tarjeta-cabeza"><div><h2>☀️ Resumen de hoy</h2><p>Lo que la IA vio en tus chats</p></div><a class="tk-boton chico" href="<?= e(url('asistente')) ?>">Pregúntale a la IA</a></div>
        <p><?= nl2br(e($resumen['texto'])) ?></p>
    </section>
    <?php elseif (modulo('ia')): ?>
    <section class="tk-tarjeta">
        <div class="tk-tarjeta-cabeza"><div><h2>✨ Pregúntale a la IA</h2><p>"¿Quiénes están listos para comprar?", "¿Cómo nos fue esta semana?"</p></div><a class="tk-boton chico" href="<?= e(url('asistente')) ?>">Preguntar</a></div>
    </section>
    <?php endif; ?>
</div>

<section aria-label="Accesos rápidos">
    <div class="tk-tarjeta-cabeza"><div><h2>⚡ Accesos rápidos</h2><p>Lo que más se usa, a un toque</p></div></div>
    <div class="tk-accesos accesos">
        <a class="tk-boton grande acceso" href="<?= e(url('bandeja')) ?>"><span aria-hidden="true">💬</span>Bandeja</a>
        <a class="tk-boton grande secundario acceso" href="<?= e(url('contactos')) ?>"><span aria-hidden="true">👥</span>Contactos</a>
        <?php if (modulo('catalogo')): ?><a class="tk-boton grande secundario acceso" href="<?= e(url('pedidos')) ?>"><span aria-hidden="true">🛒</span>Pedidos</a><?php endif; ?>
        <?php if (es_admin()): ?><a class="tk-boton grande secundario acceso" href="<?= e(url('campanias')) ?>"><span aria-hidden="true">📣</span>Campañas</a><?php endif; ?>
        <?php if (es_admin()): ?><a class="tk-boton grande secundario acceso" href="<?= e(url('reportes')) ?>"><span aria-hidden="true">📊</span>Reportes</a><?php endif; ?>
        <a class="tk-boton grande secundario acceso" href="<?= e(url('gasto')) ?>"><span aria-hidden="true">💲</span>Gasto en Meta</a>
    </div>
</section>
