# WhatsApp Tukán

Bandeja propia de WhatsApp (como WhatChimp, Wati o Mercately, pero nuestra, más barata y con todo incluido) conectada a la **API oficial de Meta**.
Sirve para el número de ventas de Tukán y, después, para QF Producciones en el mismo programa (cada número con sus propias reglas, contactos y tope).

Dirección: **whatsapp.tukanec.com** (servidor qfcorp, por el túnel de Cloudflare).

## Qué hace
- **Bandeja**: todas las conversaciones del número, con sin leer, filtros (clientes, desconocidos, personales) y búsqueda. Se actualiza sola.
- **Avisos de mensaje nuevo**: número en la pestaña del navegador, círculo rojo en el ícono, sonido y aviso del navegador (botón 🔔 en la Bandeja; al tocarlo abre el chat).
- **Chat** con dos botones: **Enviar desde el celular (gratis)** abre WhatsApp con el texto listo; **Enviar por el sistema** (pagado, se ve el costo). Pasadas 24 horas del último mensaje del cliente, Meta solo deja enviar **plantillas aprobadas**.
- **Respuestas automáticas** con reglas (la primera que dice "no" gana):
  1. **Personal: nunca** (siempre activa).
  2. **Solo clientes registrados**.
  3. **Solo fuera de mi horario**.
  4. **Solo si habla de negocio** (palabras clave, sin importar tildes ni mayúsculas).
  5. **Una sola respuesta**: el menú una vez; después solo las opciones elegidas; una opción puede "pasar a una persona" (deja de contestar solo).
  Cada mensaje que llega muestra **por qué** se contestó o no. Hay un **probador** de reglas que no envía nada.
- **Tope de gasto mensual** (por defecto $12): antes de cada envío pagado se suma lo gastado del mes; si se pasa, **no se envía** y se ofrece mandarlo gratis desde el celular. Aviso al 80 % (o el % que elijas) y al 100 %, arriba en el panel y por Telegram (opcional).
- **Traer contactos del celular** (Contactos): botón **Elegir de la agenda del celular** (Android con Chrome) o **Subir archivo de contactos** (.vcf de Android o iPhone, .vcf o CSV de Google). La pantalla explica cómo exportarlos en cada celular. Los fijos se saltan solos y, por defecto, no se cambian los contactos que ya estaban.
- **Pendientes**: lista de mensajes para mandar gratis desde el celular, uno por uno. Si el número está también en la app del celular (coexistencia), al enviarlo desde el celular se marca solo.
- **Gratis automático**: si el cliente llegó desde un anuncio "Enviar WhatsApp", 72 horas sin cobro; las plantillas de utilidad con la ventana abierta tampoco se cobran.
- **Conectar con WhatsApp Business (código QR)**: el número sigue en la app del celular y además aparece aquí, con contactos e historial (hasta 6 meses).
- **Fotos, audios, videos y documentos** en el chat: se ven y se escuchan en el panel; se envían con **📎 Adjuntar** y se graban **🎤 notas de voz** desde el navegador (se pasan solas al formato de WhatsApp con ffmpeg).
- **Menú con botones de WhatsApp**: las opciones de la respuesta automática salen como botones (hasta 3) o como lista (hasta 10). El cliente toca en lugar de escribir el número.
- **API** para otros programas (TuSalón, Panel Tukán Central): agregar pendientes y registrar clientes.

## Plan: todo incluido (desde el 28 oct 2026 ya no va por módulos)
Todo viene en **un solo plan**. Lo único que se cobra aparte es el **agente con IA**.

| Plan | Precio | Números | Usuarios | Incluye |
|---|---|---|---|---|
| Todo incluido | unos $20/mes | 1 | 50 | Todo lo de esta página, menos el agente con IA |
| Todo incluido + IA | unos $25/mes | 1 | 50 | Todo + agente con IA que contesta solo, ✨ Sugerir respuesta y Resumir |

