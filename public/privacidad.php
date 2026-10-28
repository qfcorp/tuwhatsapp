<?php
declare(strict_types=1);

// Política de privacidad pública (la piden Meta y Google Play). No usa sesión. tests/prueba_app_android.sh revisa lo esencial.
define('TUWA_SIN_SESION', true);
require dirname(__DIR__) . '/app/bootstrap.php';
header('Content-Type: text/html; charset=utf-8');
$titulo = 'Política de privacidad';
$actualizado = '28 de octubre de 2026';
ob_start();
?>
<h1>Política de privacidad</h1>
<p>Esta política explica cómo <b>Tukán Software</b> (marca de QF Producciones, Ecuador) trata los datos en su programa de mensajería
<b>WhatsApp Tukán</b>, que en la app de Android se llama <b>Tukán Mensajes</b> (whatsapp.tukanec.com), y en las conexiones con la
plataforma de WhatsApp Business de Meta. Contacto: <a href="mailto:info@tukansoftware.com">info@tukansoftware.com</a> · WhatsApp +593 99 640 8397.
Tukán Mensajes es un programa independiente: no es de WhatsApp ni de Meta y no está afiliado a ellas; usa la API oficial de WhatsApp Business.</p>

<h2>Quién decide sobre los datos</h2>
<ul>
    <li>Cada <b>negocio</b> que usa el programa es el responsable de los datos de sus clientes y contactos (decide con quién conversa y qué guarda).</li>
    <li><b>Tukán Software</b> es el encargado: guarda y procesa esos datos solo para dar el servicio, según la Ley Orgánica de Protección de Datos Personales del Ecuador (LOPDP) y el <a href="/tratamiento-datos.php">acuerdo de tratamiento de datos</a>.</li>
    <li>Las cuentas las crea Tukán Software para cada negocio. En la app no se crean cuentas nuevas ni se compra nada.</li>
</ul>

<h2>Qué datos tratamos</h2>
<ul>
    <li><b>De las personas del negocio que usan el programa</b>: nombre, correo de acceso, contraseña (cifrada), cargo (administrador o agente),
        idioma elegido y lo que hacen en el programa (quién respondió, a quién se asignó un chat).</li>
    <li><b>Del número de WhatsApp Business del negocio</b>: número, identificadores de Meta y el permiso (token) que el negocio entrega desde Meta,
        guardado cifrado.</li>
    <li><b>De las personas que escriben al negocio por WhatsApp (terceros)</b>: número de teléfono, nombre de perfil, <b>el contenido de los mensajes</b>
        (texto, <b>fotos, audios y notas de voz, videos, documentos</b>, ubicaciones o contactos que la persona decide enviar), la fecha y el estado de
        cada mensaje (enviado, entregado, leído), de qué anuncio llegó (si llegó por un anuncio) y lo que el negocio anota: etiquetas, notas,
        etapa de venta, campos propios, permiso para promociones o pedido de baja.</li>
    <li><b>Contactos que el negocio trae</b>: si el negocio sube un archivo de contactos (.vcf o .csv) o elige contactos de la agenda de su celular,
        se guardan el nombre y el número de los contactos elegidos. Si conecta la app WhatsApp Business del celular y lo autoriza, Meta comparte
        sus contactos y el historial de chats (hasta 6 meses).</li>
    <li><b>Ventas del negocio</b>: catálogo de productos (con fotos), carritos, pedidos, cotizaciones en PDF y el estado del pago.</li>
    <li><b>Pagos con tarjeta</b> (si el negocio cobra con Pago Plux o Payphone): los datos de la tarjeta se escriben en la página de pago, se cifran
        en el navegador del comprador y van directo a Pago Plux (o a Payphone). <b>No guardamos</b> el número, la fecha de vencimiento ni el CVV.
        Solo guardamos el resultado (aprobado o no), el número de transacción y el nombre, cédula y correo del titular que pide la pasarela.</li>
    <li><b>Llamadas de WhatsApp</b> (solo si el negocio las activa): la voz va directo entre el navegador de quien atiende y Meta y <b>no se graba</b>;
        guardamos el historial (quién llamó o a quién, cuándo, cuánto duró y cómo terminó) y el permiso para llamar que dio el cliente.</li>
    <li><b>Avisos en el celular</b>: si una persona del equipo activa los avisos, se guarda la suscripción de su navegador para mandarle el aviso de mensaje nuevo.</li>
    <li><b>Datos técnicos</b>: cookies de sesión necesarias para entrar (y una que indica que se usa la app), dirección IP y registros del servidor
        para seguridad. No usamos cookies de publicidad ni de seguimiento.</li>
