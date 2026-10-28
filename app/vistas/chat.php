<?php
/** @var list<array> $equipo @var list<array> $respuestas @var array $linea @var array $c @var list<array> $mensajes @var string $borrador @var bool $ventana @var float $precio @var list<array> $seguimientos @var string $resumenIa
 *  @var list<array> $productos @var ?array $carrito @var list<array> $itemsCarrito @var list<array> $pedidosCliente @var list<array> $campos */
use TuWhatsApp\Contactos;
use TuWhatsApp\Telefono;
$origenes = ['cliente' => '', 'panel' => 'Por el sistema', 'auto' => 'Automático', 'bot' => 'Agente con IA', 'flujo' => 'Chatbot', 'tienda' => 'Tienda en línea', 'campania' => 'Campaña', 'celular' => 'Desde el celular', 'pendiente' => 'Pendiente'];
$estados = ['enviando' => 'Enviando…', 'enviado' => '✓', 'entregado' => '✓✓', 'leido' => '✓✓ leído', 'fallido' => 'No se envió', 'bloqueado' => 'Bloqueado por el tope'];
$silencio = !empty($c['silencio_hasta']) && new DateTimeImmutable($c['silencio_hasta']) > new DateTimeImmutable();
$gratis = !empty($c['gratis_hasta']) && new DateTimeImmutable($c['gratis_hasta']) > new DateTimeImmutable();
?>
<div class="chat-cabeza">
    <a href="<?= e(url('bandeja')) ?>" class="volver" aria-label="Volver a la bandeja">‹</a>
    <div>
        <h1><?= u(Contactos::nombre($c)) ?></h1>
        <p class="suave"><?= e(Telefono::bonito($c['telefono'])) ?><?= $c['nombre_whatsapp'] !== '' && $c['nombre_whatsapp'] !== Contactos::nombre($c) ? ' · en WhatsApp: ' . e($c['nombre_whatsapp']) : '' ?><?= $c['etiquetas'] !== '' ? ' · ' . e($c['etiquetas']) : '' ?><?= $c['marketing'] === 'baja' ? ' · <b>no quiere promociones</b>' : '' ?></p>
    </div>
</div>

<form method="post" class="segmentos" aria-label="Tipo de contacto">
    <?= csrf_campo() ?><input type="hidden" name="accion" value="tipo">
    <?php foreach (['cliente' => 'Cliente', 'desconocido' => 'Desconocido', 'personal' => 'Personal'] as $t => $n): ?>
    <button name="tipo" value="<?= $t ?>" class="<?= $c['tipo'] === $t ? 'activo seg-' . $t : '' ?>" <?= $c['tipo'] === $t ? 'aria-pressed="true"' : 'aria-pressed="false"' ?>><?= $n ?></button>
    <?php endforeach; ?>
</form>
<?php if (count($equipo) > 1 && $c['tipo'] !== 'personal'): $yoId = (int) usuario()['id']; ?>
<?php if (modulo('agentes')): ?>
<form method="post" class="asignar">
    <?= csrf_campo() ?><input type="hidden" name="accion" value="asignar">
    <label for="asignado">Atiende</label>
    <select id="asignado" name="usuario_id" onchange="this.form.submit()">
        <option value="">Nadie todavía</option>
        <?php foreach ($equipo as $x): ?>
        <option value="<?= (int) $x['id'] ?>" <?= (int) ($c['asignado_a'] ?? 0) === (int) $x['id'] ? 'selected' : '' ?>><?= e($x['nombre']) ?><?= (int) $x['id'] === $yoId ? ' (yo)' : '' ?></option>
        <?php endforeach; ?>
    </select>
    <?php if ((int) ($c['asignado_a'] ?? 0) !== $yoId): ?><button class="boton boton-claro" name="usuario_id" value="yo">Atender yo</button><?php endif; ?>
    <noscript><button class="boton boton-claro">Cambiar</button></noscript>