Los precios están en `src/Modulos.php` (PLANES y PRECIOS) y se cambian ahí. Las cuentas que tenían los planes viejos (Básico, Negocio, Pro) pasan solas a "Todo incluido" o a "Todo incluido + IA" según tuvieran IA. En **Más**, si la cuenta no tiene IA, el agente aparece con la etiqueta *Extra* y el botón **Pedir a Tukán**. En la web, las presentaciones y los manuales **no se publican precios** (regla de la marca): se pide cotización.

### Lo que trae el plan (dónde está)
| Función | Qué hace | Dónde |
|---|---|---|
| Campañas masivas | Plantilla a muchos contactos, por etiqueta, con el costo antes de enviar | Más → Campañas |
| Varios agentes | Asignar conversaciones, filtros Míos / Sin asignar | Chat → Atiende |
| **Reparto automático** | A mano, por turnos o a quien tenga menos chats; reglas por tema (palabras clave → persona); cada persona se marca *disponible*; el supervisor ve quién tiene cuántos chats esperando y pasa los chats de una persona a otra | Más → Equipo y reparto |
| **Catálogo** | Productos o servicios con foto, precio, stock, código y descripción; se cargan uno por uno o con un CSV de Excel (nombre, precio, stock, código, descripción). El cliente escribe "catálogo" y elige tocando de una lista | Más → Catálogo |
| **Carrito y pedidos** | Desde el chat se agregan productos al carrito y se manda el resumen con cómo pagar. Estados: carrito → pendiente de pago → pagado → enviado → entregado (o cancelado). El stock se descuenta solo y vuelve si se cancela | Chat → Carrito y cotización; menú de abajo → Pedidos (o Más → Pedidos) |
| **Cobros** | **Pago Plux** (tarjeta): página de pago por pedido (`/pagar.php?p=…`) con el valor exacto, OTP y 3D Secure; el pedido pasa solo a *Pagado*, al cliente le llega la confirmación y al equipo un aviso; aviso de pago de Plux en `/plux.php`. También **Payphone** (enlace por pedido), un enlace fijo o los datos de transferencia; IVA del negocio. Guía para el cliente: [docs/GUIA_PLUX.md](docs/GUIA_PLUX.md) | Más → Cobros y cotizaciones |
| **Cotización en PDF** | Con nombre, RUC, dirección, número, validez y condiciones al pie; se manda por el chat o se descarga | Chat → Mandar cotización PDF |
| **Chatbots por pasos** | Mensaje, botones (hasta 3, o lista hasta 10), pregunta (guarda nombre, correo, notas o un campo propio) y pasar a una persona; etiquetas y etapa del embudo por opción. Se encienden con palabras clave o con el primer mensaje | Más → Chatbots |
| **Campos propios** | Datos extra de cada contacto (texto, número, fecha o lista de opciones) que se llenan en el chat o con un chatbot y salen en el Excel | Más → Campos de contactos |
| **Origen por anuncio** | Si el cliente llega desde un anuncio de clic a WhatsApp, el chat dice de qué anuncio y el reporte cuenta cuántos trajo cada uno | Chat y Más → Reportes |
| Embudo de ventas | Nuevo → Interesado → Cotizado → Ganado / Perdido con el valor de cada venta | Menú de abajo → Embudo |
| Seguimientos | "Si no responde en 2 días, escribirle" | Chat → Programar seguimiento |
| Reportes | Personas que escribieron, mensajes, tiempo de respuesta, pedidos pagados, anuncios | Más → Reportes |
| Enlaces, QR y botón web | Enlace corto que cuenta los clics, su QR y botón para la página web | Más → Enlaces y QR |
| Integraciones | Excel de contactos y mensajes; aviso firmado a Make, Zapier o n8n | Más → Integraciones |
| **Tienda en línea** | **Shopify** o **WooCommerce**: cada pedido le avisa al cliente por WhatsApp (pedido recibido, enviado) con plantillas aprobadas; con Shopify, recordatorio del carrito abandonado (lo manda el cron cada minuto) | Más → Tienda en línea |
| **App en el celular** | Se instala desde el navegador (Android, iPhone, computadora) y avisa cada mensaje nuevo aunque esté cerrada (Web Push); el aviso le llega a quien tiene el chat | Más → App en el celular |

