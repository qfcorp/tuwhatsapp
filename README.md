# WhatsApp Tukán

Bandeja propia de WhatsApp (como WhatChimp, pero nuestra y sin mensualidad) conectada a la **API oficial de Meta**.
Sirve para el número de ventas de Tukán y, después, para QF Producciones en el mismo programa (cada número con sus propias reglas, contactos y tope).

Dirección: **whatsapp.tukanec.com** (servidor qfcorp, por el túnel de Cloudflare).

## Qué hace
- **Bandeja**: todas las conversaciones del número, con sin leer, filtros (clientes, desconocidos, personales) y búsqueda. Se actualiza sola.
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
- **API** para otros programas (TuSalón, Panel Tukán Central): agregar pendientes y registrar clientes.

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
- **Agente**: solo atiende Bandeja, Pendientes y Contactos.
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

## API para otros programas
Cabecera `Authorization: Bearer <token>`: el token de cada cliente (Mi cuenta → Crear token) o, para Tukán, `TUWA_API_TOKEN` de `/var/www/tuwhatsapp/config/.env`. Cuerpo JSON.
```
POST https://whatsapp.tukanec.com/api.php?accion=pendiente
{"telefono":"0991234567","nombre":"Salón Bella","texto":"Tu demo vence mañana","origen":"tusalon","linea":1}

POST https://whatsapp.tukanec.com/api.php?accion=contacto
{"telefono":"0991234567","nombre":"Salón Bella","tipo":"cliente"}
```
`linea` es opcional (por defecto el primer número). Respuestas: 200 `{"ok":true,...}`, 401 token malo, 422 dato malo.

## Agregar QF Producciones después
En el panel → ícono del celular arriba → **Conectar otro número** → botón **Conectar con WhatsApp Business** con el número de QF (la misma app de Meta). Arriba aparece un selector para cambiar de número.

## Actualizar
La misma línea del Paso 1 (no borra nada).

## Respaldo
Cada día a las 3:40 en `/var/backups/tuwhatsapp` (14 días). Restaurar: `sudo -u postgres pg_restore -d tuwhatsapp --clean archivo.dump`.

## Pruebas (para programar)
```
PGPORT=5432 PGUSER=postgres bash tests/correr.sh
```
Meta y Telegram simulados (`tests/meta_simulado.php`). Lógica 162 y pantallas 77, deben terminar en `TODO BIEN`.

## Archivos
- `public/index.php` panel · `public/webhook.php` lo que avisa Meta · `public/api.php` API
- `src/Registro.php` botón QR (Embedded Signup: código → token, webhook, PIN, contactos e historial)
- `public/privacidad.php` y `public/eliminar-datos.php` páginas públicas para Meta
- `src/Reglas.php` decisión de responder · `src/Mensajeria.php` envíos con tope · `src/Gasto.php` precios y gasto · `src/Webhook.php` mensajes, estados y ecos · `src/Meta.php` conexión con Meta
- `db/schema.sql` base (se puede correr muchas veces) · `deploy/instalar.sh` instalador