</form>
<?php endif; ?>
<?php endif; ?>
<?php if ($c['tipo'] === 'personal'): ?>
<p class="nota">Personal: el sistema <b>nunca</b> le contesta solo.<?= !empty($linea['coexistencia']) ? ' Contéstale tú desde el celular (gratis).' : '' ?></p>
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
<?php if (!empty($c['origen_id'])): ?>
<p class="nota pequeno">📣 Llegó desde <?= $c['origen_tipo'] === 'publicacion' ? 'una publicación' : 'el anuncio' ?> <b><?= u($c['origen_titulo'] ?: $c['origen_id']) ?></b><?= $c['origen_en'] ? ' el ' . e((new DateTimeImmutable($c['origen_en']))->format('d/m/Y')) : '' ?><?= $c['origen_url'] ? ' · <a href="' . e($c['origen_url']) . '" target="_blank" rel="noopener">ver</a>' : '' ?>.</p>
<?php endif; ?>

<?php if (!empty($linea['llamadas_activo']) && $c['tipo'] !== 'personal'): $puedeLlamar = \TuWhatsApp\Llamadas::puedeLlamar($c); ?>
<div class="nota llamar-chat">
    <?php if ($puedeLlamar): ?>
    <button type="button" class="boton boton-verde" onclick="window.open('<?= e(url('llamar', ['c' => $c['id']])) ?>', 'llamada_c<?= (int) $c['id'] ?>', 'width=380,height=620')">📞 Llamar</button>
    <span class="suave pequeno">Te dio permiso<?= $c['llamar_permiso'] === 'temporal' && $c['llamar_hasta'] ? ' hasta ' . e(hora_corta((string) $c['llamar_hasta'])) : ' (siempre)' ?>.</span>
    <?php else: ?>
    <form method="post" class="en-linea"><?= csrf_campo() ?><input type="hidden" name="accion" value="pedir_llamada">
        <button class="boton boton-claro" <?= $ventana ? '' : 'disabled' ?>>📞 Pedir permiso para llamar</button></form>
    <span class="suave pequeno"><?= $c['llamar_permiso'] === 'rechazado' ? 'No aceptó la última vez.' : ($ventana ? 'Le llega un botón de WhatsApp para aceptar.' : 'Se puede pedir cuando te escriba (24 horas).') ?></span>
    <?php endif; ?>
    <?php foreach ($llamadas as $l): ?>
    <span class="pequeno ll-reciente"><?= $l['estado'] === 'perdida' ? '📵 Perdida' : ($l['direccion'] === 'entra' ? '↙ Te llamó' : '↗ Llamaste') ?><?= $l['duracion'] !== null ? ' · ' . e(\TuWhatsApp\Llamadas::duracion((int) $l['duracion'])) : '' ?> · <?= e(hora_corta((string) $l['creado'])) ?></span>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<ol class="mensajes" id="mensajes"data-contacto="<?= (int) $c['id'] ?>" data-ultimo="<?= (int) (end($mensajes)['id'] ?? 0) ?>">
    <?php foreach ($mensajes as $m): ?>
    <li class="burbuja <?= $m['direccion'] === 'entra' ? 'entra' : 'sale origen-' . e($m['origen']) ?> <?= in_array($m['estado'], ['fallido', 'bloqueado'], true) ? 'fallo' : '' ?>">
        <?php if ($m['plantilla']): ?><span class="etiqueta">Plantilla <?= e($m['plantilla']) ?></span><?php endif; ?>
        <?php if ($m['media_ruta'] || $m['media_id']):
            $src = url('archivo', ['id' => $m['id']]);
            $pie = trim((string) preg_replace('/^\[(Foto|Imagen|Video|Audio|Sticker|Documento)\]\s*/u', '', (string) $m['texto']));
            if ($m['tipo'] === 'documento') { $pie = trim(str_replace((string) $m['media_nombre'], '', $pie)); } ?>
        <?php if (in_array($m['tipo'], ['imagen', 'sticker'], true)): ?>
        <a href="<?= e($src) ?>" target="_blank" rel="noopener" class="adjunto-foto"><img src="<?= e($src) ?>" alt="Foto" loading="lazy"></a>
        <?php elseif ($m['tipo'] === 'audio'): ?>
        <audio controls preload="none" src="<?= e($src) ?>"></audio>
        <?php elseif ($m['tipo'] === 'video'): ?>
        <video controls preload="none" src="<?= e($src) ?>"></video>
        <?php else: ?>
        <a class="adjunto-doc" href="<?= e(url('archivo', ['id' => $m['id'], 'bajar' => 1])) ?>">📄 <?= e($m['media_nombre'] ?: 'Documento') ?><?= $m['media_bytes'] ? ' · ' . e(\TuWhatsApp\Archivos::tamanio((int) $m['media_bytes'])) : '' ?></a>
        <?php endif; ?>
        <?php if ($pie !== ''): ?><p><?= '<!--notr-->' . nl2br(e($pie)) . '<!--/notr-->' ?></p><?php endif; ?>
        <?php else: ?>
        <p><?= '<!--notr-->' . nl2br(e($m['texto'])) . '<!--/notr-->' ?></p>
        <?php endif; ?>
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

