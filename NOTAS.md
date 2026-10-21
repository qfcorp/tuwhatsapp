# WhatsApp Tukán — Estado y pasos para continuar

## Qué es
Programa propio tipo WhatChimp (sin mensualidad) conectado a la **API oficial de WhatsApp de Meta**, para el número de ventas de Tukán (+593 99 640 8397) y después QF Producciones en el mismo programa.

- Repositorio: https://github.com/qfcorp/tuwhatsapp (público, rama `main`, ya tiene todo el código)
- Dirección donde va a funcionar: https://whatsapp.tukanec.com
- Servidor: laptop/servidor **qfcorp** (el mismo de TuSalón), publicado con el túnel de Cloudflare
- Tecnología: PHP + PostgreSQL + nginx (igual que TuSalón)
- Pruebas: lógica 123 y pantallas 62, todas bien (Meta simulado)

## Qué hace el programa
- **Bandeja** de conversaciones y **chat** con 2 botones: "Enviar desde el celular" (gratis, abre WhatsApp con el texto listo) y "Enviar por el sistema" (pagado, muestra el costo).
- **Respuestas automáticas** con reglas (la primera que dice "no" gana):
  1. Contactos **Personales**: nunca se les contesta (siempre activa).
  2. **Solo clientes registrados**.
  3. **Solo fuera de mi horario**.
  4. **Solo si habla de negocio** (palabras clave).
  5. **Una sola respuesta** (menú una vez, luego solo opciones; una opción puede pasar a una persona).
  - Cada mensaje muestra por qué se contestó o no. Hay un **probador** de reglas.
- **Tope de gasto mensual** (por defecto $12): si se pasa, no envía nada pagado. Avisa al 80 % y 100 % (panel y Telegram opcional).
- **Pendientes**: lista para enviar gratis desde el celular, uno por uno.
- **Contactos**: traer del celular (botón de agenda en Android/Chrome, o subir archivo .vcf/.csv de Android, iPhone o Google). Por defecto entran como Personales y no cambia los que ya existen.
- **Plantillas** de Meta (para escribir pasadas 24 horas).
- **API** para otros programas (TuSalón, Panel Central): agregar pendientes y registrar clientes.
- Varios números (Tukán y QF), cada uno con sus reglas, contactos y tope.

## Costos de Meta (Ecuador, se pueden cambiar en Gasto)
| Tipo | Por mensaje |
|---|---|
| Respuesta (servicio) | $0,0113 |
| Utilidad | $0,0113 (gratis con la ventana de 24 h abierta) |
| Autenticación | $0,0113 |
| Marketing | $0,074 |

Recibir mensajes y enviar desde la app del celular es **gratis**. Con $12: unas 1.000 respuestas o 160 de marketing.

---

## LO QUE FALTA (en orden)

