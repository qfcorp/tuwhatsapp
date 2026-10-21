<?php
declare(strict_types=1);

// Instrucciones para eliminar datos (la pide Meta para publicar la app). No usa sesión.
define('TUWA_SIN_SESION', true);
require dirname(__DIR__) . '/app/bootstrap.php';
header('Content-Type: text/html; charset=utf-8');
$titulo = 'Eliminar mis datos';
ob_start();
?>
<h1>Cómo eliminar tus datos</h1>
<h2>Si eres un negocio que usa WhatsApp Tukán</h2>
<ol>
    <li>Escribe a <a href="mailto:info@tukansoftware.com">info@tukansoftware.com</a> o al WhatsApp 099 640 8397 desde el correo o número registrado, con el asunto <b>"Eliminar mis datos"</b>.</li>
    <li>En un máximo de 15 días borramos tu cuenta, tus números conectados, contactos, mensajes y tokens, y te confirmamos por el mismo medio.</li>
    <li>Además puedes quitar el permiso a la app desde Facebook: <b>Configuración y privacidad → Configuración → Integraciones comerciales</b> → elige la app → <b>Eliminar</b>.</li>
</ol>
<h2>Si escribiste a un negocio por WhatsApp</h2>
<p>Pide al negocio que borre tu conversación, o escríbenos a <a href="mailto:info@tukansoftware.com">info@tukansoftware.com</a> con tu número de teléfono
y el nombre del negocio, y la eliminaremos en un máximo de 15 días.</p>
<?php
$contenido = (string) ob_get_clean();
require RAIZ . '/app/vistas/_publica.php';