<form method="post" class="escribir" id="escribir" enctype="multipart/form-data">
    <?= csrf_campo() ?>
    <label class="oculto" for="texto">Mensaje</label>
    <?php if ($respuestas): ?>
    <div class="rapidas">
        <label for="rapida" class="oculto">Respuesta rápida</label>
        <select id="rapida" aria-label="Respuesta rápida">
            <option value="">Respuesta rápida…</option>
            <?php foreach ($respuestas as $rr): ?><option value="<?= e($rr['texto']) ?>">/<?= e($rr['atajo']) ?> · <?= e(mb_strimwidth($rr['texto'], 0, 40, '…')) ?></option><?php endforeach; ?>
        </select>
        <?php if (es_admin()): ?><a href="<?= e(url('respuestas')) ?>">Editar</a><?php endif; ?>
    </div>
    <?php elseif (es_admin()): ?>
    <p class="suave pequeno"><a href="<?= e(url('respuestas')) ?>">Crear respuestas rápidas</a> para no escribir lo mismo cada vez.</p>
    <?php endif; ?>
    <?php if ($resumenIa !== ''): ?><div class="aviso aviso-info resumen-ia"><b>Resumen (IA):</b><br><?= nl2br(e($resumenIa)) ?></div><?php endif; ?>
    <textarea id="texto" name="texto" rows="3" maxlength="4096" placeholder="Escribe tu respuesta…" required><?= e($borrador) ?></textarea>
    <?php if (modulo('ia')): ?>
    <div class="fila-botones ia-botones">
        <button class="boton boton-claro" name="accion" value="ia_sugerir" formnovalidate>✨ Sugerir respuesta</button>
        <button class="boton boton-claro" name="accion" value="ia_resumir" formnovalidate>Resumir</button>
    </div>
    <?php endif; ?>
    <?php if ($ventana): ?>
    <div class="adjuntar">
        <label class="boton boton-claro" for="archivo" title="Foto, audio, video o documento">📎 Adjuntar</label>
        <input type="file" id="archivo" name="archivo" class="oculto"
               accept="image/jpeg,image/png,audio/*,video/mp4,video/3gpp,application/pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.zip">
        <button type="button" class="boton boton-claro" id="grabar" hidden>🎤 Nota de voz</button>
        <span id="adjunto-nombre" class="suave pequeno" aria-live="polite"></span>
    </div>
    <?php endif; ?>
    <?php $celular = !empty($linea['coexistencia']); /* solo si el número también está en la app del celular */ ?>
    <div class="botones-envio <?= $celular ? '' : 'uno' ?>">
        <?php if ($celular): ?>
        <a class="boton boton-gratis" id="enviar-celular" href="<?= e(Telefono::enlace($c['telefono'])) ?>" data-tel="<?= e($c['telefono']) ?>" target="_blank" rel="noopener">
            Enviar desde el celular <small>gratis</small>
        </a>
        <?php endif; ?>
        <?php if ($ventana): ?>
        <button class="boton boton-pago" name="accion" value="enviar">Enviar por el sistema <small><?= $precio > 0 ? e(dinero($precio, true)) : 'gratis' ?></small></button>
        <?php else: ?>
        <a class="boton boton-pago" href="<?= e(url('plantilla', ['c' => $c['id']])) ?>">Enviar plantilla <small>pagado</small></a>
        <?php endif; ?>
    </div>
    <?php if (!$ventana): ?>
    <p class="suave pequeno">Pasaron más de 24 horas desde su último mensaje: por el sistema solo se puede mandar una plantilla aprobada por Meta.<?= $celular ? ' Desde el celular puedes escribir lo que quieras, gratis.' : '' ?></p>
    <?php endif; ?>
    <?php if ($celular): ?><button class="enlace" name="accion" value="pendiente" formnovalidate>Guardar en Pendientes para enviarlo después</button><?php endif; ?>
