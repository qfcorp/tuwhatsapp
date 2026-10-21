<?php
declare(strict_types=1);

// Términos y condiciones del servicio (públicos). El administrador de cada cliente los acepta al entrar la primera vez.
define('TUWA_SIN_SESION', true);
require dirname(__DIR__) . '/app/bootstrap.php';
header('Content-Type: text/html; charset=utf-8');
$titulo = 'Términos y condiciones';
ob_start();
?>
<h1>Términos y condiciones de WhatsApp Tukán</h1>
<p>Versión <?= e(\TuWhatsApp\Terminos::VERSION) ?>. Estos términos son el acuerdo entre <b>Tukán Software</b> (marca de QF Producciones, Ecuador; en adelante "Tukán")
y el negocio que contrata el programa <b>WhatsApp Tukán</b> (en adelante "el Cliente"). Al aceptar en el programa, el Cliente declara que los leyó y que
quien acepta tiene autorización para hacerlo en nombre del negocio.</p>

<h2>1. Qué es el servicio</h2>
<p>Un programa en internet para atender el WhatsApp del Cliente con la API oficial de WhatsApp Business de Meta: bandeja de conversaciones,
respuestas automáticas, plantillas, campañas, contactos, equipo y control de gasto. Tukán es proveedor de tecnología (Tech Provider) de Meta;
no es Meta ni WhatsApp.</p>

<h2>2. Qué paga el Cliente y a quién</h2>
<ul>
    <li><b>A Tukán:</b> la mensualidad del plan acordado (o lo que diga su cotización). La demo es por el tiempo indicado; al terminar, la cuenta se
    detiene hasta que se contrate un plan.</li>
    <li><b>A Meta:</b> los mensajes que Meta cobra, directo a la tarjeta que el Cliente registra en su cuenta de WhatsApp Business. Tukán no cobra
    comisión sobre esos mensajes. Los precios de Meta los fija Meta y pueden cambiar.</li>
    <li>El <b>tope de gasto</b> del programa es una ayuda para no pasarse, calculada con los precios publicados por Meta; el cobro final es el que
    hace Meta.</li>
</ul>

<h2>3. Obligaciones del Cliente</h2>
<ul>
    <li>Cumplir las políticas de WhatsApp Business y de comercio de Meta, y las leyes del Ecuador (en especial la Ley Orgánica de Protección de Datos
    Personales y la de Defensa del Consumidor).</li>
    <li>Enviar promociones solo a personas que <b>aceptaron</b> recibirlas, y dejar de escribir a quien pide la baja.</li>
    <li>No usar el servicio para spam, fraude, contenido ilegal, productos prohibidos por Meta ni para suplantar a otro.</li>
    <li>Cuidar las contraseñas de su equipo y avisar a Tukán si cree que alguien entró sin permiso.</li>
    <li>Tener la base legal (permiso o relación con el cliente) para tratar los datos de las personas con las que conversa.</li>
</ul>

<h2>4. Obligaciones de Tukán</h2>
<ul>
    <li>Mantener el programa funcionando, con respaldo diario de la base de datos, y atender el soporte por WhatsApp (099 640 8397) o correo
    (<a href="mailto:info@tukansoftware.com">info@tukansoftware.com</a>) en horario laboral de Ecuador.</li>
    <li>Tratar los datos del Cliente según el <a href="/tratamiento-datos.php">Acuerdo de tratamiento de datos</a> y la
    <a href="/privacidad.php">Política de privacidad</a>.</li>
    <li>Avisar con anticipación los cambios de precio del plan y los cambios importantes de estos términos.</li>
</ul>

<h2>5. Lo que no depende de Tukán</h2>
<p>Meta puede limitar, pausar o bloquear un número o una cuenta por la calidad de sus mensajes o por incumplir sus políticas; también puede
cambiar sus precios, reglas y funciones. Tukán ayuda a resolverlo pero no garantiza decisiones de Meta. Tukán no responde por pérdidas
indirectas, lucro cesante ni por el contenido que el Cliente envía. Si hay una falla de Tukán, su responsabilidad máxima es el valor pagado por
el Cliente a Tukán en los últimos 3 meses.</p>

<h2>6. Suspensión y fin del servicio</h2>
<ul>
    <li>Tukán puede suspender la cuenta por falta de pago o por uso que incumpla estos términos o las políticas de Meta, avisando el motivo.</li>
    <li>El Cliente puede terminar cuando quiera. Al terminar puede pedir una copia de sus contactos; después de 30 días Tukán borra sus datos,
    salvo lo que la ley obligue a guardar.</li>
    <li>Mientras la cuenta está suspendida, los mensajes que llegan se siguen guardando, pero el programa no contesta ni envía.</li>
</ul>

<h2>7. Ley y conflictos</h2>
<p>Este acuerdo se rige por las leyes de la República del Ecuador. Las partes intentarán primero llegar a un acuerdo directo; si no, acudirán a
los jueces competentes del Ecuador.</p>
<?php
$contenido = (string) ob_get_clean();
require RAIZ . '/app/vistas/_publica.php';
