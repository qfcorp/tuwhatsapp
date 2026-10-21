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
curl -fsSL https://raw.githubusercontent.com/qfcorp/tuwhatsapp/main/deploy/instalar.sh -o ~/instalar_tuwhatsapp.sh && bash ~/instalar_tuwhatsapp.sh
```
Al final debe decir: tablas 9, extensiones 4, página de entrar 200, webhook 403.

Crea tu usuario (pide correo, nombre y contraseña):
```
php /var/www/tuwhatsapp/bin/crear_usuario.php
```

### Paso 2. Publicarlo en internet (Cloudflare)
1. Entra a https://one.dash.cloudflare.com
2. **Networks → Tunnels** → toca el túnel de qfcorp → **Public Hostname** → **Add a public hostname**.
3. Subdomain: `whatsapp` · Domain: `tukanec.com` · Type: `HTTP` · URL: `localhost:80` → **Save**.
4. Abre https://whatsapp.tukanec.com y entra con tu usuario.

### Paso 3. Meta (una sola vez)
1. Entra a https://developers.facebook.com/apps → **Crear app**.
2. Caso de uso: **Conectarte con clientes a través de WhatsApp** → elige el portafolio comercial de Tukán/QF → **Crear app**.
3. En el menú de la app: **WhatsApp → Configuración de la API**. Toca **Agregar número de teléfono**, escribe el número de Tukán y verifícalo con el código.
   - Si Meta ofrece **conectar el número que ya usas en la app WhatsApp Business** (coexistencia, se escanea un código QR desde el celular), elige esa opción: así sigues usando el celular y el botón "gratis" sale del mismo número.
   - Si no la ofrece, registrar el número en la API lo **saca de la app del celular**. En ese caso conviene usar un **chip nuevo** solo para la API.
4. En la misma pantalla copia el **Identificador del número de teléfono** y el **Identificador de la cuenta de WhatsApp Business**.
5. Token permanente: https://business.facebook.com/settings/system-users → **Agregar** (rol Administrador) → **Asignar activos** → la app con **Control total** → **Generar token** → elige la app, vencimiento **Nunca**, permisos `whatsapp_business_messaging` y `whatsapp_business_management` → copia el token.
6. Clave secreta: en la app, **Configuración de la app → Básica → Clave secreta de la app → Mostrar** → cópiala. En el servidor pega:
   ```
   bash /var/www/tuwhatsapp/deploy/clave_meta.sh
   ```
7. En el panel (https://whatsapp.tukanec.com) → **Conectar número**: pega los dos identificadores y el token → **Guardar y probar**. Debe decir "Conectado con Meta".
8. Webhook: en la app de Meta, **WhatsApp → Configuración → Webhook → Editar**. Pega la **URL** y el **Token de verificación** que muestra el panel en la pantalla del número (tienen botón Copiar) → **Verificar y guardar**. Luego en **Campos del webhook** toca **Suscribirse** en `messages` (y en `smb_message_echoes` si usas coexistencia).
9. Método de pago: https://business.facebook.com → **WhatsApp Manager → Configuración de pago** → agrega la tarjeta (de preferencia una tarjeta virtual con límite de $15).
10. Escribe al número de Tukán desde otro celular: el mensaje debe aparecer en la Bandeja.

### Paso 4. Reglas
En **Automático**: revisa horario, palabras clave y el menú, prueba con el **probador**, enciende **Contestar automáticamente** y guarda. En **Contactos → Traer contactos del celular** trae a tu familia y amigos como **Personales**.

---

## API para otros programas
Cabecera `Authorization: Bearer <TUWA_API_TOKEN>` (está en `/var/www/tuwhatsapp/config/.env`). Cuerpo JSON.
```
POST https://whatsapp.tukanec.com/api.php?accion=pendiente
{"telefono":"0991234567","nombre":"Salón Bella","texto":"Tu demo vence mañana","origen":"tusalon","linea":1}

POST https://whatsapp.tukanec.com/api.php?accion=contacto
{"telefono":"0991234567","nombre":"Salón Bella","tipo":"cliente"}
```
`linea` es opcional (por defecto el primer número). Respuestas: 200 `{"ok":true,...}`, 401 token malo, 422 dato malo.

## Agregar QF Producciones después
Haz otra vez el Paso 3 con el número de QF (puede ser la misma app de Meta) y en el panel → ícono del celular arriba → **Conectar otro número**. Arriba aparece un selector para cambiar de número.

## Actualizar
La misma línea del Paso 1 (no borra nada).

## Respaldo
Cada día a las 3:40 en `/var/backups/tuwhatsapp` (14 días). Restaurar: `sudo -u postgres pg_restore -d tuwhatsapp --clean archivo.dump`.

## Pruebas (para programar)
```
PGPORT=5432 PGUSER=postgres bash tests/correr.sh
```
Meta y Telegram simulados (`tests/meta_simulado.php`). Lógica 123 y pantallas 62, deben terminar en `TODO BIEN`.

## Archivos
- `public/index.php` panel · `public/webhook.php` lo que avisa Meta · `public/api.php` API
- `src/Reglas.php` decisión de responder · `src/Mensajeria.php` envíos con tope · `src/Gasto.php` precios y gasto · `src/Webhook.php` mensajes, estados y ecos · `src/Meta.php` conexión con Meta
- `db/schema.sql` base (se puede correr muchas veces) · `deploy/instalar.sh` instalador