### El extra: agente con IA (`ia`)
- Contesta solo con lo que el negocio escribe en **Lo que sabe la IA** y con el **catálogo** (precios y stock). Siempre o solo fuera del horario; con nombre propio y un máximo de respuestas al día por persona.
- Puede armar el carrito y, cuando el cliente confirma, deja el pedido en *pendiente de pago* y le manda el resumen con cómo pagar.
- Pasa la conversación a una persona del equipo (con el reparto) si se la piden, si el cliente está molesto o si no sabe la respuesta.
- **Se calla** cuando alguien del equipo contesta (desde el panel o desde el celular), por las horas de silencio de Respuestas automáticas.
- Si la IA falla o está fuera del horario elegido, siguen las respuestas automáticas de siempre.
- Orden de lo que contesta: chatbot en curso → catálogo (palabras "catálogo", "comprar"…) → chatbot que se enciende → agente con IA → respuestas automáticas.

### Configurar lo nuevo (una vez por número)
1. **Más → Cobros y cotizaciones**: RUC, dirección, IVA (15 o 0), datos de transferencia y la tarjeta: **Pago Plux** (los 4 datos que da Plux; pasos en [docs/GUIA_PLUX.md](docs/GUIA_PLUX.md)) o el token y Store ID de **Payphone**.
2. **Más → Catálogo**: agrega productos o sube el CSV.
3. **Más → Equipo y reparto**: elige cómo se reparten los chats y quién está disponible.
4. **Más → Chatbots**: crea el primero (ya trae un ejemplo de 3 pasos) y enciéndelo.
5. **Más → Agente con IA** (si lo contrató): escribe lo que sabe del negocio y enciéndelo.
6. **Más → Tienda en línea** (si tiene Shopify o WooCommerce): copia la dirección y la clave que muestra la pantalla en el webhook de la tienda, y elige las plantillas.
7. Cada persona del equipo: **Más → App en el celular** → instalar y **Activar avisos**.


## Idiomas (botón 🌐)

Arriba a la derecha está el botón **🌐**: el programa se usa en **español, inglés, francés, portugués o italiano**.
También aparece en la pantalla de entrar y en las páginas públicas.

- Cada persona elige su idioma. Queda guardado en su cuenta y en ese navegador.
- **Lo que escriben los clientes no se traduce**: mensajes, nombres, notas y plantillas se ven tal como se escribieron.
- El botón **✨ Sugerir respuesta** de la IA contesta **en el idioma en que escribe el cliente**. El **Resumen** sale en el idioma del panel.
- Funciona con la pieza compartida **Tukán Idiomas** (`src/tukan_idiomas.php`, copia de `tukan-central/compartido/tukan_idiomas.php`).
  Los diccionarios están en `idiomas/en.php`, `fr.php`, `pt.php` e `it.php`. Lo que no tenga traducción se queda en español.
- Las páginas legales (términos, privacidad, tratamiento de datos) siguen en español: falta una traducción revisada por un abogado.

## Costos (Ecuador, editables en Gasto → Precios de Meta)
| Tipo | Precio por mensaje |
|---|---|
| Respuesta (servicio) | $0,0113 |
| Utilidad | $0,0113 (gratis con la ventana de 24 h abierta) |
| Autenticación | $0,0113 |
| Marketing | $0,074 |
Recibir mensajes y enviar desde la app del celular **no se paga**. Con $12: unas 1.000 respuestas o 160 de marketing.

---

## Instalación paso a paso

### Paso 1. Instalar en el servidor qfcorp
Entra por SSH al servidor qfcorp (el mismo de TuSalón) y pega **una sola línea**:
```
curl -fsSL https://raw.githubusercontent.com/qfcorp/tuwhatsapp/main/deploy/instalar.sh -o /tmp/instalar_tuwhatsapp.sh && bash /tmp/instalar_tuwhatsapp.sh
```
Al final debe decir: tablas 9, extensiones 4, página de entrar 200, webhook 403.

