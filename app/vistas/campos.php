<?php
/** @var array $linea @var list<array> $campos */
use TuWhatsApp\Campos;
?>
<div class="cabeza"><h1>Campos personalizados</h1></div>
<p class="suave">Datos propios de cada cliente que quieres guardar: ciudad, cédula, fecha de cumpleaños, talla, placa del carro…
Se llenan en cada chat (sección "Datos y notas") o los pregunta un chatbot. Salen en la descarga a Excel.</p>
<?php if ($campos): ?>
<ul class="lista-simple">
    <?php foreach ($campos as $c): ?>
    <li><span><b><?= u($c['nombre']) ?></b><br><span class="suave pequeno"><?= e(Campos::TIPOS[$c['tipo']]) ?><?= $c['opciones'] !== '' ? ': ' . u($c['opciones']) : '' ?></span></span>
        <form method="post" class="en-linea" onsubmit="return confirm('¿Borrar este campo? Los datos que ya tiene cada cliente no se borran, pero dejan de verse.')"><?= csrf_campo() ?>
            <input type="hidden" name="accion" value="borrar"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><button class="enlace enlace-rojo pequeno">Borrar</button></form></li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>
<form method="post" class="tarjeta formulario">
    <?= csrf_campo() ?><input type="hidden" name="accion" value="crear">
    <h2>Campo nuevo</h2>
    <div class="fila-doble">
        <label>Nombre<input name="nombre" maxlength="60" required placeholder="Ciudad"></label>
        <label>Tipo<select name="tipo"><?php foreach (Campos::TIPOS as $k => $n): ?><option value="<?= e($k) ?>"><?= e($n) ?></option><?php endforeach; ?></select></label>
    </div>
    <label>Opciones (solo para "Lista", separadas por coma)<input name="opciones" maxlength="500" placeholder="Quito, Guayaquil, Cuenca"></label>
    <button class="boton">Agregar campo</button>
</form>
