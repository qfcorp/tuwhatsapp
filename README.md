# WhatsApp Tukán

Bandeja propia de WhatsApp (como WhatChimp o Wati, pero nuestra, más barata y por módulos) conectada a la **API oficial de Meta**.
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

## Módulos (se encienden o apagan por cliente)
Lo básico (todo lo de arriba) siempre está. Lo demás son **módulos** que Tukán enciende en **Clientes de Tukán → ficha del cliente → Módulos del plan** (o con un toque en un plan). Lo que el cliente no tiene se ve en **Más** con la etiqueta *No incluido* y el botón **Pedir a Tukán**.

| Módulo | Qué hace | Dónde |
|---|---|---|
| Campañas masivas | Plantilla a muchos contactos, por etiqueta, con costo antes de enviar | Más → Campañas |
| Varios agentes | Asignar conversaciones, filtros Míos / Sin asignar | Chat → Atiende |
| **Embudo de ventas** | Tablero Nuevo → Interesado → Cotizado → Ganado / Perdido con el valor de cada venta. Quien escribe entra solo a Nuevo (los personales no) | Menú de abajo → Embudo, y en cada chat |
| **Seguimientos** | "Si no responde en 2 días, escribirle": mensajes programados. Si ya respondió no se manda; si pasaron 24 h queda en Pendientes para mandarlo gratis desde el celular | Chat → Programar seguimiento; Más → Seguimientos |
| **Reportes** | Personas que escribieron, contactos nuevos, mensajes por día, tiempo de respuesta del equipo, % atendidos, ventas ganadas, por persona del equipo | Más → Reportes |
| **Asistente con IA** | ✨ Sugerir respuesta (deja un borrador, siempre lo manda una persona) y Resumir. Usa lo que el negocio escribe en "Lo que sabe la IA" y la IA compartida de Tukán (tope $3 al mes por cuenta, el cliente no lo ve) | Chat; Más → Asistente con IA |
| **Enlaces, QR y botón web** | Enlace corto por cada lugar (Instagram, volante…) que abre WhatsApp con un texto listo y **cuenta los clics**; su código QR para imprimir; botón flotante para la página web | Más → Enlaces y QR |
| **Integraciones** | Descargar contactos y mensajes a Excel; avisar a otro programa (Make, Zapier, n8n) cuando llega un mensaje, firmado con clave | Más → Integraciones |

### Planes sugeridos (un toque en la ficha del cliente)
| Plan | Precio | Números | Usuarios | Módulos |
|---|---|---|---|---|
| Básico | $19/mes | 1 | 3 | Lo básico + Enlaces y QR |
| Negocio | $35/mes | 1 | 5 | + Campañas, Varios agentes, Embudo, Seguimientos, Reportes |
| Pro | $55/mes | 2 | 10 | Todo, con IA e Integraciones |
Precios sin IVA, **mes a mes sin contrato**, **contactos ilimitados** y **0 % de recargo**: lo de Meta lo paga el cliente directo con su tarjeta en su cuenta de Meta. Los precios están en `src/Modulos.php` (PLANES) y se pueden cambiar.

**Comparación (octubre 2026):** WhatChimp $40 (2 usuarios), Wati $69 (3 usuarios y recargo sobre Meta), Callbell $15–20 **por usuario**, Kommo $25–45 **por usuario** con pago de 6 meses, Leadsales $97, Whaticket $49, Respond.io $99 sin campañas en el plan barato. Lo que más reclaman sus clientes: cobro por usuario, recargo escondido sobre Meta, límites de contactos, funciones quitadas del plan barato y contratos largos. Nosotros no tenemos ninguno de esos.

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

## Asistente con IA (usa la IA compartida de Tukán)
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
