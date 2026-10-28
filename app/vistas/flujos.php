<?php
/** @var array $linea @var list<array> $flujos */
use TuWhatsApp\Flujos;
?>
<div class="cabeza"><h1>Chatbots</h1></div>
<p class="suave">Conversaciones por pasos con botones y preguntas, sin programar. Ejemplo: "¿Qué necesitas? [Precios] [Horarios] [Hablar con alguien]".
Arrancan cuando el cliente escribe una palabra clave o en su primer mensaje. Tienen prioridad sobre el agente con IA y el menú automático,
y nunca contestan a contactos personales ni a quien ya está atendiendo una persona.</p>

<?php if (!$flujos): ?>
<div class="vacio"><p><b>Todavía no tienes chatbots.</b></p><p class="suave">Crea el primero: viene con un ejemplo de 3 pasos que puedes cambiar.</p></div>
<?php else: ?>
<ul class="lista-simple">
    <?php foreach ($flujos as $f): $n = count(Flujos::pasos($f)); ?>
    <li><a href="<?= e(url('flujo', ['id' => $f['id']])) ?>">
        <span><b><?= u($f['nombre']) ?></b><br><span class="suave pequeno"><?= $f['disparador'] === 'nuevo' ? 'Primer mensaje del cliente' : 'Palabras: ' . e($f['palabras'] ?: '—') ?> · <?= $n ?> <?= $n === 1 ? 'paso' : 'pasos' ?> · usado <?= (int) $f['usos'] ?> veces</span></span>
        <span class="derecha"><small class="chip <?= $f['activo'] ? 'chip-cliente' : '' ?>"><?= $f['activo'] ? 'Encendido' : 'Apagado' ?></small></span>
    </a></li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>

<form method="post" class="tarjeta formulario">
    <?= csrf_campo() ?><input type="hidden" name="accion" value="crear">
    <h2>Chatbot nuevo</h2>
    <label>Nombre<input name="nombre" maxlength="80" required placeholder="Bienvenida"></label>
    <label>Se activa con<select name="disparador">
        <option value="palabras">Palabras clave</option>
        <option value="nuevo">El primer mensaje del cliente</option>
    </select></label>
    <label>Palabras clave (separadas por coma)<input name="palabras" maxlength="500" placeholder="hola, info, precios"></label>
    <button class="boton">Crear y editar</button>
</form>