### ✅ Hecho
- Código terminado y subido a GitHub.
- Repositorio cambiado a público.
- 21 oct 2026: pruebas otra vez → lógica 123 bien, pantallas 62 bien.
- 21 oct 2026: instalador probado en un servidor simulado (Ubuntu + nginx + PHP-FPM + PostgreSQL): RESULTADO correcto, crear usuario LISTO, entrar al panel muestra "Conectar número", verificación del webhook responde bien, y correrlo 2 veces no borra nada.
- 21 oct 2026: el comando del Paso 1 ya no lleva tilde (se rompía al pegar desde el celular).
- 21 oct 2026: **Pasos 1, 2 y 3 HECHOS.** Programa instalado en qfcorp, usuario creado, y ruta publicada en Cloudflare (túnel `servidorqf` → `whatsapp.tukanec.com` → `http://localhost:80`). https://whatsapp.tukanec.com ya muestra la pantalla de entrar.
- 21 oct 2026: **Meta:** creada la app **"Tukan Mensajes"** (ID 2592670637899782) en el portafolio **Qfproducciones** (verificación del negocio YA completa), caso de uso WhatsApp. Meta no permite la palabra "WhatsApp" en el nombre de la app.
- 21 oct 2026: la app quedó marcada como **Tech Provider** (no se puede revertir). Faltan: verificación de acceso, revisión de la app, publicar la app (sin publicar, Meta NO manda mensajes reales al webhook).
- 21 oct 2026: al agregar +593 99 640 8397 Meta respondió *"no cumple los requisitos para registrarse o migrar"* (error 3441034): el número sigue activo en la app WhatsApp Business del celular. La lista solo ofrece "número nuevo" o "número virtual", no coexistencia.
- 21 oct 2026: **Dimitry eligió el plan B** (coexistencia: el número sigue en la app del celular).
- 21 oct 2026: **Programado y probado el botón "Conectar con WhatsApp Business (código QR)"** (Embedded Signup v5 de Meta): canje del código, suscripción del webhook, PIN solo si es número nuevo, y pide a Meta contactos + historial (1 vez, dentro de 24 h). El webhook ahora guarda `history` (sin contestar solo), `smb_app_state_sync` (contactos nuevos como Desconocido) y acepta `account_update`. Páginas públicas `privacidad.php` y `eliminar-datos.php`. Pruebas: lógica 162 y pantallas 77, todo bien. Subido a GitHub.
- 21 oct 2026: **Meta configurado:** "Inicio de sesión con Facebook para empresas" con `https://whatsapp.tukanec.com/` (URI de redirección y dominio del SDK). Configuración **"Conectar WhatsApp QR"** ID **1118315074244612** (token de usuario del sistema que nunca vence, permisos de WhatsApp). App ID **2592670637899782**.
- 21 oct 2026: Dimitry guardó App ID, Configuration ID y clave secreta en el servidor (LISTO). Webhook verificado y suscritos messages, history, smb_app_state_sync, smb_message_echoes y account_update (prueba de Meta llegó bien). Datos básicos de la app completos (dominio, privacidad, eliminación de datos, categoría). **App PUBLICADA** por Dimitry. Falta subir el ícono (icono_tukan_mensajes.png).
- 21 oct 2026: primer intento del botón QR: Meta terminó pero no mandó el número ("Meta no devolvió el número…"). Arreglado: si falta la cuenta se saca del permiso del token (debug_token) y si falta el número se busca en la cuenta (prefiere el que sigue en la app del celular); nunca registra un número a ciegas. Pruebas: lógica 173, pantallas 77.
- **ANTERIOR (ya hecho):** actualizar el programa (línea del Paso 1) y `bash /var/www/tuwhatsapp/deploy/clave_meta.sh 2592670637899782 1118315074244612` (pegar la Clave secreta de la app).
- **SIGUE (Meta):** webhook (URL + token de verificación del panel) y campos; política de privacidad en Configuración → Básica; probar el botón QR con el número de Tukán; publicar la app; verificación de acceso y revisión de la app (para clientes de otros negocios).

### ✅ Paso 1. Instalar en el servidor qfcorp (por SSH)
Pegar esta línea completa (como usuario qf, NO como root; pedirá la contraseña de sudo):
```
curl -fsSL https://raw.githubusercontent.com/qfcorp/tuwhatsapp/main/deploy/instalar.sh -o /tmp/instalar_tuwhatsapp.sh && bash /tmp/instalar_tuwhatsapp.sh
```
Al final, el cuadro RESULTADO debe decir: Tablas 9 · Extensiones 4 · Página de entrar 200 · Webhook 403 · Clave secreta de Meta: FALTA (normal).

### ✅ Paso 2. Crear el usuario del programa (por SSH)
```
php /var/www/tuwhatsapp/bin/crear_usuario.php
```
Pide correo, nombre y contraseña (mínimo 8). Debe decir `LISTO`.

