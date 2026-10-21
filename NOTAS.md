# WhatsApp Tukán — Estado y pasos para continuar
*Actualizado: 21 oct 2026, 12:30*

## Qué es
Programa propio tipo WhatChimp (sin mensualidad) conectado a la **API oficial de WhatsApp de Meta**, para el número de ventas de Tukán (+593 99 640 8397) y después QF Producciones (+593 98 683 3077) en el mismo programa.

- Repositorio: https://github.com/qfcorp/tuwhatsapp (rama `main`)
- Dirección: **https://whatsapp.tukanec.com** (ya funciona)
- Servidor: **qfcorp** (usuario `qf`), túnel de Cloudflare `servidorqf` → `http://localhost:80`
- Tecnología: PHP + PostgreSQL + nginx
- Pruebas (Meta simulado): **lógica 173 y pantallas 77, todo bien**

## Qué hace el programa
- **Bandeja** y **chat** con 2 botones: "Enviar desde el celular" (gratis) y "Enviar por el sistema" (pagado, muestra el costo).
- **Respuestas automáticas** con reglas: Personales nunca · solo clientes · solo fuera de horario · solo si habla de negocio · una sola respuesta. Cada mensaje dice por qué se contestó o no. Hay **probador**.
- **Tope de gasto mensual** ($12 por defecto) con avisos al 80 % y 100 %.
- **Pendientes** para enviar gratis desde el celular.
- **Contactos**: traer del celular (agenda Android o archivo .vcf/.csv).
- **Plantillas** de Meta (para escribir pasadas 24 horas).
- **API** para otros programas (TuSalón, Panel Central).
- **Botón "Conectar con WhatsApp Business (código QR)"**: el número sigue en la app del celular y además aparece aquí, con contactos e historial (hasta 6 meses).
- Páginas públicas: `/privacidad.php` y `/eliminar-datos.php` (las pide Meta).
- Varios números (Tukán y QF), cada uno con sus reglas, contactos y tope.

## Costos de Meta (Ecuador, editables en Gasto)
| Tipo | Por mensaje |
|---|---|
| Respuesta (servicio) | $0,0113 |
| Utilidad | $0,0113 (gratis con la ventana de 24 h abierta) |
| Autenticación | $0,0113 |
| Marketing | $0,074 |

Recibir mensajes y enviar desde la app del celular es **gratis**.

---

## Datos de Meta (no son secretos)
| Dato | Valor |
|---|---|
| App | **Tukan Mensajes** — App ID `2592670637899782` — **Publicada** |
| Portafolio | **Qfproducciones** (ID `474753276508128`) — negocio **verificado** |
| Tech Provider | **Independiente** — paso 1 (verificación del negocio) **Aprobado** · paso 2 (revisión de la app) **pendiente** |
| Configuración del botón QR | **ES Config** — ID `1865337081309109` (creada en Casos de uso → Conectar en WhatsApp → **Administrador de registro insertado**; token nunca vence) |
| Configuración vieja (NO sirve) | `1118315074244612` (variación "General", sin WhatsApp) — se puede borrar |
| Webhook | `https://whatsapp.tukanec.com/webhook.php` — verificado — campos: messages, smb_message_echoes, history, smb_app_state_sync, account_update |
| Número Tukán | +593 99 640 8397 → cuenta "tukansoftware" (ID `2250026202491413`), tipo **App de WhatsApp Business**, calidad Alta |
| Número QF | +593 98 683 3077 → cuenta "qfproducciones" (ID `3132653697006297`), tipo App de WhatsApp Business |
| Número de prueba de Meta | +1 555 675 0567 — Phone Number ID `1391982964001238` — cuenta `2501989233621271` |

En el servidor (`/var/www/tuwhatsapp/config/.env`) ya están: META_APP_ID, META_ES_CONFIG_ID=1865337081309109 y la clave secreta. ✅

---

## ✅ Hecho (21 oct 2026)
1. Programa instalado en qfcorp, usuario creado, publicado en Cloudflare.
2. App de Meta creada, publicada, con dominio, privacidad, eliminación de datos y categoría.
3. Webhook verificado y suscrito (la prueba de Meta llegó bien).
4. Registrado como **Tech Provider independiente**.
5. Botón QR programado, probado y funcionando: abre la ventana correcta de Meta ("Conecta tu cuenta fácilmente con Qfproducciones").
7. **Crear plantillas desde el panel** (ícono de hoja arriba): estado Aprobada / En revisión / Rechazada con motivo, vista previa, botones y borrar. Sirve para el video de la revisión de Meta.
6. **Número de prueba de Meta conectado** en el programa ("Prueba Meta") con token temporal de 24 h.

