<?php
/** @var string $contenido @var string $titulo @var ?array $u @var ?array $linea */
$rutaActual = (string) ($_GET['r'] ?? 'bandeja');
$menu = [
    'bandeja'    => ['Bandeja',    'M4 5h16v11H8l-4 4z'],
    'pendientes' => ['Pendientes', 'M9 11l3 3 8-8M20 12v7a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1h11'],
    'contactos'  => ['Contactos',  'M16 19v-1a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v1M10 10a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM20 19v-1a4 4 0 0 0-3-3.9M15 4.1a3 3 0 0 1 0 5.8'],
    'reglas'     => ['Automático', 'M12 3v3M12 18v3M4.2 7.5l2.6 1.5M17.2 15l2.6 1.5M4.2 16.5 6.8 15M17.2 9l2.6-1.5M12 9a3 3 0 1 0 0 6 3 3 0 0 0 0-6z'],
    'gasto'      => ['Gasto',      'M12 3v18M17 7.5c0-1.9-2.2-3-5-3s-5 1.1-5 3 2.2 2.6 5 3.3 5 1.5 5 3.5-2.2 3.2-5 3.2-5-1.3-5-3.2'],
];
$activo = $rutaActual === 'chat' || $rutaActual === 'plantilla' ? 'bandeja' : $rutaActual;
$avisos = tomar_avisos();
$lineas = $u ? \TuWhatsApp\Lineas::todas(db()) : [];
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
<title><?= e($titulo) ?> · WhatsApp Tukán</title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%2314211F'/%3E%3Cpath d='M7 25l2-5a9 9 0 1 1 3.5 3.3z' fill='%23F28C28'/%3E%3C/svg%3E">
<link rel="stylesheet" href="/assets/app.css?v=3">
</head>
<body class="<?= $u ? 'con-sesion' : 'sin-sesion' ?> ruta-<?= e($rutaActual) ?>">
<?php if ($u): ?>
<header class="barra">
    <a class="marca" href="<?= e(url('bandeja')) ?>" aria-label="Inicio">
        <svg viewBox="0 0 32 32" width="28" height="28" aria-hidden="true"><rect width="32" height="32" rx="8" fill="#F28C28"/><path d="M8 24.5l1.7-4.6a8.6 8.6 0 1 1 3.3 3.2z" fill="#14211F"/></svg>
        <span>WhatsApp <b>Tukán</b></span>
    </a>
    <?php if (count($lineas) > 1): ?>
    <form method="post" action="<?= e(url('cambiar_linea')) ?>" class="selector-linea">
        <?= csrf_campo() ?>
        <select name="linea_id" aria-label="Número" onchange="this.form.submit()">
            <?php foreach ($lineas as $l): ?>
            <option value="<?= (int) $l['id'] ?>" <?= $linea && (int) $l['id'] === (int) $linea['id'] ? 'selected' : '' ?>><?= e($l['nombre']) ?></option>
            <?php endforeach; ?>
        </select>
    </form>
    <?php elseif ($linea): ?>
    <span class="nombre-linea"><?= e($linea['nombre']) ?></span>
    <?php endif; ?>
    <nav class="acciones-barra">
        <a href="<?= e(url('lineas')) ?>" title="Números conectados" aria-label="Números conectados"><svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path d="M7 3h10a1 1 0 0 1 1 1v16a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1zM11 18h2" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></a>
        <a href="<?= e(url('salir')) ?>" title="Salir" aria-label="Salir"><svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path d="M15 4h4a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1h-4M10 16l-4-4 4-4M6 12h10" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></a>
    </nav>
</header>
<?php if ($gastoBarra): ?>
<a class="tope <?= $gastoBarra['pct'] >= 100 ? 'lleno' : ($gastoBarra['pct'] >= $gastoBarra['aviso'] ? 'alerta' : '') ?>" href="<?= e(url('gasto')) ?>">
    <span class="tope-texto"><?= $gastoBarra['pct'] >= 100 ? 'Tope alcanzado: solo se envía gratis desde el celular' : 'Gasto del mes en Meta' ?></span>
    <span class="tope-cifra"><?= e(dinero($gastoBarra['g'])) ?> de <?= e(dinero($gastoBarra['t'])) ?></span>
    <span class="tope-barra"><span style="width: <?= (int) $gastoBarra['pct'] ?>%"></span></span>
</a>
<?php endif; ?>
<?php endif; ?>

<main class="principal">
    <?php foreach ($avisos as [$tipo, $texto]): ?>
    <div class="aviso aviso-<?= e($tipo) ?>" role="<?= $tipo === 'error' ? 'alert' : 'status' ?>"><?= e($texto) ?></div>
    <?php endforeach; ?>
    <?= $contenido ?>
</main>

<?php if ($u && $linea): ?>
<nav class="menu" aria-label="Menú">
    <?php foreach ($menu as $r => [$nombre, $icono]): ?>
    <a href="<?= e(url($r)) ?>" class="<?= $activo === $r ? 'activo' : '' ?>" <?= $activo === $r ? 'aria-current="page"' : '' ?>>
        <svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><path d="<?= $icono ?>" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
        <span><?= e($nombre) ?></span>
        <?php if ($r === 'pendientes' && $cuentaPend > 0): ?><b class="globo"><?= $cuentaPend ?></b><?php endif; ?>
        <?php if ($r === 'bandeja'): ?><b class="globo" id="globo-bandeja" hidden></b><?php endif; ?>
    </a>
    <?php endforeach; ?>
</nav>
<?php endif; ?>
<script src="/assets/app.js?v=2" defer></script>
</body>
</html>