</ul>
<p>La app no pide permisos del teléfono para ubicación, contactos o archivos. El micrófono (para grabar una nota de voz), la cámara o los archivos
solo se usan cuando la persona toca <i>Grabar</i>, <i>Adjuntar</i> o contesta una llamada, y el teléfono le pide permiso antes.</p>

<h2>Para qué los usamos</h2>
<ul>
    <li>Mostrar al negocio sus conversaciones, enviar las respuestas que el negocio escribe o configura (respuestas automáticas, chatbots, campañas a
        quien aceptó promociones, seguimientos), repartir chats en el equipo, llevar pedidos y cobros, y controlar el gasto en Meta.</li>
    <li>Seguridad de la cuenta y soporte cuando el negocio lo pide.</li>
    <li>No vendemos ni alquilamos datos. No los usamos para publicidad ni para perfilar a nadie.</li>
</ul>

<h2>Con quién se comparten</h2>
<p>No compartimos datos con terceros para sus propios fines. Solo intervienen, para dar el servicio que el negocio pide:</p>
<ul>
    <li><b>Meta (WhatsApp Business Platform)</b>: los mensajes y archivos se envían y se reciben a través de Meta, que los procesa según sus propias condiciones.</li>
    <li><b>Anthropic</b> (IA Claude, por API), solo si el negocio tiene el <b>Agente con IA</b> encendido o usa <i>Sugerir respuesta</i>, <i>Resumir</i>, <i>Pregúntale a la IA</i> o el resumen de cada mañana:
        recibe el texto de la conversación (o las cifras y listas que hacen falta) y lo que el negocio escribió en «Lo que sabe la IA» para preparar la respuesta. No usa estos datos para entrenar sus modelos.</li>
    <li><b>Pago Plux o Payphone</b>, solo para los cobros con tarjeta que el negocio activa.</li>
    <li><b>Shopify, WooCommerce, Make, Zapier o n8n</b>, solo si el negocio conecta su tienda o sus avisos a otros sistemas.</li>
    <li>Nuestros servidores en Ecuador y Cloudflare (conexión segura HTTPS).</li>
</ul>

<h2>Dónde se guardan y por cuánto tiempo</h2>
<ul>
    <li>En servidores de QF Producciones en Ecuador, con acceso protegido. Todo viaja cifrado (HTTPS); los tokens y las claves de cobro se guardan cifrados.
        Las fotos, audios y documentos se guardan fuera de la parte pública del servidor y solo los ve el equipo del negocio.</li>
    <li>Mientras el negocio use el servicio. Los registros técnicos de Meta se borran a los 30 días y las copias de respaldo a los 14 días.</li>
</ul>

<h2 id="borrar">Tus derechos y cómo borrar la cuenta o los datos</h2>
<p>Según la LOPDP puedes pedir acceso, corrección o eliminación de tus datos, u oponerte a su uso.</p>
<ol>
    <li><b>Si eres un negocio y quieres borrar tu cuenta y todos sus datos</b> (números conectados, contactos, mensajes, archivos, pedidos y tokens): escribe a
        <a href="mailto:info@tukansoftware.com?subject=Eliminar%20mis%20datos">info@tukansoftware.com</a> o al WhatsApp +593 99 640 8397 desde el correo o número
        registrado, con el asunto <b>«Eliminar mis datos»</b>. En un máximo de <b>15 días</b> lo borramos y te confirmamos por el mismo medio.
        Los pasos completos están en <a href="/eliminar-datos.php">Eliminar mis datos</a>.</li>
    <li><b>Si eres una persona del equipo</b> y quieres que se borre tu usuario: pídeselo al administrador de tu negocio (Mi cuenta → equipo) o escríbenos.</li>
    <li><b>Si escribiste a un negocio por WhatsApp</b>: pide al negocio que borre tu conversación, o escríbenos con tu número y el nombre del negocio y lo
        borramos en un máximo de 15 días. Para no recibir más promociones, responde <b>BAJA</b> al negocio.</li>
</ol>

<h2>Menores de edad</h2>
<p>El programa y la app son herramientas de trabajo para negocios y no están dirigidos a menores de 18 años.</p>

<h2>Cambios</h2>
<p>Si cambiamos esta política, publicamos aquí la nueva versión con su fecha.</p>
<?php
$contenido = (string) ob_get_clean();
require RAIZ . '/app/vistas/_publica.php';
