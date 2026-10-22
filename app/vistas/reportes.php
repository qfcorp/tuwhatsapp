<?php
/** @var array $linea @var array $r */
use TuWhatsApp\Reportes;
$t = $r['totales'];
$pl = fn (int $n, string $uno, string $varios) => $n . ' ' . ($n === 1 ? $uno : $varios);
$max = max(1, ...array_values(array_map(fn ($d) => max($d["entran"], $d["salen"]), $r["por_dia"])));
$pct = $r['respuesta']['total'] > 0 ? (int) round($r['respuesta']['contestados'] * 100 / $r['respuesta']['total']) : null;
?>
<div class="cabeza"><h1>Reportes</h1>
    <nav class="pestanas" aria-label="Período">
        <?php foreach (Reportes::PERIODOS as $d => $n): ?><a href="<?= e(url('reportes', ['d' => $d])) ?>" class="<?= $r['dias'] === $d ? 'activo' : '' ?>"><?= e($n) ?></a><?php endforeach; ?>
    </nav>
</div>
<p class="suave pequeno"><?= u($linea['nombre']) ?> · del <?= e($r['desde']->format('d/m/Y')) ?> al <?= e($r['hasta']->format('d/m/Y')) ?></p>

<ul class="cifras">
    <li><b><?= (int) $t['conversaciones'] ?></b><span>personas escribieron</span></li>
    <li><b><?= (int) $t['nuevos'] ?></b><span>contactos nuevos</span></li>
    <li><b><?= (int) $t['entran'] ?></b><span>mensajes recibidos</span></li>
    <li><b><?= (int) $t['salen'] ?></b><span>mensajes enviados</span></li>
    <li><b><?= e(Reportes::duracion($r['respuesta']['mediana'])) ?></b><span>tarda el equipo en responder (normal)</span></li>
    <li><b><?= $pct === null ? '—' : $pct . '%' ?></b><span>de los clientes recibió respuesta</span></li>
    <li><b><?= (int) $t['automaticos'] ?></b><span>respuestas automáticas</span></li>
    <li><b><?= e(dinero($t['gasto'], true)) ?></b><span>gastado en Meta</span></li>
</ul>

<section class="tarjeta">
    <h2>Mensajes por día</h2>
    <p class="suave pequeno"><span class="punto punto-entra"></span> recibidos · <span class="punto punto-sale"></span> enviados</p>
    <div class="barras">
        <?php foreach ($r['por_dia'] as $dia => $d): ?>
        <div class="barra-dia" title="<?= e(date('d/m', strtotime($dia))) ?>: <?= $d['entran'] ?> recibidos, <?= $d['salen'] ?> enviados">
            <span class="b-entra" style="height:<?= round($d['entran'] * 100 / $max) ?>%"></span><span class="b-sale" style="height:<?= round($d['salen'] * 100 / $max) ?>%"></span>
            <?php if ($r['dias'] <= 7): ?><small><?= e(date('d/m', strtotime($dia))) ?></small><?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</section>

<?php if (modulo('embudo')): ?>
<section class="tarjeta">
    <h2>Ventas del embudo en el período</h2>
    <p><b><?= (int) $r['ventas']['ganados'] ?></b> <?= $r['ventas']['ganados'] === 1 ? 'ganada' : 'ganadas' ?> por <b><?= e(dinero($r['ventas']['vendido'])) ?></b> · <b><?= (int) $r['ventas']['perdidos'] ?></b> <?= $r['ventas']['perdidos'] === 1 ? 'perdida' : 'perdidas' ?>
    <?php $cerr = $r['ventas']['ganados'] + $r['ventas']['perdidos']; if ($cerr > 0): ?> · cierre <?= (int) round($r['ventas']['ganados'] * 100 / $cerr) ?>%<?php endif; ?></p>
</section>
<?php endif; ?>

<h2 class="separador">Por persona del equipo</h2>
<?php if (!$r['agentes']): ?>
<p class="suave">Nadie del equipo respondió en este período.</p>
<?php else: ?>
<ul class="lista-simple">
    <?php foreach ($r['agentes'] as $a): ?>
    <li><span><b><?= u($a['nombre']) ?></b></span><span class="suave"><?= e($pl((int) $a['mensajes'], 'mensaje', 'mensajes')) ?> · <?= e($pl((int) $a['conversaciones'], 'conversación', 'conversaciones')) ?></span></li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>
<p class="suave pequeno">Para descargar todo a Excel: <?= modulo('integraciones') ? '<a href="' . e(url('integraciones')) . '">Integraciones</a>' : 'módulo Integraciones' ?>.</p>
