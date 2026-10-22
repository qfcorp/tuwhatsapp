<?php /** @var list<array> $lineas @var array $empresa */ use TuWhatsApp\Telefono; ?>
<div class="cabeza"><h1>Números conectados</h1></div>
<?php if (!$lineas): ?>
<div class="vacio"><p><b>Todavía no conectas ningún número.</b></p></div>
<?php else: ?>
<ul class="lista-simple">
    <?php foreach ($lineas as $l): ?>
    <li>
        <span><b><?= u($l['nombre']) ?></b><br><span class="suave"><?= $l['telefono'] !== '' ? e(Telefono::bonito($l['telefono'])) : 'sin número escrito' ?> · automático <?= $l['auto_activo'] ? 'encendido' : 'apagado' ?> · tope <?= e(dinero($l['tope_mensual'])) ?></span></span>
        <a class="boton boton-claro" href="<?= e(url('linea', ['id' => $l['id']])) ?>">Editar</a>
    </li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>
<?php if (\TuWhatsApp\Empresas::puedeAgregarNumero(db(), (int) $empresa['id'])): ?>
<a class="boton boton-grande" href="<?= e(url('linea')) ?>">Conectar otro número</a>
<?php else: ?>
<p class="nota">Tu plan permite <?= (int) $empresa['max_numeros'] ?> número<?= (int) $empresa['max_numeros'] === 1 ? '' : 's' ?>. Para conectar otro, escribe a Tukán.</p>
<?php endif; ?>
<p class="suave pequeno">Cada número tiene sus propias reglas, contactos, pendientes y tope. Por ejemplo: Tukán ventas y QF Producciones.</p>
