<?php
/** @var array $linea @var list<array> $productos @var string $buscar @var ?array $editar */
use TuWhatsApp\Catalogo;
$v = $editar ?? ['id' => null, 'codigo' => '', 'nombre' => '', 'descripcion' => '', 'precio' => '', 'stock' => null, 'activo' => true, 'foto_ruta' => null];
?>
<div class="cabeza"><h1>Catálogo</h1></div>
<p class="suave">Tus productos o servicios. Desde cada chat puedes mandarlos (con foto y precio), armar el carrito del cliente y mandarle la cotización.
Si el cliente escribe <b>catálogo</b>, le llega la lista para elegir tocando; con <b>comprar</b> confirma su carrito.</p>

<form method="post" class="tarjeta formulario" enctype="multipart/form-data" id="producto">
    <?= csrf_campo() ?><input type="hidden" name="accion" value="guardar">
    <?php if ($v['id']): ?><input type="hidden" name="id" value="<?= (int) $v['id'] ?>"><?php endif; ?>
    <h2><?= $v['id'] ? 'Cambiar producto' : 'Producto nuevo' ?></h2>
    <label>Nombre<input name="nombre" maxlength="120" required value="<?= e($v['nombre']) ?>" placeholder="Corte de cabello"></label>
    <div class="fila-doble">
        <label>Precio ($)<input name="precio" inputmode="decimal" maxlength="15" required value="<?= $v['precio'] !== '' ? e(number_format((float) $v['precio'], 2, ',', '.')) : '' ?>" placeholder="12,50"></label>
        <label>Stock (vacío = no se controla)<input name="stock" inputmode="numeric" maxlength="7" value="<?= $v['stock'] !== null ? (int) $v['stock'] : '' ?>"></label>
    </div>
    <div class="fila-doble">
        <label>Código (opcional)<input name="codigo" maxlength="40" value="<?= e($v['codigo']) ?>"></label>
        <label>Foto (JPG o PNG)<input type="file" name="foto" accept="image/jpeg,image/png"></label>
    </div>
    <label>Descripción<textarea name="descripcion" rows="3" maxlength="1000"><?= e($v['descripcion']) ?></textarea></label>
    <label class="check"><input type="checkbox" name="activo" value="1" <?= $v['activo'] ? 'checked' : '' ?>> Disponible para vender</label>
    <div class="fila-botones"><button class="boton">Guardar</button><?php if ($v['id']): ?> <a class="boton boton-claro" href="<?= e(url('catalogo')) ?>">Cancelar</a><?php endif; ?></div>
</form>

<form class="buscar" method="get"><input type="hidden" name="r" value="catalogo">
    <input type="search" name="b" value="<?= e($buscar) ?>" placeholder="Buscar producto o código" aria-label="Buscar producto"></form>

<?php if (!$productos): ?>
<div class="vacio"><p><b>Todavía no hay productos.</b></p><p class="suave">Agrégalos arriba uno por uno o súbelos todos con un archivo de Excel (abajo).</p></div>
<?php else: ?>
<ul class="lista-simple productos">
    <?php foreach ($productos as $p): ?>
    <li class="<?= $p['activo'] ? '' : 'apagado' ?>">
        <span class="prod-foto"><?php if ($p['foto_ruta']): ?><img src="<?= e(url('foto_producto', ['id' => $p['id']])) ?>" alt="" loading="lazy"><?php else: ?>📦<?php endif; ?></span>
        <span class="prod-datos"><b><?= u($p['nombre']) ?></b><?= $p['codigo'] !== '' ? ' <small class="suave">' . u($p['codigo']) . '</small>' : '' ?><br>
            <span class="suave pequeno"><?= e(Catalogo::precio((float) $p['precio'], $linea)) ?><?= $p['stock'] !== null ? ' · stock ' . (int) $p['stock'] : '' ?><?= $p['activo'] ? '' : ' · no disponible' ?></span></span>
        <span class="prod-acciones">
            <a class="pequeno" href="<?= e(url('catalogo', ['editar' => $p['id']])) ?>#producto">Cambiar</a>
            <form method="post" class="en-linea" onsubmit="return confirm('¿Borrar este producto? Los pedidos que ya lo tienen no cambian.')"><?= csrf_campo() ?>
                <input type="hidden" name="accion" value="borrar"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>"><button class="enlace enlace-rojo pequeno">Borrar</button></form>
        </span>
    </li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>

<details class="tarjeta plegable">
    <summary>Subir muchos productos con Excel</summary>
    <form method="post" class="formulario" enctype="multipart/form-data">
        <?= csrf_campo() ?><input type="hidden" name="accion" value="importar">
        <p class="suave pequeno">En Excel pon estas columnas en este orden: <b>nombre, precio, stock, código, descripción</b> (stock, código y descripción pueden ir vacíos).
        Luego <b>Archivo → Guardar como → CSV</b>. Si un código ya existe, se actualiza ese producto.</p>
        <label>Archivo CSV<input type="file" name="archivo" accept=".csv,text/csv" required></label>
        <button class="boton boton-claro">Subir productos</button>
    </form>
</details>