Crea tu usuario (pide correo, nombre y contraseña):
```
php /var/www/tuwhatsapp/bin/crear_usuario.php
```

### Paso 2. Publicarlo en internet (Cloudflare)
1. Entra a https://dash.cloudflare.com
2. **Networking → Tunnels** → toca el túnel de qfcorp → **Routes** → **Add route** → **Published application**.
   (Panel viejo one.dash.cloudflare.com: **Networks → Tunnels** → túnel → **Public Hostname** → **Add a public hostname**.)
3. Subdomain: `whatsapp` · Domain: `tukanec.com` · Service URL: `http://localhost:80` → **Add route**.
4. Abre https://whatsapp.tukanec.com y entra con tu usuario.

### Paso 3. Meta (una sola vez) — ya hecho en la app "Tukan Mensajes"
App de Meta: **Tukan Mensajes** (App ID `2592670637899782`), portafolio **Qfproducciones** (negocio verificado), marcada como **Tech Provider**.
Configuración del botón QR ("Inicio de sesión con Facebook para empresas" → Configuraciones): **ES Config** (creada desde Casos de uso → Conectar en WhatsApp → Administrador de registro insertado; la de "Inicio de sesión → Configuraciones" con variación General NO sirve), ID `1865337081309109`
(token de usuario del sistema que **nunca vence**, permisos `whatsapp_business_messaging` y `whatsapp_business_management`).
En "Inicio de sesión con Facebook para empresas → Configurar" están `https://whatsapp.tukanec.com/` como URI de redirección y como dominio permitido del SDK de JavaScript.

1. **Guardar los datos de Meta en el servidor** (pega la línea; te pedirá pegar la **Clave secreta de la app**, que está en Meta → **Configuración de la app → Básica → Clave secreta de la app → Mostrar**):
   ```
   bash /var/www/tuwhatsapp/deploy/clave_meta.sh 2592670637899782 1865337081309109
   ```
2. **Webhook**: en Meta → **Casos de uso → Conectar en WhatsApp → Configuración** → **URL de devolución de llamada** y **Token de verificación**: pega los que muestra el panel en **Conectar número** (botón Copiar) → **Verificar y guardar**. Luego en **Campos del webhook** suscríbete a `messages`, `smb_message_echoes`, `history`, `smb_app_state_sync` y `account_update`.
3. **Política de privacidad** (la pide Meta para publicar): `https://whatsapp.tukanec.com/privacidad.php` · **Eliminación de datos**: `https://whatsapp.tukanec.com/eliminar-datos.php`. Van en Meta → **Configuración de la app → Básica**.
4. **Conectar el número**: en el panel → **Conectar número** → botón **Conectar con WhatsApp Business** → entra con tu Facebook, elige **Qfproducciones**, escribe el número, escanea el **código QR** desde WhatsApp Business en el celular y acepta compartir el historial → **Finalizar**. El programa guarda todo solo y pide a Meta los contactos y el historial (hasta 6 meses).
   - Con esto el número **sigue funcionando en la app del celular** (coexistencia) y lo que mandas desde el celular aparece en el chat, gratis.
   - Meta deja traer contactos e historial **una sola vez y dentro de 24 horas**. Deja la app abierta en el celular mientras llegan.
   - Si el número NO está en la app del celular (chip nuevo), el mismo botón lo registra con un PIN de 6 números que el panel te muestra una vez.
5. **Método de pago**: https://business.facebook.com → **WhatsApp Manager → Configuración de pago** → agrega la tarjeta (de preferencia una virtual con límite de $15).
6. Escribe al número desde otro celular: el mensaje debe aparecer en la Bandeja.

**Conectar a mano** (sigue disponible, abajo en Conectar número): Phone number ID, WABA ID y token permanente de un usuario del sistema.

