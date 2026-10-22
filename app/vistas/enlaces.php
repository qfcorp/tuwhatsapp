<?php
/** @var array $linea @var list<array> $enlaces */
use TuWhatsApp\Enlaces;
use TuWhatsApp\Telefono;
$directo = $linea['telefono'] !== '' ? Telefono::enlace((string) $linea['telefono']) : '';
?>
<div class="cabeza"><h1>Enlaces y QR</h1></div>
<p class="suave pequeno">Crea un enlace para cada lugar donde te promocionas (Instagram, Facebook, volantes, tu página web). Al tocarlo se abre WhatsApp con tu número y un mensaje listo,
y aquí ves <b>cuántas veces lo tocaron</b>. Cada enlace tiene su <b>código QR</b> para imprimir.</p>

<form method="post" class="tarjeta formulario">
    <?= csrf_campo() ?><input type="hidden" name="accion" value="crear">
    <h2>Enlace nuevo</h2>
    <label>Nombre (solo lo ves tú)<input name="nombre" required maxlength="60" placeholder="Instagram"></label>
    <label>Mensaje que se escribe solo (opcional)<textarea name="texto" rows="2" maxlength="500" placeholder="Hola, vi su anuncio en Instagram y quiero información"></textarea></label>
    <button class="boton">Crear enlace</button>
</form>

<?php if (!$enlaces): ?>
<div class="vacio"><p><b>Todavía no tienes enlaces.</b></p><?php if ($directo): ?><p class="suave">Tu enlace directo (sin contar clics): <code><?= e($directo) ?></code></p><?php endif; ?></div>
<?php endif; ?>
<?php foreach ($enlaces as $x): $u = Enlaces::url((string) $x['codigo']); ?>
<section class="tarjeta enlace-tarjeta">
    <div class="cabeza"><h2><?= e($x['nombre']) ?></h2><span class="chip chip-cliente"><?= (int) $x['clics'] ?> <?= (int) $x['clics'] === 1 ? 'clic' : 'clics' ?></span></div>
    <?php if ($x['texto'] !== ''): ?><p class="suave pequeno">Mensaje: <?= e($x['texto']) ?></p><?php endif; ?>
    <p><code class="copiable"><?= e($u) ?></code></p>
    <div class="qr" data-qr="<?= e($u) ?>" data-nombre="qr-<?= e($x['codigo']) ?>" aria-label="Código QR de <?= e($x['nombre']) ?>"></div>
    <div class="fila-botones">
        <button type="button" class="boton boton-claro copiar" data-copiar="<?= e($u) ?>">Copiar enlace</button>
        <a class="boton boton-claro bajar-qr" href="#" hidden>Descargar QR</a>
    </div>
    <details class="plegable">
        <summary>Botón de WhatsApp para tu página web</summary>
        <p class="suave pequeno">Copia esta línea y pégala en tu página antes de <code>&lt;/body&gt;</code> (o pásasela a quien hace tu página). Aparece un botón flotante abajo a la derecha.</p>
        <textarea readonly rows="3" class="codigo"><?= e(Enlaces::codigoBoton($u)) ?></textarea>
    </details>
    <form method="post" class="derecha"><?= csrf_campo() ?><input type="hidden" name="accion" value="borrar"><input type="hidden" name="id" value="<?= (int) $x['id'] ?>">
        <button class="enlace enlace-rojo">Borrar enlace</button></form>
</section>
<?php endforeach; ?>
<script src="/assets/qrcode.js?v=1"></script>
