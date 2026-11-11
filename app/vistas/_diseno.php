<?php
/** @var string $contenido @var string $titulo @var ?array $u @var ?array $linea */
$rutaActual = (string) ($_GET['r'] ?? 'inicio');
// (10 nov 2026) Identidad gráfica común de Tukán: barra de arriba naranja con el nombre del negocio, menú azul noche a la izquierda con TODAS
// las opciones agrupadas y con emoji (kit public/assets/tukan-ui.css + tukan-ui.js; docs/LINEA_GRAFICA.md). En el celular el menú se abre
// con ☰ (ya no hay barra de abajo; la pantalla «Más» sigue en /?r=mas). Mismas reglas de siempre: módulos, administrador, Tukán.
$menu = [
    'inicio'     => 'Inicio',
    'bandeja'    => 'Bandeja',
    'pendientes' => 'Pendientes',
    'pedidos'    => 'Pedidos',
    'contactos'  => 'Contactos',
    'embudo'     => 'Embudo',
];
if ($u && !modulo('embudo')) {
    unset($menu['embudo']);
}
// Sin la app del celular (coexistencia) no hay nada que mandar "desde el celular": en su lugar va Pedidos
if ($linea && empty($linea['coexistencia']) && \TuWhatsApp\Pendientes::contar(db(), (int) $linea['id']) === 0) {
    unset($menu['pendientes']);
} else {
    unset($menu['pedidos']);
}
$activo = ['chat' => 'bandeja', 'plantilla' => 'bandeja', 'pedido' => 'pedidos', 'flujo' => 'flujos', 'campania' => 'campanias', 'campania_revisar' => 'campanias',
           'linea' => 'lineas', 'cliente' => 'clientes', 'llamar' => 'llamadas'][$rutaActual] ?? $rutaActual;
