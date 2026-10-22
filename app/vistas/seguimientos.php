<?php
/** @var array $linea @var list<array> $programados @var list<array> $hechos */
use TuWhatsApp\Contactos;
use TuWhatsApp\Seguimientos;
$fecha = fn (?string $t) => $t ? (new DateTimeImmutable($t))->format('d/m H:i') : '';
?>
<div class="cabeza"><h1>Seguimientos</h1><span class="suave"><?= count($programados) ?> programados</span></div>
<p class="suave pequeno">Se programan desde cada chat (botón <b>Programar seguimiento</b>). Si la persona responde antes, no se le manda nada.
Si ya pasaron 24 horas desde su último mensaje, queda en <a href="<?= e(url('pendientes')) ?>">Pendientes</a> para mandarlo <b>gratis</b> desde tu celular.</p>

<?php if (!$programados): ?>
<div class="vacio"><p><b>No hay seguimientos programados.</b></p><p class="suave">Abre un chat y toca "Programar seguimiento".</p></div>
<?php else: ?>
<ul class="lista-simple">
    <?php foreach ($programados as $s): ?>
    <li>
        <span><b><a href="<?= e(url('chat', ['c' => $s['contacto_id']])) ?>"><?= e(Contactos::nombre($s)) ?></a></b> · <?= e($fecha($s['cuando'])) ?><?= $s['solo_sin_respuesta'] ? ' · solo si no responde' : '' ?><br>
            <span class="suave"><?= e(mb_strimwidth($s['texto'], 0, 100, '…')) ?></span></span>
        <form method="post"><?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int) $s['id'] ?>"><button class="enlace enlace-rojo">Cancelar</button></form>
    </li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>

<?php if ($hechos): ?>
<h2 class="separador">Últimos</h2>
<ul class="lista-simple">
    <?php foreach ($hechos as $s): ?>
    <li><span><b><?= e(Contactos::nombre($s)) ?></b> · <?= e(Seguimientos::ESTADOS[$s['estado']] ?? $s['estado']) ?> · <?= e($fecha($s['hecho_en'])) ?><br>
        <span class="suave"><?= e($s['resultado'] !== '' ? $s['resultado'] : mb_strimwidth($s['texto'], 0, 100, '…')) ?></span></span></li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>
