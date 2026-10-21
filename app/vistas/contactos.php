<?php
/** @var array $linea @var string $tipo @var string $buscar @var list<array> $contactos @var array $cuenta */
use TuWhatsApp\Contactos;
use TuWhatsApp\Telefono;
?>
<div class="cabeza"><h1>Contactos</h1></div>
<p class="suave"><b>Cliente</b>: puede recibir respuestas automáticas. <b>Personal</b> (familia, amigos): nunca se le contesta solo. <b>Desconocido</b>: alguien nuevo que todavía no clasificas.</p>
<nav class="pestanas" aria-label="Tipo">
    <a href="<?= e(url('contactos', ['b' => $buscar ?: null])) ?>" class="<?= $tipo === '' ? 'activo' : '' ?>">Todos</a>
    <a href="<?= e(url('contactos', ['t' => 'cliente', 'b' => $buscar ?: null])) ?>" class="<?= $tipo === 'cliente' ? 'activo' : '' ?>">Clientes (<?= (int) $cuenta['cliente'] ?>)</a>
    <a href="<?= e(url('contactos', ['t' => 'desconocido', 'b' => $buscar ?: null])) ?>" class="<?= $tipo === 'desconocido' ? 'activo' : '' ?>">Desconocidos (<?= (int) $cuenta['desconocido'] ?>)</a>
    <a href="<?= e(url('contactos', ['t' => 'personal', 'b' => $buscar ?: null])) ?>" class="<?= $tipo === 'personal' ? 'activo' : '' ?>">Personales (<?= (int) $cuenta['personal'] ?>)</a>
</nav>
<form class="buscar" method="get">
    <input type="hidden" name="r" value="contactos"><input type="hidden" name="t" value="<?= e($tipo) ?>">
    <input type="search" name="b" value="<?= e($buscar) ?>" placeholder="Buscar nombre o número" aria-label="Buscar">
</form>

<details class="tarjeta plegable">
    <summary>Agregar un contacto</summary>
    <form method="post" class="formulario">
        <?= csrf_campo() ?><input type="hidden" name="accion" value="agregar">
        <label>Número<input name="telefono" inputmode="tel" required placeholder="0991234567"></label>
        <label>Nombre<input name="nombre" maxlength="100"></label>
        <label>Es<select name="tipo"><option value="cliente">Cliente</option><option value="personal">Personal</option><option value="desconocido">Desconocido</option></select></label>
        <button class="boton">Guardar</button>
    </form>
</details>
<details class="tarjeta plegable">
    <summary>Pegar una lista de números</summary>
    <form method="post" class="formulario">
        <?= csrf_campo() ?><input type="hidden" name="accion" value="importar">
        <label>Un contacto por línea: número, nombre
            <textarea name="lista" rows="6" required placeholder="0991234567, Tía Rosa&#10;0987654321, Juan&#10;0998877665"></textarea>
        </label>
        <label>Todos son<select name="tipo"><option value="personal">Personales (familia y amigos)</option><option value="cliente">Clientes</option></select></label>
        <button class="boton">Guardar la lista</button>
    </form>
</details>

<?php if (!$contactos): ?>
<div class="vacio"><p><b>No hay contactos<?= $tipo !== '' || $buscar !== '' ? ' con ese filtro' : '' ?>.</b></p></div>
<?php else: ?>
<ul class="lista-contactos">
    <?php foreach ($contactos as $c): ?>
    <li>
        <a href="<?= e(url('chat', ['c' => $c['id']])) ?>" class="lc-nombre">
            <b><?= e(Contactos::nombre($c)) ?></b>
            <span class="suave"><?= e(Telefono::bonito($c['telefono'])) ?></span>
        </a>
        <form method="post" class="lc-tipo">
            <?= csrf_campo() ?><input type="hidden" name="accion" value="tipo"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
            <select name="tipo" aria-label="Tipo de <?= e(Contactos::nombre($c)) ?>" onchange="this.form.submit()" class="sel-<?= e($c['tipo']) ?>">
                <?php foreach (['cliente' => 'Cliente', 'desconocido' => 'Desconocido', 'personal' => 'Personal'] as $t => $n): ?>
                <option value="<?= $t ?>" <?= $c['tipo'] === $t ? 'selected' : '' ?>><?= $n ?></option>
                <?php endforeach; ?>
            </select>
            <noscript><button class="enlace">Cambiar</button></noscript>
        </form>
    </li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>
