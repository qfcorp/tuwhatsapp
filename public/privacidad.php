<?php
declare(strict_types=1);

// Política de privacidad pública (la pide Meta para publicar la app). No usa sesión.
define('TUWA_SIN_SESION', true);
require dirname(__DIR__) . '/app/bootstrap.php';
header('Content-Type: text/html; charset=utf-8');
$titulo = 'Política de privacidad';
ob_start();
?>
<h1>Política de privacidad</h1>
<p>Esta política explica cómo <b>Tukán Software</b> (marca de QF Producciones, Ecuador) trata los datos en su programa de mensajería
<b>WhatsApp Tukán</b> y en las conexiones con la plataforma de WhatsApp Business de Meta. Contacto: <a href="mailto:info@tukansoftware.com">info@tukansoftware.com</a> · WhatsApp 099 640 8397.</p>

<h2>Qué datos tratamos</h2>
<ul>
    <li>De los negocios que usan el programa: nombre, correo de acceso y los datos de su cuenta de WhatsApp Business (número, identificadores y el permiso que entregan desde Meta).</li>
    <li>De las personas que escriben a esos negocios por WhatsApp: número de teléfono, nombre de perfil, los mensajes de la conversación y su estado (enviado, entregado, leído).</li>
    <li>Si el negocio conecta la app WhatsApp Business del celular y lo autoriza: sus contactos y el historial de chats que Meta comparte.</li>
    <li>Pagos con tarjeta (si el negocio cobra con Pago Plux): los datos de la tarjeta se cifran en el navegador del comprador y van directo y cifrados a Pago Plux.
        No guardamos el número, la fecha de vencimiento ni el CVV. Solo guardamos el resultado (aprobado o no), el número de transacción y el nombre, cédula y correo del titular que Plux necesita para el cobro.</li>
</ul>

<h2>Para qué los usamos</h2>
<ul>
    <li>Mostrar al negocio sus conversaciones, enviar las respuestas que el negocio escribe o configura, y llevar el control del gasto en Meta.</li>
    <li>No vendemos ni alquilamos datos. No los usamos para publicidad. No los compartimos con terceros, salvo con Meta para enviar y recibir los mensajes.</li>
</ul>

<h2>Dónde se guardan y por cuánto tiempo</h2>
<ul>
    <li>En servidores propios de QF Producciones en Ecuador, con acceso protegido; los tokens de acceso se guardan cifrados.</li>
    <li>Mientras el negocio use el servicio. Los registros técnicos se borran a los 30 días y las copias de respaldo a los 14 días.</li>
</ul>

<h2>Tus derechos</h2>
<p>Según la Ley Orgánica de Protección de Datos Personales del Ecuador puedes pedir acceso, corrección o eliminación de tus datos,
o oponerte a su uso. Escríbenos a <a href="mailto:info@tukansoftware.com">info@tukansoftware.com</a>. Para eliminar datos, sigue los pasos de
<a href="/eliminar-datos.php">esta página</a>.</p>
<?php
$contenido = (string) ob_get_clean();
require RAIZ . '/app/vistas/_publica.php';
