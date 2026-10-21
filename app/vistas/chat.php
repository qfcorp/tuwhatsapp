<?php
/** @var array $linea @var array $c @var list<array> $mensajes @var string $borrador @var bool $ventana @var float $precio */
use TuWhatsApp\Contactos;
use TuWhatsApp\Telefono;
$origenes = ['cliente' => '', 'panel' => 'Por el sistema', 'auto' => 'Automático', 'celular' => 'Desde el celular', 'pendiente' => 'Pendiente'];
$estados = ['enviando' => 'Enviando…', 'enviado' => '✓', 'entregado' => '✓✓', 'leido' => '✓✓ leído', 'fallido' => 'No se envió', 'bloqueado' => 'Bloqueado por el tope'];
$silencio = !empty($c['silencio_hasta']) && new DateTimeImmutable($c['silencio_hasta']) > new DateTimeImmutable();
$gratis = !empty($c['gratis_hasta']) && new DateTimeImmutable($c['gratis_hasta']) > new DateTimeImmutable();
?>
<div class="chat-cabeza">
    <a href="<?= e(url('bandeja')) ?>" class="volver" aria-label="Volver a la bandeja">‹</a>
    <div>
        <h1><?= e(Contactos::nombre($c)) ?></h1>
        <p class="suave"><?= e(Telefono::bonito($c['telefono'])) ?><?= $c['nombre_whatsapp'] !== '' && $c['nombre_whatsapp'] !== Contactos::nombre($c) ? ' · en WhatsApp: ' . e($c['nombre_whatsapp']) : '' ?></p>
    </div>
</div>

<form method="post" class="segmentos" aria-label="Tipo de contacto">
    <?= csrf_campo() ?><input type="hidden" name="accion" value="tipo">
    <?php foreach (['cliente' => 'Cliente', 'desconocido' => 'Desconocido', 'personal' => 'Personal'] as $t => $n): ?>
    <button name="tipo" value="<?= $t ?>" class="<?= $c['tipo'] === $t ? 'activo seg-' . $t : '' ?>" <?= $c['tipo'] === $t ? 'aria-pressed="true"' : 'aria-pressed="false"' ?>><?= $n ?></button>
    <?php endforeach; ?>
</form>
<?php if ($c['tipo'] === 'personal'): ?>
<p class="nota">Personal: el sistema <b>nunca</b> le contesta solo. Contéstale tú desde el celular (gratis).</p>
<?php endif; ?>
<?php if ($silencio): ?>
<form method="post" class="nota nota-silencio">
    <?= csrf_campo() ?><input type="hidden" name="accion" value="silencio"><input type="hidden" name="horas" value="0">
    Pidió hablar con una persona: no se le contesta solo hasta <?= e(hora_corta($c['silencio_hasta'])) ?>. <button class="enlace">Volver a activar</button>
</form>
<?php endif; ?>
<?php if ($gratis): ?>
<p class="nota nota-ok">Llegó desde un anuncio: sus mensajes no se cobran hasta <?= e(hora_corta($c['gratis_hasta'])) ?>.</p>
<?php endif; ?>

<ol class="mensajes" id="mensajes" data-contacto="<?= (int) $c['id'] ?>" data-ultimo="<?= (int) (end($mensajes)['id'] ?? 0) ?>">
    <?php foreach ($mensajes as $m): ?>
    <li class="burbuja <?= $m['direccion'] === 'entra' ? 'entra' : 'sale origen-' . e($m['origen']) ?> <?= in_array($m['estado'], ['fallido', 'bloqueado'], true) ? 'fallo' : '' ?>">
        <?php if ($m['plantilla']): ?><span class="etiqueta">Plantilla <?= e($m['plantilla']) ?></span><?php endif; ?>
        <p><?= nl2br(e($m['texto'])) ?></p>
        <span class="pie">
            <?php if ($m['direccion'] === 'sale'): ?><?= e($origenes[$m['origen']] ?? '') ?> · <?php endif; ?>
            <?= e(hora_corta($m['creado'])) ?>
            <?php if ($m['direccion'] === 'sale'): ?>
                · <?= e($estados[$m['estado']] ?? $m['estado']) ?>
                <?php if ((float) $m['costo'] > 0): ?> · <?= e(dinero($m['costo'], true)) ?><?php elseif (!in_array($m['estado'], ['fallido', 'bloqueado'], true)): ?> · gratis<?php endif; ?>
            <?php endif; ?>
        </span>
        <?php if ($m['error']): ?><span class="motivo motivo-error"><?= e($m['error']) ?></span><?php endif; ?>
        <?php if ($m['direccion'] === 'entra' && $m['motivo']): ?><span class="motivo"><?= e($m['motivo']) ?></span><?php endif; ?>
    </li>
    <?php endforeach; ?>
</ol>

<form method="post" class="escribir" id="escribir">
    <?= csrf_campo() ?>
    <label class="oculto" for="texto">Mensaje</label>
    <textarea id="texto" name="texto" rows="3" maxlength="4096" placeholder="Escribe tu respuesta…" required><?= e($borrador) ?></textarea>
    <div class="botones-envio">
        <a class="boton boton-gratis" id="enviar-celular" href="<?= e(Telefono::enlace($c['telefono'])) ?>" data-tel="<?= e($c['telefono']) ?>" target="_blank" rel="noopener">
            Enviar desde el celular <small>gratis</small>
        </a>
        <?php if ($ventana): ?>
        <button class="boton boton-pago" name="accion" value="enviar">Enviar por el sistema <small><?= $precio > 0 ? e(dinero($precio, true)) : 'gratis' ?></small></button>
        <?php else: ?>
        <a class="boton boton-pago" href="<?= e(url('plantilla', ['c' => $c['id']])) ?>">Enviar plantilla <small>pagado</small></a>
        <?php endif; ?>
    </div>
    <?php if (!$ventana): ?>
    <p class="suave pequeno">Pasaron más de 24 horas desde su último mensaje: por el sistema solo se puede mandar una plantilla aprobada por Meta. Desde el celular puedes escribir lo que quieras, gratis.</p>
    <?php endif; ?>
    <button class="enlace" name="accion" value="pendiente" formnovalidate>Guardar en Pendientes para enviarlo después</button>
</form>

<details class="tarjeta plegable">
    <summary>Datos y notas de este contacto</summary>
    <form method="post" class="formulario">
        <?= csrf_campo() ?><input type="hidden" name="accion" value="datos">
        <label>Nombre<input name="nombre" maxlength="100" value="<?= e($c['nombre']) ?>" placeholder="<?= e($c['nombre_whatsapp']) ?>"></label>
        <label>Notas<textarea name="notas" rows="3" maxlength="2000"><?= e($c['notas']) ?></textarea></label>
        <button class="boton">Guardar</button>
    </form>
    <?php if (!$silencio && $c['tipo'] !== 'personal'): ?>
    <form method="post" class="formulario">
        <?= csrf_campo() ?><input type="hidden" name="accion" value="silencio">
        <p class="suave">¿Lo estás atendiendo tú? Pausa las respuestas automáticas para esta persona:</p>
        <div class="fila-botones">
            <button class="boton boton-claro" name="horas" value="4">4 horas</button>
            <button class="boton boton-claro" name="horas" value="24">1 día</button>
            <button class="boton boton-claro" name="horas" value="168">1 semana</button>
        </div>
    </form>
    <?php endif; ?>
</details>