$gruposMenu = [];
if ($u && $linea) {
    $conIa = modulo('ia');
    foreach (menu_mas_grupos() as $g => $items) {
        foreach ($items as [$r, $nombre, , $soloAdmin]) {
            if (($soloAdmin && !es_admin()) || isset($menu[$r]) || (in_array($r, ['ia', 'asistente'], true) && !$conIa)) continue;
            $gruposMenu[$g][$r] = trim(str_replace('🧾', '', $nombre));
        }
    }
}
if ($u && es_super()) $gruposMenu['Tukán'] = ['clientes' => 'Clientes de Tukán'];
$avisos = tomar_avisos();
$empresaVista = $u ? empresa_actual() : null;
$lineas = $empresaVista ? \TuWhatsApp\Lineas::todas(db(), (int) $empresaVista['id']) : [];
$cuentaPend = ($u && $linea) ? \TuWhatsApp\Pendientes::contar(db(), (int) $linea['id']) : 0;
$gastoBarra = null;
if ($u && $linea) {
    $g = \TuWhatsApp\Gasto::delMes(db(), (int) $linea['id']);
    $t = (float) $linea['tope_mensual'];
    $gastoBarra = ['g' => $g, 't' => $t, 'pct' => $t > 0 ? min(100, (int) floor($g * 100 / $t)) : 100, 'aviso' => (int) $linea['aviso_pct']];
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#14211F">
<meta name="robots" content="noindex, nofollow">
<title><?= e($titulo) ?> · <?= e(nombre_programa()) ?></title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%2314211F'/%3E%3Cpath d='M7 25l2-5a9 9 0 1 1 3.5 3.3z' fill='%23F28C28'/%3E%3C/svg%3E">
<link rel="manifest" href="/manifest.json">
<link rel="apple-touch-icon" href="/assets/apple-touch-icon.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="<?= e(nombre_programa()) ?>">
<link rel="stylesheet" href="/assets/app.css?v=21">
<link rel="stylesheet" href="/assets/tukan-ui.css?v=1">
<?= TK_COLOR_LINEA ?>
<script>try { var t = localStorage.getItem('tuwa_tema'); if (t) { document.documentElement.dataset.tema = t; } } catch (e) {}</script>
<?= tukan_idioma_js(require RAIZ . '/app/frases_js.php') ?>
</head>
<body class="tk <?= $u ? 'con-sesion' : 'sin-sesion' ?> ruta-<?= e($rutaActual) ?><?= en_app_android() ? ' en-app' : '' ?>"<?= $u && $linea && !empty($linea['llamadas_activo']) ? ' data-llamadas="1" data-csrf="' . e(csrf()) . '"' : '' ?>>
<?php if ($u): ?>
<header class="tk-arriba">
    <div class="tk-arriba-izq">
        <?php if ($linea || es_super()): ?><button class="tk-boton-menu" type="button" aria-label="Abrir el menú" aria-expanded="false">☰</button><?php endif; ?>
        <a class="tk-marca" href="<?= e(url('inicio')) ?>"><span class="tk-logo" aria-hidden="true"><!--notr--><?= en_app_android() ? 'Tm' : 'Wt' ?><!--/notr--></span><span><b><?= u($empresaVista['nombre'] ?? nombre_programa()) ?></b><small><!--notr--><?= e(nombre_programa()) ?> · <!--/notr--><span>Plan <?= e(\TuWhatsApp\Modulos::PLANES[\TuWhatsApp\Modulos::planDe($empresaVista ?? [])][0]) ?></span><?php if (($empresaVista['estado'] ?? '') === 'demo'): ?> <span>(demo)</span><?php endif; ?></small></span></a>
    </div>
    <div class="tk-usuario"><span class="tk-nombre">👤 <?= u(usuario()['nombre']) ?></span><span class="tk-rol"><?= es_super() ? 'Tukán' : (es_admin() ? 'Administrador' : 'Agente') ?></span><a class="tk-salir" href="<?= e(url('salir')) ?>">Salir</a></div>
</header>
<div class="tk-cuerpo">
    <?php if ($linea || es_super()): ?>
    <nav class="tk-menu" aria-label="Menú principal">
        <?php if (count($lineas) > 1): ?>
        <div class="tk-menu-titulo">Número</div>
        <form method="post" action="<?= e(url('cambiar_linea')) ?>" class="selector-linea tk-menu-linea">
            <?= csrf_campo() ?>
            <select name="linea_id" aria-label="Número" onchange="this.form.submit()">
                <?php foreach ($lineas as $l): ?>
                <option value="<?= (int) $l['id'] ?>" <?= $linea && (int) $l['id'] === (int) $linea['id'] ? 'selected' : '' ?>><?= e($l['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
        <?php endif; ?>
        <?php if ($linea): ?>
        <div class="tk-menu-titulo">Principal</div>
        <?php foreach ($menu as $r => $nombre): ?>
        <a href="<?= e(url($r)) ?>"<?= $activo === $r ? ' class="activo" aria-current="page"' : '' ?>><span class="tk-ico" aria-hidden="true"><?= icono_menu($r) ?></span> <?= e($nombre) ?><?php if ($r === 'pendientes' && $cuentaPend > 0): ?> <span class="tk-globito globo"><?= $cuentaPend ?></span><?php endif; ?><?php if ($r === 'bandeja'): ?> <span class="tk-globito globo" id="globo-bandeja" hidden></span><?php endif; ?></a>
        <?php endforeach; ?>
        <?php endif; ?>
        <?php foreach ($gruposMenu as $g => $items): ?>
        <div class="tk-menu-titulo"><?= e($g) ?></div>
        <?php foreach ($items as $r => $nombre): ?>
        <a href="<?= e(url($r)) ?>"<?= $activo === $r ? ' class="activo" aria-current="page"' : '' ?>><span class="tk-ico" aria-hidden="true"><?= icono_menu($r) ?></span> <?= e($nombre) ?></a>
        <?php endforeach; ?>
        <?php endforeach; ?>
        <div class="tk-menu-opciones">
            <button type="button" class="boton-tema tk-menu-tema" id="boton-tema" title="Modo claro u oscuro" aria-label="Cambiar entre modo claro y oscuro"><span class="tk-ico" aria-hidden="true">🌙</span> <span>Modo claro u oscuro</span></button>
            <?= tukan_idioma_selector('idioma-barra') ?>
        </div>
        <a href="<?= e(url('salir')) ?>" class="tk-menu-salir"><span class="tk-ico" aria-hidden="true">🚪</span> Salir</a>
    </nav>
    <div class="tk-fondo-menu"></div>
    <?php endif; ?>
<main class="tk-contenido principal">
<?php if (viendo_otra_empresa()): ?>
<form method="post" action="<?= e(url('volver_tukan')) ?>" class="tk-tira soporte">
    <?= csrf_campo() ?>
    <span>🛡️ Soporte: estás en la cuenta de <b><?= u($empresaVista['nombre']) ?></b><?= \TuWhatsApp\Empresas::alDia($empresaVista) ? '' : ' (no está al día)' ?></span>
    <button class="tk-boton chico oscuro boton boton-claro">Volver a Tukán</button>
</form>
<?php endif; ?>
<?php if ($gastoBarra): $topeAlerta = $gastoBarra['pct'] >= $gastoBarra['aviso']; ?>
<a class="tope <?= $gastoBarra['pct'] >= 100 ? 'lleno' : ($topeAlerta ? 'alerta' : '') ?> <?= $topeAlerta || in_array($rutaActual, ['inicio', 'gasto'], true) ? '' : 'tope-fino' ?>" href="<?= e(url('gasto')) ?>">
    <span class="tope-texto"><?= $gastoBarra['pct'] >= 100 ? 'Tope alcanzado: ya no se envían plantillas pagadas' : 'Gasto aprox. del mes en Meta' ?></span>
    <span class="tope-cifra"><?= e(dinero($gastoBarra['g'])) ?> de <?= e(dinero($gastoBarra['t'])) ?></span>
    <span class="tope-barra"><span style="width: <?= (int) $gastoBarra['pct'] ?>%"></span></span>
</a>
<?php endif; ?>
<?php else: ?>
<div class="idioma-suelto"><?= tukan_idioma_selector() ?></div>
<main class="principal">
<?php endif; ?>
    <?php foreach ($avisos as [$tipo, $texto]): ?>
    <div class="tk-aviso <?= ['ok' => 'ok', 'error' => 'mal'][$tipo] ?? 'info' ?> aviso aviso-<?= e($tipo) ?>" role="<?= $tipo === 'error' ? 'alert' : 'status' ?>"><?= e($texto) ?></div>
    <?php endforeach; ?>
    <?= $contenido ?>
</main>
<?php if ($u): ?></div><?php endif; ?>
<script src="/assets/tukan-ui.js?v=1" defer></script>
<script src="/assets/app.js?v=11" defer></script>
<?php if ($u && $linea && !empty($linea['llamadas_activo'])): ?><script src="/assets/llamadas.js?v=1" defer></script><?php endif; ?>
</body>
</html>