**Mientras Meta no publique la app**: solo llegan webhooks de prueba; los mensajes reales llegan cuando la app está **Publicada**. Para clientes de otros negocios (Tech Provider) Meta pide además la **verificación de acceso** y la **revisión de la app**.

### Paso 4. Reglas
En **Automático**: revisa horario, palabras clave y el menú, prueba con el **probador**, enciende **Contestar automáticamente** y guarda. En **Contactos → Traer contactos del celular** trae a tu familia y amigos como **Personales**.

---

## Cuentas por cliente (para vender el servicio)
- Cada **empresa** (cliente) tiene sus usuarios y sus números; solo ve lo suyo (contactos, mensajes, pendientes, reglas, plantillas y gasto).
- **Personal de Tukán** (usuarios creados en el servidor con `bin/crear_usuario.php`): ícono del **edificio** arriba → **Clientes de Tukán**: crear cliente (demo con fecha o activa), su primer usuario (la contraseña se muestra una sola vez), números y usuarios permitidos, suspender con motivo, notas internas y **Entrar a su cuenta (soporte)**.
- **Administrador** del cliente: configura todo y en **Mi cuenta** (ícono de la persona) agrega a su equipo (administradores o agentes) y crea su token de API.
- **Agente**: atiende Bandeja, Pendientes, Contactos, Embudo y Seguimientos (no ve Reportes, Integraciones, IA ni configuración).
- Cuenta **suspendida o vencida**: no entra al panel, no contesta sola y no envía; los mensajes que llegan se siguen guardando.
- Un número no se puede conectar en dos cuentas, y se respeta el máximo de números del plan (también con el botón QR).

## Campañas masivas
- Menú **Campañas** (solo administradores): eliges una plantilla aprobada, qué va en cada `{{n}}` (nombre del contacto o un texto fijo), a quién (clientes, o clientes y desconocidos; con una etiqueta) y cuántos por minuto. Antes de enviar muestra cuántos contactos, el costo y cuánto queda del tope.
- Nunca llega a Personales ni a quien pidió la baja. Por defecto solo a quien **aceptó promociones** (se marca en el chat, al importar contactos o por la API con `"promociones": true`).
- Si alguien responde **BAJA**, **STOP** o **No más**, sale solo de las campañas.
- Se envía poco a poco desde `bin/campanias.php` (cron de cada minuto que pone el instalador). Si llega al tope del mes, la campaña se **pausa sola**; subes el tope y la reanudas.
- Muestra enviados, leídos, quién respondió, fallidos (con el motivo) y costo.

## Varios agentes
- En cada chat: **Atiende** (elegir a la persona del equipo) o **Atender yo**. Al responder desde el panel, una conversación sin dueño pasa a quien respondió.
- Bandeja: filtros **Míos** y **Sin asignar**; cada conversación muestra quién la atiende.
- **Respuestas rápidas** (Automático → Respuestas rápidas, o "Editar" en el chat): textos guardados que se pegan con un toque. Los agentes las usan; los administradores las crean.

## Conectar con el Panel Tukán Central (protocolo v2: demos desde el panel)
1. En panel.tukanec.com → **Programas** → **Agregar programa** → elige **WhatsApp Tukán**, ID `tuwhatsapp`, dirección `https://whatsapp.tukanec.com`.
2. Copia el comando que te da y pégalo en el servidor (PuTTY de qfcorp). Es así:
   `sudo bash /var/www/tuwhatsapp/deploy/conectar_central.sh tuwhatsapp <SECRETO> https://panel.tukanec.com` → debe terminar en `OK: protocolo v2`.
3. En el panel toca **Probar conexión**. Con eso el panel lee las **capacidades** y WhatsApp Tukán aparece en **Crear demo**, **Clientes** y **Vencimientos**.
   Si ya estaba conectado y solo actualizaste el programa, basta con tocar **Probar conexión**.
