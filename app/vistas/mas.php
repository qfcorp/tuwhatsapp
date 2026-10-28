<?php
/**
 * Todo lo que no cabe en la barra de abajo. Todo el programa viene incluido; solo la IA se cobra aparte
 * (si no la tiene, se ve con candado y el botón para pedirla).
 * [ruta, nombre, para qué sirve, solo administrador]
 */
$grupos = [
    'Vender' => [
        ['pedidos', 'Pedidos', 'Carritos y pedidos de tus clientes: pendientes de pago, pagados, enviados.', false],
        ['catalogo', 'Catálogo', 'Tus productos o servicios con precio y stock, para mandarlos por el chat.', true],
        ['cobros', 'Cobros y cotizaciones', 'Tarjeta con Plux o Payphone, transferencia, IVA y cotización en PDF.', true],
        ['campanias', 'Campañas', 'Mandar una plantilla a muchos clientes a la vez.', true],
        ['seguimientos', 'Seguimientos', 'Mensajes programados: escribir solo a quien no respondió.', false],
        ['enlaces', 'Enlaces y QR', 'Enlace corto, código QR y botón de WhatsApp para tu página web.', true],
        ['tienda', 'Tienda en línea', 'Shopify o WooCommerce: avisos de pedido y carritos abandonados.', true],
    ],
    'Automático' => [
        ['reglas', 'Respuestas automáticas', 'Menú con botones, horario y palabras clave.', true],
        ['flujos', 'Chatbots', 'Conversaciones por pasos con botones y preguntas, sin programar.', true],
        ['ia', 'Agente con IA', 'Contesta solo las 24 horas con lo que sabe tu negocio y tu catálogo.', true],
        ['asistente', 'Pregúntale a la IA', 'Pide en palabras: quién está listo para comprar, arma una campaña, cómo te fue. Resumen cada mañana.', false],
    ],
    'Equipo' => [
        ['equipo', 'Equipo y reparto', 'Quién atiende qué, reparto automático y vista del supervisor.', true],
        ['reportes', 'Reportes', 'Mensajes, tiempos de respuesta, ventas y de qué anuncio llegó cada cliente.', true],
        ['llamadas', 'Llamadas', 'Contesta y haz llamadas de WhatsApp desde el panel.', false],
        ['app', 'App en el celular', 'Instálalo como app y recibe avisos aunque el navegador esté cerrado.', false],
    ],
    'Ajustes' => [
        ['campos', 'Campos personalizados', 'Datos propios de cada cliente: ciudad, cédula, cumpleaños, talla…', true],
        ['plantillas', 'Plantillas', 'Mensajes aprobados por Meta para escribir pasadas 24 horas.', false],
        ['respuestas', 'Respuestas rápidas', 'Textos guardados para pegar en el chat con un toque.', true],
        ['integraciones', 'Integraciones', 'Excel y avisos a otros programas (Make, Zapier, n8n).', true],
        ['gasto', 'Gasto en Meta', 'Cuánto llevas gastado este mes y el tope.', false],
        ['lineas', 'Números conectados', 'Tus números de WhatsApp y cómo conectarlos.', true],
        ['cuenta', 'Mi cuenta', 'Contraseña, equipo y token de la API.', false],
    ],
];
$conIa = modulo('ia');
?>
<div class="cabeza"><h1>Más</h1></div>
<?php foreach ($grupos as $titulo => $items): ?>
<h2 class="separador"><?= e($titulo) ?></h2>
<ul class="mosaico">
    <?php foreach ($items as [$ruta, $nombre, $texto, $soloAdmin]): if ($soloAdmin && !es_admin()) continue; ?>
    <?php if (in_array($ruta, ['ia', 'asistente'], true) && !$conIa): ?>
    <li class="bloqueado"><div><b><?= e($nombre) ?> <small class="chip">Extra</small></b><span><?= e($texto) ?></span>
        <a class="pequeno" href="https://wa.me/593996408397?text=<?= rawurlencode('Hola, quiero activar el Agente con IA en WhatsApp Tukán.') ?>" target="_blank" rel="noopener">Pedir a Tukán</a></div></li>
    <?php else: ?>
    <li><a href="<?= e(url($ruta)) ?>"><b><?= e($nombre) ?><?= in_array($ruta, ['ia', 'asistente'], true) ? ' <small class="chip chip-cliente">Activo</small>' : '' ?></b><span><?= e($texto) ?></span></a></li>
    <?php endif; ?>
    <?php endforeach; ?>
</ul>
<?php endforeach; ?>
