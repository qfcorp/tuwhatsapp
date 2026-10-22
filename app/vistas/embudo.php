<?php
/** @var array $linea @var string $buscar @var array<string, list<array>> $tablero @var array<string, array{n:int, valor:float}> $totales */
use TuWhatsApp\Contactos;
use TuWhatsApp\Embudo;
$abiertos = $totales['nuevo']['valor'] + $totales['interesado']['valor'] + $totales['cotizado']['valor'];
?>
<div class="cabeza"><h1>Embudo de ventas</h1><span class="suave">En juego: <b><?= e(dinero($abiertos)) ?></b> · Ganado: <b><?= e(dinero($totales['ganado']['valor'])) ?></b></span></div>
<p class="suave pequeno">Cada persona que te escribe entra sola a <b>Nuevo</b>. Muévela de etapa cuando avance y anota cuánto vale la venta (también desde el chat).</p>
<form class="buscar" method="get">
    <input type="hidden" name="r" value="embudo">
    <input type="search" name="b" value="<?= e($buscar) ?>" placeholder="Buscar nombre, número o etiqueta" aria-label="Buscar en el embudo">
</form>
<div class="tablero" role="list">
    <?php foreach (Embudo::ETAPAS as $k => $nombre): ?>
    <section class="columna etapa-<?= e($k) ?>" role="listitem" aria-label="<?= e($nombre) ?>">
        <h2><?= e($nombre) ?> <small class="suave"><?= (int) $totales[$k]['n'] ?><?= $totales[$k]['valor'] > 0 ? ' · ' . e(dinero($totales[$k]['valor'])) : '' ?></small></h2>
        <?php if (!$tablero[$k]): ?><p class="suave pequeno">Nadie aquí.</p><?php endif; ?>
        <?php foreach ($tablero[$k] as $c): ?>
        <article class="ficha">
            <a href="<?= e(url('chat', ['c' => $c['id']])) ?>" class="ficha-nombre"><?= u(Contactos::nombre($c)) ?></a>
            <?php if ((float) $c['valor'] > 0): ?><b class="ficha-valor"><?= e(dinero($c['valor'])) ?></b><?php endif; ?>
            <p class="suave pequeno"><?= u(mb_strimwidth((string) $c['ultimo_texto'], 0, 70, '…')) ?></p>
            <form method="post" class="ficha-mover" action="<?= e(url('embudo', array_filter(['b' => $buscar]))) ?>">
                <?= csrf_campo() ?><input type="hidden" name="c" value="<?= (int) $c['id'] ?>">
                <select name="etapa" aria-label="Mover a" class="auto-enviar">
                    <?php foreach (Embudo::ETAPAS as $k2 => $n2): ?><option value="<?= e($k2) ?>" <?= $k2 === $k ? 'selected' : '' ?>><?= e($n2) ?></option><?php endforeach; ?>
                    <option value="">Sacar del embudo</option>
                </select>
                <button class="boton boton-claro sin-js">Mover</button>
            </form>
            <time class="suave pequeno"><?= e(hora_corta($c['ultimo_en'] ?? $c['creado'])) ?></time>
        </article>
        <?php endforeach; ?>
    </section>
    <?php endforeach; ?>
</div>