- Opcional: `TUKAN_SOLO_CENTRAL=1` en `config/.env` hace que el personal de Tukán solo entre desde el panel central (los clientes entran igual).

Qué se puede hacer desde el panel (cada cliente es una **empresa**; Tukán no cuenta como cliente):

| Desde el panel | Qué hace en WhatsApp Tukán |
|---|---|
| **Crear demo** | Crea la empresa en **demo** hasta hoy + los días elegidos (7 por defecto, máximo 90), con su administrador y una contraseña que se ve **una sola vez** (botón *Enviar por WhatsApp*). Plan Pro con **todos los módulos**, o los que se marquen. Usa las mismas funciones que *Crear cliente nuevo* de Clientes de Tukán. No manda correo (el programa no envía correos). |
| **Alargar demo** | Suma días desde el fin de la demo (o desde hoy si ya terminó). No se puede con cuentas de pago ni suspendidas. |
| **Suspender / Reactivar** | Suspende con motivo (el cliente lo ve al entrar; los mensajes que llegan se guardan). Reactivar la deja como estaba (demo o activa). |
| **Nueva clave del dueño** | Contraseña nueva para el administrador principal (la anterior deja de servir). |
| **Cambiar plan**, **Módulos**, **Tope de IA**, **Nota interna** | Lo mismo que la ficha del cliente: plan Básico/Negocio/Pro, encender o apagar módulos (el precio del mes se recalcula), tope de IA y notas que el cliente no ve. |
| Clientes, ficha, Vencimientos | Estado con la regla única del panel (demo, al día, vence en 7 días, vencida, suspendida), plan, módulos y su precio del mes, contacto, uso (números, usuarios, mensajes, contactos) y lo que hizo el panel. |
| **Entrar** | Abre WhatsApp Tukán ya dentro, directo en la ficha de ese cliente. |
| **IA** | Gasto de IA de cada cliente en el mes. |

- Cada operación lleva una clave (`operacion_id`): si el panel reintenta, no se crea una demo dos veces. Se guarda en `tukan_central_operaciones` **sin contraseñas**.
- Lo que hace el panel queda en la ficha del cliente → *Desde el Panel Tukán Central* (quién, qué y cuándo).
- Los precios de los módulos sueltos (encender uno fuera del plan) están en `src/Modulos.php` (`PRECIOS`) y son **provisionales**.
- No hay cobros dentro del programa (los pagos se anotan en el panel), ni pedidos de demo, ni tickets: por eso no tiene *Registrar pago*, *Finanzas* ni *Solicitudes*.

## Términos y condiciones
- Páginas públicas: `/terminos.php` (qué paga el cliente a Tukán y a Meta, reglas de uso, suspensión, ley de Ecuador) y `/tratamiento-datos.php` (acuerdo de encargado de tratamiento según la LOPDP).
- El administrador de cada cliente los acepta al entrar la primera vez (se guarda quién, cuándo y desde qué IP). Si se cambian, se sube `Terminos::VERSION` y se vuelven a pedir. Tukán ve en la ficha del cliente si ya aceptó.
- Son un borrador hecho con cuidado, pero conviene que los revise un abogado antes de vender en serio.

## API para otros programas
Cabecera `Authorization: Bearer <token>`: el token de cada cliente (Mi cuenta → Crear token) o, para Tukán, `TUWA_API_TOKEN` de `/var/www/tuwhatsapp/config/.env`. Cuerpo JSON.
```
POST https://whatsapp.tukanec.com/api.php?accion=pendiente
{"telefono":"0991234567","nombre":"Salón Bella","texto":"Tu demo vence mañana","origen":"tusalon","linea":1}

POST https://whatsapp.tukanec.com/api.php?accion=contacto
{"telefono":"0991234567","nombre":"Salón Bella","tipo":"cliente"}
```
`linea` es opcional (por defecto el primer número). Respuestas: 200 `{"ok":true,...}`, 401 token malo, 422 dato malo.