## ⚠️ Por qué todavía no está el número de Tukán
Meta responde **"Este número no cumple los requisitos para registrarse o migrar" (#3441034)** y **no ofrece** la opción de coexistencia (QR) para el +593 99 640 8397. Se probó de 3 formas (botón QR, Meta for Developers y crear cuenta de mensajes en el portafolio): Meta no deja usar ese número en la API por ahora.

Causa más probable: el número tiene **poco tiempo/actividad** en WhatsApp Business. Meta pide **mínimo 7 días** de uso real (ideal 1–2 meses), app actualizada (2.24.17 o más) y que no se borre ni reinstale.

**Decisión de Dimitry: opción B** → seguir usando el número de Tukán normal en el celular (es eSIM pagada, no se cambia) y **reintentar el botón QR en 1–2 semanas**.

---

## ⬜ LO QUE FALTA (en orden)

### 1. Probar con el número de prueba (hoy)
- En Meta → Paso 1. Pruébalo → "Envía un mensaje" → **Para** → agregar el celular personal y poner el código que llega.
- Desde el celular personal escribir a **+1 555 675 0567** "Hola, quiero precios" → debe aparecer en la **Bandeja**.
- Si no aparece, ver lo que llegó al servidor (PuTTY de **qfcorp**, no el del VPS):
  ```
  sudo -u postgres psql -d tuwhatsapp -c "SELECT id, recibido, left(cuerpo,150), error FROM webhook_log ORDER BY id DESC LIMIT 5"
  ```
- El token de prueba **vence en 24 h**: es solo para ver que todo funciona.

### 2. Mientras tanto (con el número de Tukán)
- Usarlo normal con clientes en **WhatsApp Business**, actualizar la app, **no borrar ni reinstalar**.

### 3. Reintentar el QR (en 1–2 semanas)
1. https://whatsapp.tukanec.com → **Conectar número** → **Conectar con WhatsApp Business**.
2. Ventana de Meta: Continuar → Qfproducciones → número 0996408397 → debe ofrecer **conectar la app WhatsApp Business** → escanear el QR con el celular → aceptar compartir historial → **Finalizar**.
3. El programa guarda todo solo y pide contactos e historial (Meta lo permite **1 vez y dentro de 24 h**).
- Opcional: probar antes con el número de **QF (098 683 3077)**, que puede tener más historial.

### 4. Pendientes en Meta
- Subir el **ícono** de la app (`icono_tukan_mensajes.png`, 1024×1024): Configuración de la app → Básica → Ícono de la app → Guardar cambios.
- **Método de pago** en WhatsApp Manager (tarjeta virtual con límite de $15) cuando el número real esté conectado.
- **Revisión de la app** (para vender a otros negocios): 2 videos — uno enviando un mensaje desde el programa y recibiéndolo en WhatsApp, y otro creando una plantilla.
- Borrar la configuración vieja `1118315074244612` (opcional).

### 5. Configurar el programa (cuando esté el número real)
1. **Contactos → Traer contactos del celular** (familia y amigos como **Personales**).
2. **Automático**: horario, palabras clave, menú; probar; encender **Contestar automáticamente**.
3. **Gasto**: tope $12, aviso al 80 %.

### 6. Después
- Conectar el número de **QF Producciones** (ícono del celular arriba → **Conectar otro número** → botón QR).
- Conectar TuSalón / Panel Tukán Central a la API (`/api.php`, token `TUWA_API_TOKEN` en `.env`).
- Ofrecer WhatsApp como **módulo extra** en los programas Tukán (ya somos Tech Provider).

---

## Comandos útiles (PuTTY de **qfcorp**, usuario qf)
Actualizar el programa (no borra nada):
```
curl -fsSL https://raw.githubusercontent.com/qfcorp/tuwhatsapp/main/deploy/instalar.sh -o /tmp/instalar_tuwhatsapp.sh && bash /tmp/instalar_tuwhatsapp.sh
```
Cambiar datos de Meta (Enter deja lo guardado):
```
bash /var/www/tuwhatsapp/deploy/clave_meta.sh 2592670637899782 1865337081309109
```
Crear o cambiar usuario del panel:
```
php /var/www/tuwhatsapp/bin/crear_usuario.php
```

## Datos técnicos
- Respaldo diario 3:40 am en `/var/backups/tuwhatsapp` (14 días).
- Archivos principales: `src/Registro.php` (botón QR), `src/Webhook.php`, `src/Reglas.php`, `src/Mensajeria.php`, `src/Gasto.php`, `deploy/instalar.sh`, `deploy/clave_meta.sh`.
- Pruebas: `PGPORT=5432 PGUSER=postgres bash tests/correr.sh` → debe terminar en `TODO BIEN`.
- Cuidado: hay dos ventanas de PuTTY; la del programa es **qf@qfcorp** (no `root@vps-...`).

## 21 oct 2026 (tarde): cuentas por cliente
- Hecho: empresas separadas (tabla `empresas`, `usuarios.empresa_id/rol/super/activo`, `lineas.empresa_id`). Lo que había quedó en la empresa 1 "Tukán Software" y el primer usuario es super.
- Panel Clientes de Tukán (ícono edificio, solo super), Mi cuenta (equipo, contraseña, token de API), cuenta suspendida/vencida bloqueada, soporte "Entrar a su cuenta".
- Pruebas: lógica 234, pantallas 108.
- Siguiente: campañas masivas con plantillas y varios agentes por chat; luego conectar con el Panel Tukán Central.
- Hecho: campañas masivas (tablas `campanias`, `campania_envios`; `contactos.marketing/etiquetas`; cron `bin/campanias.php`). Pruebas: lógica 272, pantallas 117.
- Hecho: varios agentes (contactos.asignado_a, filtros Míos/Sin asignar) y respuestas rápidas (tabla respuestas_rapidas). Pruebas: lógica 282, pantallas 129. Tablas: 13.
- Hecho: conector con el Panel Tukán Central v1 (public/tukan_central.php, src/TukanCentral.php, deploy/conectar_central.sh). Producto "tuwhatsapp". Tablas: 15. Pruebas: lógica 302, pantallas 134.