### ✅ Paso 3. Publicarlo en Cloudflare (navegador)
1. https://dash.cloudflare.com → menú **Networking → Tunnels** → túnel de **qfcorp** → pestaña **Routes** → **Add route** → **Published application**.
   (En el panel viejo https://one.dash.cloudflare.com es: **Networks → Tunnels** → túnel → **Public Hostname** → **Add a public hostname**.)
2. Subdomain `whatsapp` · Domain `tukanec.com` · Service URL `http://localhost:80` → **Add route** (o **Save hostname**).
3. Abrir https://whatsapp.tukanec.com y entrar con el usuario del Paso 2. Debe salir **Conectar número**.

### ⬜ Paso 4. Meta (una sola vez)
1. https://developers.facebook.com/apps → **Crear app** → caso de uso **Conectarte con clientes a través de WhatsApp** → portafolio de Tukán/QF → **Crear app**.
2. **WhatsApp → Configuración de la API** → **Agregar número de teléfono** → número de Tukán → verificar con código.
   - ⚠️ **IMPORTANTE**: si Meta ofrece **conectar el número de la app WhatsApp Business** (coexistencia, con código QR), elegir esa opción. Así se sigue usando el celular y el botón "gratis" sale del mismo número.
   - Si NO aparece esa opción, registrarlo en la API **lo saca de la app del celular**. En ese caso conviene usar un **chip nuevo** solo para la API. Decidirlo ANTES de seguir.
3. Copiar el **Identificador del número de teléfono** (Phone number ID) y el **Identificador de la cuenta de WhatsApp Business** (WABA ID).
4. Token permanente: https://business.facebook.com/settings/system-users → **Agregar** (Administrador) → **Asignar activos** → la app con **Control total** → **Generar token** → vencimiento **Nunca** → permisos `whatsapp_business_messaging` y `whatsapp_business_management` → copiar el token.
5. Clave secreta: en la app → **Configuración de la app → Básica → Clave secreta de la app → Mostrar** → copiarla. En el servidor:
   ```
   bash /var/www/tuwhatsapp/deploy/clave_meta.sh
   ```
   (pide la clave; opcional el token de Telegram y el chat_id para avisos de gasto)
6. En https://whatsapp.tukanec.com → **Conectar número**: pegar los dos identificadores y el token → **Guardar y probar** → debe decir "Conectado con Meta".
7. Webhook: en la app de Meta → **WhatsApp → Configuración → Webhook → Editar** → pegar la **URL** y el **Token de verificación** que muestra el programa en la pantalla del número (botón Copiar) → **Verificar y guardar** → en **Campos del webhook** suscribirse a `messages` (y `smb_message_echoes` si hay coexistencia).
8. Pago: https://business.facebook.com → **WhatsApp Manager → Configuración de pago** → agregar tarjeta (mejor una virtual con límite de $15).
9. Escribir al número de Tukán desde otro celular: debe aparecer en la **Bandeja**.

### ⬜ Paso 5. Configurar el programa
1. **Contactos → Traer contactos del celular**: traer familia y amigos como **Personales**.
2. **Automático**: revisar horario, palabras clave y menú; probar con el **probador**; encender **Contestar automáticamente** y **Guardar reglas**.
3. **Gasto**: confirmar tope $12 y aviso al 80 %.

### ⬜ Después
- Agregar el número de **QF Producciones**: repetir el Paso 4 con ese número y en el programa → ícono del celular arriba → **Conectar otro número**.
- Conectar TuSalón / Panel Tukán Central a la API (`/api.php`, token `TUWA_API_TOKEN` en `/var/www/tuwhatsapp/config/.env`).

---

## Datos útiles
- Actualizar el programa más adelante: la misma línea del Paso 1 (no borra nada).
- Respaldo automático diario 3:40 am en `/var/backups/tuwhatsapp` (14 días).
- Configuración (no está en GitHub): `/var/www/tuwhatsapp/config/.env`.
- Archivos principales: `src/Reglas.php` (reglas), `src/Mensajeria.php` (envíos con tope), `src/Gasto.php`, `src/Webhook.php`, `src/LectorContactos.php`, `deploy/instalar.sh`.
- Pruebas: `PGPORT=5432 PGUSER=postgres bash tests/correr.sh` → debe terminar en `TODO BIEN`.