</form>


<?php if ($c['tipo'] !== 'personal'): ?>
<details class="tarjeta plegable carrito-chat" <?= $itemsCarrito ? 'open' : '' ?>>
    <summary>🛒 Carrito y cotización<?= $carrito && $itemsCarrito ? ' · ' . e(dinero($carrito['total'])) : '' ?></summary>
    <?php if (!$productos): ?>
    <p class="suave pequeno">Primero agrega tus productos en el <a href="<?= e(url('catalogo')) ?>">Catálogo</a><?= es_admin() ? '' : ' (lo hace el administrador)' ?>.</p>
    <?php else: ?>
    <?php if ($itemsCarrito): ?>
    <ul class="lista-simple items-pedido">
        <?php foreach ($itemsCarrito as $i): ?><li><span><?= (int) $i['cantidad'] ?> x <?= u($i['nombre']) ?></span><b class="derecha"><?= e(dinero((float) $i['precio'] * (int) $i['cantidad'])) ?></b></li><?php endforeach; ?>
    </ul>
    <p class="totales"><?php if ((float) $carrito['iva'] > 0): ?>Subtotal <?= e(dinero($carrito['subtotal'])) ?> · IVA <?= e(dinero($carrito['iva'])) ?> · <?php endif; ?><b>Total <?= e(dinero($carrito['total'])) ?></b>
        · <a href="<?= e(url('pedido', ['id' => $carrito['id']])) ?>">Cambiar cantidades</a></p>
    <div class="fila-botones">
        <form method="post" class="en-linea"><?= csrf_campo() ?><input type="hidden" name="accion" value="carrito_resumen">
            <button class="boton" <?= $ventana ? '' : 'disabled' ?>>Mandar resumen y cómo pagar</button></form>
        <form method="post" class="en-linea"><?= csrf_campo() ?><input type="hidden" name="accion" value="carrito_cotizacion">
            <button class="boton boton-claro" <?= $ventana ? '' : 'disabled' ?>>Mandar cotización PDF</button></form>
        <a class="boton boton-claro" href="<?= e(url('cotizacion', ['id' => $carrito['id']])) ?>">Descargar PDF</a>
    </div>
    <?php endif; ?>
    <form method="post" class="formulario"><?= csrf_campo() ?><input type="hidden" name="accion" value="carrito_agregar">
        <div class="fila-doble">
            <label>Agregar producto<select name="producto_id" required><option value="">Elegir…</option>
                <?php foreach ($productos as $x): ?><option value="<?= (int) $x['id'] ?>"><?= e($x['nombre']) ?> · <?= e(dinero($x['precio'])) ?><?= $x['stock'] !== null ? ' (quedan ' . (int) $x['stock'] . ')' : '' ?></option><?php endforeach; ?>
            </select></label>
            <label>Cantidad<input name="cantidad" type="number" min="1" max="999" value="1"></label>
        </div>
        <button class="boton boton-claro">Agregar al carrito</button>
    </form>
    <?php if ($ventana): ?>
    <form method="post" class="formulario"><?= csrf_campo() ?><input type="hidden" name="accion" value="enviar_producto">
        <label>Mandarle un producto (con foto y precio)<select name="producto_id" required><option value="">Elegir…</option>
            <?php foreach ($productos as $x): ?><option value="<?= (int) $x['id'] ?>"><?= e($x['nombre']) ?></option><?php endforeach; ?>
        </select></label>
        <div class="fila-botones"><button class="boton boton-claro">Mandar producto</button>
            <button class="boton boton-claro" name="accion" value="enviar_catalogo" formnovalidate>Mandar catálogo para elegir</button></div>
    </form>
    <?php endif; ?>
    <?php endif; ?>
    <?php if ($pedidosCliente): ?>
    <p class="suave pequeno">Pedidos: <?php foreach ($pedidosCliente as $k => $pc): ?><?= $k ? ' · ' : '' ?><a href="<?= e(url('pedido', ['id' => $pc['id']])) ?>">#<?= (int) $pc['numero'] ?> <?= e(\TuWhatsApp\Pedidos::ESTADOS[$pc['estado']]) ?> <?= e(dinero($pc['total'])) ?></a><?php endforeach; ?></p>
    <?php endif; ?>
