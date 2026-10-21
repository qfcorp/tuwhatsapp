<?php
declare(strict_types=1);

// Acuerdo de tratamiento de datos (LOPDP Ecuador): el Cliente es responsable y Tukán es encargado del tratamiento.
define('TUWA_SIN_SESION', true);
require dirname(__DIR__) . '/app/bootstrap.php';
header('Content-Type: text/html; charset=utf-8');
$titulo = 'Acuerdo de tratamiento de datos';
ob_start();
?>
<h1>Acuerdo de tratamiento de datos personales</h1>
<p>Versión <?= e(\TuWhatsApp\Terminos::VERSION) ?>. Forma parte de los <a href="/terminos.php">Términos y condiciones</a> de WhatsApp Tukán y sigue la
Ley Orgánica de Protección de Datos Personales del Ecuador (LOPDP).</p>

<h2>1. Quién es quién</h2>
<ul>
    <li><b>Responsable del tratamiento:</b> el Cliente (el negocio). Decide para qué y cómo usa los datos de las personas con las que conversa.</li>
    <li><b>Encargado del tratamiento:</b> Tukán Software (QF Producciones). Trata esos datos solo para dar el servicio y siguiendo las instrucciones
    del Cliente.</li>
    <li><b>Meta Platforms</b> trata los mensajes como proveedor de WhatsApp según sus propias condiciones.</li>
</ul>

<h2>2. Qué datos y para qué</h2>
<p>Números de teléfono, nombres, mensajes, estados de los mensajes, etiquetas, notas y permisos de promociones de los contactos del Cliente;
y los datos de acceso de su equipo. Solo para mostrar y enviar mensajes, aplicar las reglas que el Cliente configura, llevar el gasto y dar
soporte.</p>

<h2>3. Compromisos de Tukán como encargado</h2>
<ul>
    <li>No usar los datos para fines propios, no venderlos y no compartirlos con terceros, salvo Meta para enviar y recibir los mensajes y los
    proveedores de servidores necesarios para el servicio.</li>
    <li>Guardar confidencialidad y limitar el acceso a las personas de Tukán que dan soporte (cada entrada a una cuenta para soporte se hace desde
    el panel de Tukán).</li>
    <li>Aplicar medidas de seguridad: conexión cifrada, contraseñas protegidas, tokens de Meta cifrados, cuentas separadas por cliente y respaldos
    diarios.</li>
    <li>Avisar al Cliente sin demora indebida si ocurre una vulneración de seguridad que afecte sus datos, con lo que se sepa para que pueda
    cumplir sus obligaciones ante la Autoridad de Protección de Datos.</li>
    <li>Ayudar al Cliente a atender los pedidos de acceso, corrección, eliminación u oposición de las personas titulares de los datos.</li>
    <li>Al terminar el servicio, entregar al Cliente lo que pida dentro de 30 días y luego borrar sus datos, salvo lo que la ley obligue a guardar.</li>
</ul>

<h2>4. Compromisos del Cliente como responsable</h2>
<ul>
    <li>Tener la base legal para tratar los datos (consentimiento, relación contractual u otra que permita la LOPDP) y informar a sus clientes.</li>
    <li>Pedir permiso antes de enviar promociones y respetar las bajas.</li>
    <li>No subir datos sensibles (salud, datos de niños, etc.) si no tiene la base legal para hacerlo.</li>
</ul>

<h2>5. Contacto</h2>
<p>Delegado o persona de contacto de Tukán para protección de datos: <a href="mailto:info@tukansoftware.com">info@tukansoftware.com</a> · WhatsApp 099 640 8397.</p>
<?php
$contenido = (string) ob_get_clean();
require RAIZ . '/app/vistas/_publica.php';
