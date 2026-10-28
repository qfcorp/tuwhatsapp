<?php
/** @var array $linea @var list<array> $pedidos @var string $estado @var string $buscar @var array $cuentas */
use TuWhatsApp\Pedidos;
$origenes = ['chat' => 'Chat', 'bot' => 'Agente IA', 'shopify' => 'Shopify', 'woocommerce' => 'WooCommerce'];
?>
<div class="cabeza"><h1>Pedidos</h1></div>
<ul class="cifras">
    <?php foreach (['carrito', 'pendiente', 'pagado', 'enviado'] as $k): $c = $cuentas[$k] ?? ['n' => 0, 'total' => 0]; ?>
    <li><b><?= (int) $c['n'] ?></b><span><?= e(Pedidos::ESTADOS[$k]) ?> · <?= e(dinero($c['total'])) ?></span></li>
    <?php endforeach; ?>
</ul>
<form class="buscar" method="get"><input type="hidden" name="r" value="pedidos"><input type="hidden" name="e" value="<?= e($estado) ?>">
    <input type="search" name="b" value="<?= e($buscar) ?>" placeholder="Buscar cliente, número o #pedido" aria-label="Buscar pedido"></form>
<nav class="pestanas" aria-label="Estado">
    <a href="<?= e(url('pedidos')) ?>" class="<?= $estado === '' ? 'activo' : '' ?>">Todos</a>
    <?php foreach (Pedidos::ESTADOS as $k => $n): ?><a href="<?= e(url('pedidos', ['e' => $k])) ?>" class="<?= $estado === $k ? 'activo' : '' ?>"><?= e($n) ?></a><?php endforeach; ?>
</nav>
<?php if (!$pedidos): ?>
<div class="vacio"><p><b>No hay pedidos aquí.</b></p><p class="suave">Los carritos se arman desde cada chat (sección "Carrito y cotización"), cuando el cliente elige del catálogo, o los arma el agente con IA.</p></div>
<?php else: ?>
<ul class="lista-simple pedidos">
    <?php foreach ($pedidos as $p): ?>
    <li><a href="<?= e(url('pedido', ['id' => $p['id']])) ?>">
        <span><b>#<?= (int) $p['numero'] ?> · <?= u(Pedidos::nombreCliente($p)) ?></b><br>
        <span class="suave pequeno"><?= e(hora_corta($p['actualizado'])) ?> · <?= (int) $p['lineas'] ?> <?= (int) $p['lineas'] === 1 ? 'producto' : 'productos' ?> · <?= e($origenes[$p['origen']] ?? $p['origen']) ?></span></span>
        <span class="derecha"><b><?= e(dinero($p['total'])) ?></b><br><small class="chip estado-<?= e($p['estado']) ?>"><?= e(Pedidos::ESTADOS[$p['estado']]) ?></small></span>
    </a></li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>