## Agente y asistente con IA (usa la IA compartida de Tukán)
WhatsApp Tukán **no tiene clave propia**: usa la misma clave de Anthropic que todos los programas Tukán, guardada una sola vez en `/etc/tukan-ia.env` (la librería `src/tukan_ia.php` es copia exacta de `tukan-central/compartido/tukan_ia.php`).
- Si ya se configuró en el Panel Tukán Central, no hay que hacer nada: el instalador dice `9) Asistente con IA: usa la clave compartida de Tukan`.
- Si dice `apagado`, en el PuTTY de qfcorp (usuario qf) pega esta línea; toma la clave que ya está en TuHospedaje, sin pedirla ni mostrarla:
  `cd /var/www/tukan-central && git pull && bash deploy/configurar_ia.sh --usar-clave-tuhospedaje` → debe decir `IA: FUNCIONA`.
- Pausar toda la IA de todos los programas: `bash /var/www/tukan-central/deploy/configurar_ia.sh --pausar`. Tope para todo WhatsApp: `--tope tuwhatsapp 20`.
- Tope por cuenta: `empresas.ia_tope` (por defecto $3 al mes). El cliente nunca ve "límite" ni "cupo": si no se puede, sale el mensaje neutro de la librería. Tukán ve el gasto de cada cuenta en Más → Asistente con IA (entrando como soporte).
- Cada sugerencia cuesta unos $0,0002 (Haiku 5.5): $3 alcanzan para miles.

## Agregar QF Producciones después
En el panel → ícono del celular arriba → **Conectar otro número** → botón **Conectar con WhatsApp Business** con el número de QF (la misma app de Meta). Arriba aparece un selector para cambiar de número.

## Actualizar
La misma línea del Paso 1 (no borra nada).

## Respaldo
Cada día a las 3:40 en `/var/backups/tuwhatsapp` (14 días). Restaurar: `sudo -u postgres pg_restore -d tuwhatsapp --clean archivo.dump`.

## Pruebas (para programar)

Idiomas: `php tests/prueba_idiomas.php` (motor + diccionarios). Para encontrar textos sin traducir: correr las pruebas con
`TUWA_IDIOMA_PRUEBA=en TUWA_IDIOMA_FALTANTES=/tmp/faltan.txt` y revisar ese archivo.

```
PGPORT=5432 PGUSER=postgres bash tests/correr.sh
```
Meta, Telegram, la IA y el programa que recibe avisos están simulados (`tests/meta_simulado.php`). Idiomas 43, lógica 446, Panel Tukán Central v2 162 y pantallas 212: deben terminar en `TODO BIEN`.

## Archivos
- `public/index.php` panel · `public/webhook.php` lo que avisa Meta · `public/api.php` API
- `src/Registro.php` botón QR (Embedded Signup: código → token, webhook, PIN, contactos e historial)
- `public/privacidad.php` y `public/eliminar-datos.php` páginas públicas para Meta
- `src/Reglas.php` decisión de responder · `src/Mensajeria.php` envíos con tope · `src/Gasto.php` precios y gasto · `src/Webhook.php` mensajes, estados y ecos · `src/Meta.php` conexión con Meta
- `src/Modulos.php` módulos y planes · `src/Archivos.php` fotos, audios y documentos · `src/Embudo.php` · `src/Seguimientos.php` · `src/Reportes.php` · `src/Asistente.php` (IA) · `src/Enlaces.php` · `src/Integraciones.php`
- `public/w.php` enlace corto · `public/boton.js` botón para páginas web · `public/assets/qrcode.js` códigos QR (MIT, Kazuhiko Arase)
- `src/TukanCentral.php` (v1: entrar y cifras) y `src/TukanCentralV2.php` (v2: demos, clientes, módulos) · `public/tukan_central.php` conector · `tests/prueba_central_v2.php`
- `db/schema.sql` base (se puede correr muchas veces) · `deploy/instalar.sh` instalador · `deploy/clave_meta.sh` clave de Meta · `src/tukan_ia.php` IA compartida (no editar: se copia del Panel Tukán Central)
