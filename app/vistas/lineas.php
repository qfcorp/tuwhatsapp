<?php /** @var list<array> $lineas */ use TuWhatsApp\Telefono; ?>
<div class="cabeza"><h1>Números conectados</h1></div>
<?php if (!$lineas): ?>
<div class="vacio"><p><b>Todavía no conectas ningún número.</b></p></div>
<?php else: ?>
<ul class="lista-simple">
    <?php foreach ($lineas as $l): ?>
    <li>
        <span><b><?= e($l['nombre']) ?></b><br><span class="suave"><?= $l['telefono'] !== '' ? e(Telefono::bonito($l['telefono'])) : 'sin número escrito' ?> · automático <?= $l['auto_activo'] ? 'encendido' : 'apagado' ?> · tope <?= e(dinero($l['tope_mensual'])) ?></span></span>
        <a class="boton boton-claro" href="<?= e(url('linea', ['id' => $l['id']])) ?>">Editar</a>
    </li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>
<a class="boton boton-grande" href="<?= e(url('linea')) ?>">Conectar otro número</a>
<p class="suave pequeno">Cada número tiene sus propias reglas, contactos, pendientes y tope. Por ejemplo: Tukán ventas y QF Producciones.</p>