</details>
<?php endif; ?>
<?php if (modulo('embudo') && $c['tipo'] !== 'personal'): ?>
<form method="post" class="tarjeta formulario embudo-chat">
    <?= csrf_campo() ?><input type="hidden" name="accion" value="etapa">
    <div class="fila-doble">
        <label>Etapa de la venta<select name="etapa">
            <option value="" <?= $c['etapa'] === null ? 'selected' : '' ?>>Fuera del embudo</option>
            <?php foreach (\TuWhatsApp\Embudo::ETAPAS as $k => $n): ?><option value="<?= e($k) ?>" <?= $c['etapa'] === $k ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?>
        </select></label>
        <label>Valor ($)<input name="valor" inputmode="decimal" maxlength="15" value="<?= (float) $c['valor'] > 0 ? e(number_format((float) $c['valor'], 2, ',', '.')) : '' ?>" placeholder="0"></label>
    </div>
    <button class="boton boton-claro">Guardar etapa</button> <a class="pequeno" href="<?= e(url('embudo')) ?>">Ver embudo</a>
</form>
<?php endif; ?>
<?php if (modulo('seguimientos') && $c['tipo'] !== 'personal'): ?>
<details class="tarjeta plegable" <?= $seguimientos ? 'open' : '' ?>>
    <summary>Programar seguimiento<?= $seguimientos ? ' (' . count(array_filter($seguimientos, fn ($s) => $s['estado'] === 'programado')) . ' programados)' : '' ?></summary>
    <?php foreach ($seguimientos as $s): ?>
    <form method="post" class="seguimiento-item">
        <?= csrf_campo() ?><input type="hidden" name="accion" value="cancelar_seguimiento"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
        <span><b><?= e((new DateTimeImmutable($s['cuando']))->format('d/m H:i')) ?></b> · <?= e(\TuWhatsApp\Seguimientos::ESTADOS[$s['estado']] ?? $s['estado']) ?><br>
        <span class="suave pequeno"><?= u(mb_strimwidth($s['texto'], 0, 90, '…')) ?></span></span>
        <?php if ($s['estado'] === 'programado'): ?><button class="enlace enlace-rojo">Cancelar</button><?php endif; ?>
    </form>
    <?php endforeach; ?>
    <form method="post" class="formulario">
        <?= csrf_campo() ?><input type="hidden" name="accion" value="seguimiento">
        <label>Mensaje<textarea name="texto_seguimiento" rows="3" maxlength="4000" required placeholder="Hola <?= e(Contactos::nombre($c)) ?>, ¿pudiste revisar la cotización?"></textarea></label>
        <label>Cuándo<select name="cuando" class="seg-cuando">
            <?php foreach (\TuWhatsApp\Seguimientos::ATAJOS as $k => $n): ?><option value="<?= e($k) ?>" <?= $k === '2d' ? 'selected' : '' ?>><?= e($n) ?><?= str_ends_with($k, 'd') ? ' a las 9:00' : '' ?></option><?php endforeach; ?>
            <option value="fecha">Elegir fecha y hora…</option>
        </select></label>
        <label class="seg-fecha">Fecha y hora (si elegiste "Elegir fecha")<input type="datetime-local" name="fecha"></label>
        <label class="check"><input type="checkbox" name="solo_sin_respuesta" value="1" checked> Mandarlo solo si no responde antes</label>
        <button class="boton boton-claro">Programar</button>
    </form>
