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
<section class="tarjeta importar" id="importar">
    <h2>Traer contactos del celular</h2>
    <p class="suave">Así no tienes que escribir uno por uno. Lo normal es traer a la familia y amigos como <b>Personales</b>, para que el sistema nunca les conteste solo.</p>
    <form method="post" enctype="multipart/form-data" class="formulario" id="form-importar">
        <?= csrf_campo() ?><input type="hidden" name="accion" value="importar">
        <input type="hidden" name="contenido" id="imp-contenido"><input type="hidden" name="nombre_archivo" id="imp-nombre">
        <textarea name="lista" id="imp-lista" hidden></textarea>
        <label>Guardarlos como
            <select name="tipo" id="imp-tipo">
                <option value="personal">Personales (familia y amigos)</option>
                <option value="cliente">Clientes</option>
                <option value="desconocido">Desconocidos</option>
            </select>
        </label>
        <label class="check"><input type="checkbox" name="solo_nuevos" value="1" checked> No cambiar los que ya están en el programa (recomendado)</label>

        <button type="button" class="boton boton-grande boton-gratis" id="btn-agenda" hidden>Elegir de la agenda del celular</button>
        <p class="suave pequeno" id="txt-agenda" hidden>Se abre tu agenda: marca los contactos que quieras (o "Seleccionar todo") y toca Listo.</p>

        <label class="subir">
            <span class="boton boton-grande">Subir archivo de contactos (.vcf o .csv)</span>
            <input type="file" name="archivo" id="imp-archivo" accept=".vcf,.csv,.txt,text/vcard,text/x-vcard,text/csv,text/plain">
        </label>
        <noscript><button class="boton">Guardar</button></noscript>
        <p class="suave pequeno" id="imp-estado" role="status"></p>
    </form>

    <details class="plegable ayuda-exportar">
        <summary>¿Cómo saco el archivo de contactos de mi celular?</summary>
        <h3>Android (Samsung, Xiaomi, Motorola…)</h3>
        <ol>
            <li>Abre la app <b>Contactos</b>.</li>
            <li>Toca los <b>tres puntos</b> o el <b>menú ☰</b> → <b>Gestionar contactos</b> (o <b>Ajustes</b>).</li>
            <li>Toca <b>Importar o exportar contactos</b> → <b>Exportar</b>.</li>
            <li>Elige guardar en <b>Almacenamiento interno</b> → <b>Exportar</b>. Se crea un archivo terminado en <b>.vcf</b> en la carpeta Descargas.</li>
            <li>Vuelve aquí, toca <b>Subir archivo de contactos</b> y elige ese archivo .vcf.</li>
        </ol>
        <p class="suave pequeno">En Android con Chrome también puedes usar el botón verde <b>Elegir de la agenda del celular</b>, sin archivo.</p>
        <h3>iPhone</h3>
        <ol>
            <li>Abre la app <b>Contactos</b>.</li>
            <li>Arriba a la izquierda toca <b>Listas</b>.</li>
            <li>Mantén presionado <b>Todos los contactos</b> → <b>Exportar</b> → <b>Guardar en Archivos</b>.</li>
            <li>Vuelve aquí, toca <b>Subir archivo de contactos</b> → <b>Explorar</b> → elige el archivo .vcf.</li>
        </ol>
        <h3>Si tus contactos están en Google (desde la computadora)</h3>
        <ol>
            <li>Entra a <b>contacts.google.com</b> con tu cuenta de Gmail.</li>
            <li>En el menú de la izquierda toca <b>Exportar</b> (o los tres puntos → Exportar).</li>
            <li>Elige <b>Contactos</b> (todos) y el formato <b>vCard</b> o <b>CSV de Google</b> → <b>Exportar</b>.</li>
            <li>Sube aquí el archivo que se descargó.</li>
        </ol>
        <p class="suave pequeno">Los teléfonos fijos y números incompletos se saltan solos (no tienen WhatsApp). Si un contacto tiene varios celulares, se guardan todos.</p>
    </details>
</section>

<details class="tarjeta plegable">
    <summary>Pegar una lista de números</summary>
    <form method="post" class="formulario">
        <?= csrf_campo() ?><input type="hidden" name="accion" value="importar">
        <label>Un contacto por línea: número, nombre
            <textarea name="lista" rows="6" required placeholder="0991234567, Tía Rosa&#10;0987654321, Juan&#10;0998877665"></textarea>
        </label>
        <label>Todos son<select name="tipo"><option value="personal">Personales (familia y amigos)</option><option value="cliente">Clientes</option></select></label>
        <label class="check"><input type="checkbox" name="solo_nuevos" value="1" checked> No cambiar los que ya están</label>
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