</details>
<?php endif; ?>
<details class="tarjeta plegable">
    <summary>Datos y notas de este contacto</summary>
    <form method="post" class="formulario">
        <?= csrf_campo() ?><input type="hidden" name="accion" value="datos">
        <label>Nombre<input name="nombre" maxlength="100" value="<?= e($c['nombre']) ?>" placeholder="<?= e($c['nombre_whatsapp']) ?>"></label>
        <label>Etiquetas (separadas por coma: vip, quito)<input name="etiquetas" maxlength="300" value="<?= e($c['etiquetas']) ?>"></label>
        <label>Notas<textarea name="notas" rows="3" maxlength="2000"><?= e($c['notas']) ?></textarea></label>
        <?php $valores = \TuWhatsApp\Campos::de($c); foreach ($campos as $cp): $val = $valores[$cp['clave']] ?? ''; ?>
        <label><?= e($cp['nombre']) ?>
        <?php if ($cp['tipo'] === 'lista'): ?><select name="campo[<?= e($cp['clave']) ?>]"><option value="">—</option>
            <?php foreach (array_map('trim', explode(',', $cp['opciones'])) as $o): ?><option <?= $val === $o ? 'selected' : '' ?>><?= e($o) ?></option><?php endforeach; ?></select>
        <?php else: ?><input name="campo[<?= e($cp['clave']) ?>]" maxlength="300" value="<?= e($val) ?>" <?= $cp['tipo'] === 'fecha' ? 'type="date"' : ($cp['tipo'] === 'numero' ? 'inputmode="decimal"' : '') ?>><?php endif; ?>
        </label>
        <?php endforeach; ?>
        <?php if (es_admin()): ?><p class="suave pequeno"><a href="<?= e(url('campos')) ?>">Agregar campos propios</a> (ciudad, cédula, cumpleaños…)</p><?php endif; ?>
        <button class="boton">Guardar</button>
    </form>
    <form method="post" class="formulario">
        <?= csrf_campo() ?><input type="hidden" name="accion" value="marketing">
        <p class="suave">¿Recibe promociones (campañas)? Meta pide que el cliente haya aceptado.</p>
        <div class="segmentos">
            <?php foreach (['acepta' => 'Aceptó', 'sin_dato' => 'Sin respuesta', 'baja' => 'No quiere'] as $k => $n): ?>
            <button name="marketing" value="<?= $k ?>" class="<?= $c['marketing'] === $k ? 'activo' : '' ?>" aria-pressed="<?= $c['marketing'] === $k ? 'true' : 'false' ?>"><?= $n ?></button>
            <?php endforeach; ?>
        </div>
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
