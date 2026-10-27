# WhatsApp Tukán — Estado y pasos para continuar
*Actualizado: 27 oct 2026*

## Qué es
Programa propio tipo WhatChimp (sin mensualidad) conectado a la **API oficial de WhatsApp de Meta**, para el número de ventas de Tukán (+593 99 640 8397) y después QF Producciones (+593 98 683 3077) en el mismo programa.

- Repositorio: https://github.com/qfcorp/tuwhatsapp (rama `main`)
- Dirección: **https://whatsapp.tukanec.com** (ya funciona)
- Servidor: **qfcorp** (usuario `qf`), túnel de Cloudflare `servidorqf` → `http://localhost:80`
- Tecnología: PHP + PostgreSQL + nginx
- Pruebas (Meta simulado): **idiomas 43, lógica 446, Panel Central v2 162 y pantallas 212, todo bien** (27 oct 2026)

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

## 🧩 Panel Tukán Central v2: demos desde el panel (27 oct 2026)
**Problema:** en el Panel Tukán Central, *Crear demo* no mostraba WhatsApp Tukán porque el conector solo hablaba el protocolo v1 (entrar y cifras).

**Hecho** (`src/TukanCentralV2.php`, `src/TukanCentralError.php`, `public/tukan_central.php`, siguiendo el de TuLavadora):
- v1 sigue igual (`sso`, `estado`), ahora con `codigo` en los errores (y cabecera `X-Tukan-Codigo` en el SSO), `estado` dice `protocolo_max: 2`
  y el SSO acepta `ir` (enlace directo a la ficha: solo `/?r=cliente…`; uno malo abre Clientes de Tukán).
- Acciones v2: `capacidades`, `clientes`, `cliente`, `planes` (de `Modulos::PLANES`), `modulos` (Anexo B), `ia_uso` (Anexo C) y `ejecutar` con
  `crear_demo`, `extender_prueba`, `suspender`, `reactivar`, `cambiar_plan`, `nueva_clave_dueno`, `nota_interna`, `cambiar_modulos` e `ia_tope`.
- **Crear demo**: empresa en demo hasta hoy + días (7 por defecto, máx. 90), su administrador con contraseña que se ve una sola vez,
  `origen = central`, plan Pro con todos los módulos (o los elegidos; "ninguno" = solo lo básico), mensaje y enlace de WhatsApp. Usa
  `Empresas::crear` (lo mismo que *Crear cliente nuevo*) y `Modulos::aplicarPlan`. Correo ya usado → error en el campo correo. `correo_enviado: false` (no hay envío de correo).
- Seguridad del protocolo: orden de validación §3.1, `bh` (hash del cuerpo), nonce de un solo uso, vida 60 s (30 s en `ejecutar`),
  límite por IP **120 pedidos y 30 rechazados por minuto** (también para `sso` y `estado`; antes 30), errores siempre con `codigo`.
- Idempotencia: tabla `tukan_central_operaciones` (la operación repetida devuelve lo mismo con `repetida: true`; la contraseña NO se
  guarda: en la repetida sale `clave: null, clave_oculta: true`). Registro de lo que hace el panel en `tukan_central_registro`
  (se ve en la ficha del cliente → *Desde el Panel Tukán Central*).
- Base (`db/schema.sql`, se puede correr muchas veces): `empresas.origen`, `empresas.plan`, `empresas.estado_antes`,
  `tukan_central_pedidos.rechazados` y las tablas `tukan_central_operaciones` y `tukan_central_registro` → **Tablas: 21**.
- Estado de cada cliente con la regla única del panel: demo → *prueba*; demo terminada o vencida → *vencido*; activa que vence en 0 a 7 días
  → *por_vencer*; activa sin fecha → *activo*; suspendida → *suspendido*. `estado` (v1) y `clientes` (v2) dan las mismas cifras.
- Precio del mes por módulos: plan + módulos encendidos aparte − módulos del plan apagados. Precios sueltos **provisionales** en
  `Modulos::PRECIOS` (enlaces 3, campañas 6, agentes 5, embudo 4, seguimientos 4, reportes 3, IA 12, integraciones 8).

**Pruebas:** nuevo `tests/prueba_central_v2.php` (162, contra PostgreSQL real: firma mala, vencido, nonce repetido, bh malo, GET, no JSON,
vida 31 s, crear demo, repetida con clave oculta, correo duplicado → `datos.email`, alargar, suspender/reactivar, nueva clave,
capacidades, clientes = estado, módulos, IA, error interno sin detalles, límite 120/30). Toda la suite: idiomas 43, lógica 446,
Panel Central v2 162, pantallas 212 (crear demo por HTTP, SSO con `ir`) → **TODO BIEN**.

**Para tenerlo en el servidor:**
1. Pegar la línea de "Actualizar el programa" (abajo) en PuTTY **qf@qfcorp**: corre `db/schema.sql` (agrega las columnas y tablas nuevas, no borra nada) → debe decir **Tablas 21**.
2. En el Panel Tukán Central → Programas → WhatsApp Tukán → tocar **Probar conexión** (así lee las capacidades v2 y aparece en *Crear demo*).

**Pendiente:**
- Dimitry: confirmar los precios de los módulos sueltos (`Modulos::PRECIOS`).
- No hay correo de bienvenida (el programa no envía correos): la demo se manda por WhatsApp desde el panel.
- Sin `registrar_pago` ni `finanzas`: WhatsApp Tukán no guarda pagos; si se quiere, agregar una tabla de pagos y esas acciones.

## 🚀 Mejoras para competir (22 oct 2026)
Se investigaron 17 programas que venden lo mismo (Wati, Callbell, Kommo, Leadsales, Respond.io, WhatChimp, Wasapi, Whaticket, Zoko, AiSensy, etc.).
**Lo que más molesta a sus clientes:** cobro por cada usuario, recargo escondido de 15–80 % sobre lo que cobra Meta, límites de contactos, campañas o bot quitados del plan barato, pago de 3 a 6 meses por adelantado.
**Nuestra propuesta:** precio fijo por negocio, mes a mes, contactos ilimitados, **0 % de recargo** (el cliente paga Meta directo), coexistencia, español y factura SRI.

Hecho, probado e **INSTALADO en qfcorp el 22 oct 2026** (resultado: 19 tablas, todo bien, IA con la clave compartida de Tukán):
1. **Módulos por cliente**: se encienden o apagan en la ficha del cliente; **planes con un toque**: Básico $19, Negocio $35, Pro $55. Lo que no tiene sale en **Más** como "No incluido" con botón "Pedir a Tukán".
2. **Fotos, audios, videos y documentos** en el chat (ver, escuchar, adjuntar, **notas de voz** desde el navegador).
3. **Menú con botones de WhatsApp** (3 botones o lista de hasta 10) en la respuesta automática.
4. **Embudo de ventas** (tablero con etapas y valor; los nuevos entran solos).
5. **Seguimientos programados** (no se mandan si ya respondió; si pasaron 24 h van a Pendientes gratis).
6. **Reportes** (mensajes por día, tiempo de respuesta, % atendidos, ventas, por persona).
7. **Asistente con IA** (sugerir respuesta y resumir; nunca contesta solo). Usa la **IA compartida de Tukán** (`/etc/tukan-ia.env`, la misma clave de TuHospedaje): no necesita clave propia. Tope $3 al mes por cuenta.
8. **Enlaces cortos con conteo de clics, QR y botón flotante para la página web**.
9. **Integraciones**: descargar a Excel y aviso a Make/Zapier/n8n firmado.

Corrección (22 oct): al principio le puse a WhatsApp una clave de IA propia; estaba mal, porque ya existe la IA compartida de Tukán con la clave de TuHospedaje. Ya se cambió: **no hace falta crear otra clave**. La clave nueva que se creó en console.anthropic.com se puede borrar ahí (API Keys → la última → Delete) para no dejar claves sueltas.
Pendiente de estas mejoras: llenar "Lo que sabe la IA" (Más → Asistente con IA) con los datos de Tukán; revisar precios de los planes en `src/Modulos.php` si quieres otros; más adelante, la acción `ia_uso` para ver el gasto de IA de WhatsApp en el Panel Tukán Central (hoy WhatsApp usa el conector v1).
Ideas siguientes (lo que más ofrecen los competidores y aún no tenemos): Instagram y Messenger en la misma bandeja, app con notificaciones, Shopify/WooCommerce, catálogo de productos, pagos con Payphone/DeUna, constructor visual de flujos.

## 🌐 Idiomas (22 oct 2026, noche)
- Pedido de Dimi: botón de traducción en todos los programas para vender afuera. Revisado: solo TuVocalía y TuAcademia lo tenían (v28, 5 idiomas). Nunca se quitó; los demás nunca lo tuvieron.
- Hecho: pieza compartida **Tukán Idiomas** (`tukan-central/compartido/tukan_idiomas.php` + `LEEME_IDIOMAS.md`), copiada sin cambios en `src/tukan_idiomas.php`.
- WhatsApp Tukán ya tiene el botón 🌐 con español, inglés (777 frases), francés (773), portugués (722) e italiano (773). Se guarda en `usuarios.idioma`.
- Mensajes, nombres, notas y plantillas de clientes se marcan con `u()` / `<!--notr-->` y **nunca se traducen**.
- La IA sugiere la respuesta en el idioma del cliente.
- Pruebas: idiomas 43, lógica 443, pantallas 202, todo bien.
- Falta: páginas legales en otros idiomas (revisión de abogado). Siguientes programas, en orden: TuSalón, TuHospedaje, TuEvento, TuPlanner, TuGuardería Canina, TuSpa, TuGroomer, TuPass, ERP para productoras, TuRemolque, Panel Tukán Central.

## 📝 Revisión de la app de Meta (empezada 22 oct 2026)
Guía completa con textos, videos y respuestas: **docs/REVISION_META.md**.
- Listo: verificación ✅, llamadas de prueba ✅, plataforma Sitio web ✅, los 3 permisos ✅ (textos + video 1 y video 2 + casillas), tratamiento de datos ✅ (Cloudflare y Anthropic como encargados; responsable QFCORP SOCIEDAD POR ACCIONES SIMPLIFICADA, Ecuador), instrucciones para revisores ✅ (texto en inglés, Facebook Login: Sí).
- **ENVIADA a Meta el 22 oct 2026 16:38 → "Revisión en curso"** (hasta 20 días). Cuenta de prueba: cliente "Meta Review" (review@tukanec.com, demo hasta 21/11/2026). Ícono y URL de términos guardados.
- Arreglado: el usuario dimitryfierro@gmail.com no era super (el super era "dimitryfierro@gmail.oom", error de tipeo al crearlo); se le dio super con UPDATE en la base.

## 🧪 Prueba con el número de prueba (22 oct 2026, tarde)
- Token nuevo puesto en Prueba Meta ("Guardado y conectado"). Destinatario: celular personal de Dimi.
- El mensaje no llegaba: **la cuenta de prueba (WABA 2501989233621271) no tenía suscrita la app Tukan Mensajes**. Se suscribió desde el Explorador de la API Graph (POST subscribed_apps → success).
- **Resultado: FUNCIONA.** Llega el mensaje (Bandeja y Embudo → Nuevo), Enviar por el sistema sale ✓✓.
- IA: la clave compartida vieja daba 401 (no válida). Se creó una clave nueva y se guardó con `tukan-central/deploy/configurar_ia.sh` (modelo rápido claude-haiku-5-5) → "IA: FUNCIONA". Sugerir respuesta probado en el chat: funciona. Revisar si TuHospedaje usa la clave compartida.
- Fotos, notas de voz y PDF probados en las dos direcciones: funcionan.
- Nuevo (pedido de Dimi): **avisos de mensaje nuevo** → número en la pestaña "(2) Bandeja", círculo rojo en el ícono de la pestaña, sonido corto y aviso del navegador (botón "🔔 Avisarme cuando llegue un mensaje" en la Bandeja; al tocar el aviso abre el chat). Archivo public/sw.js. Siguiente paso posible: avisos con el navegador cerrado (Web Push / app instalable).
- El botón "Enviar desde el celular (gratis)" y "Guardar en Pendientes" solo salen si el número está también en la app del celular (coexistencia). Con números solo API no tienen sentido (saldría desde otro número).
- Arreglo en el programa: al guardar un número a mano ahora suscribe la app solo (antes solo lo hacía el botón QR) y los avisos con firma mala quedan anotados en webhook_log. Pruebas: lógica 441, pantallas 190.

## 🔎 Revisión de la versión de GitHub (22 oct 2026)
Otra sesión subió 6 cambios el 21 oct en la tarde (+3.240 líneas): **plantillas desde el panel**, **cuentas por cliente** (empresas separadas, panel "Clientes de Tukán", "Mi cuenta"), **campañas masivas** con permiso de promociones y etiquetas, **varios agentes** (asignar conversaciones, respuestas rápidas), **conector con el Panel Tukán Central** y **términos y condiciones + tratamiento de datos**.
- Pruebas: **lógica 306 y pantallas 140, todo bien**. Sin errores de sintaxis.
- El botón QR mantiene todos los arreglos (solo se agregó que el número sea de la empresa correcta y que haya cupo en el plan).
- Actualizar sobre la base actual **no borra nada**: usuarios, números, mensajes y pendientes se conservan; lo existente pasa a la empresa 1 "Tukán Software" y el primer usuario queda como **super**.
- Instalador probado en servidor simulado: 15 tablas, página 200, envío de campañas programado (cada minuto).
- Arreglado: el cuadro RESULTADO tenía dos "7)" y una "ñ"; ahora va del 1 al 8 sin tildes.
- **Para tenerlo en el servidor:** pegar la línea de "Actualizar el programa" (abajo) en PuTTY **qf@qfcorp**. Ahora debe decir **Tablas 19** y "8) Envio automatico (campanias y seguimientos): 1".

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
Encender la IA compartida (una sola vez para todos los programas; usa la clave de TuHospedaje):
```
cd /var/www/tukan-central && git pull && bash deploy/configurar_ia.sh --usar-clave-tuhospedaje
```
Crear o cambiar usuario del panel:
```
php /var/www/tuwhatsapp/bin/crear_usuario.php
```

## Datos técnicos
- Respaldo diario 3:40 am en `/var/backups/tuwhatsapp` (14 días).
- Archivos principales: `src/Registro.php` (botón QR), `src/Webhook.php`, `src/Reglas.php`, `src/Mensajeria.php`, `src/Gasto.php`, `deploy/instalar.sh`, `deploy/clave_meta.sh`.
- Módulos nuevos: `src/Modulos.php` (módulos y planes), `src/Archivos.php`, `src/Embudo.php`, `src/Seguimientos.php`, `src/Reportes.php`, `src/Asistente.php` + `src/tukan_ia.php` (IA compartida, copia sin cambios del Panel Central), `src/Enlaces.php`, `src/Integraciones.php`, `public/w.php`, `public/boton.js`. Fotos y audios en `/var/www/tuwhatsapp/archivos` (no se suben a GitHub).
- Pruebas: `PGPORT=5432 PGUSER=postgres bash tests/correr.sh` → debe terminar en `TODO BIEN` (idiomas 43, lógica 446, Panel Central v2 162, pantallas 212).
- Cuidado: hay dos ventanas de PuTTY; la del programa es **qf@qfcorp** (no `root@vps-...`).

## 21 oct 2026 (tarde): cuentas por cliente
- Hecho: empresas separadas (tabla `empresas`, `usuarios.empresa_id/rol/super/activo`, `lineas.empresa_id`). Lo que había quedó en la empresa 1 "Tukán Software" y el primer usuario es super.
- Panel Clientes de Tukán (ícono edificio, solo super), Mi cuenta (equipo, contraseña, token de API), cuenta suspendida/vencida bloqueada, soporte "Entrar a su cuenta".
- Pruebas: lógica 234, pantallas 108.
- Siguiente: campañas masivas con plantillas y varios agentes por chat; luego conectar con el Panel Tukán Central.
- Hecho: campañas masivas (tablas `campanias`, `campania_envios`; `contactos.marketing/etiquetas`; cron `bin/campanias.php`). Pruebas: lógica 272, pantallas 117.
- Hecho: varios agentes (contactos.asignado_a, filtros Míos/Sin asignar) y respuestas rápidas (tabla respuestas_rapidas). Pruebas: lógica 282, pantallas 129. Tablas: 13.
- Hecho: conector con el Panel Tukán Central v1 (public/tukan_central.php, src/TukanCentral.php, deploy/conectar_central.sh). Producto "tuwhatsapp". Tablas: 15. Pruebas: lógica 302, pantallas 134.
- Hecho: términos y condiciones + acuerdo de tratamiento de datos (públicos) y aceptación del admin del cliente al entrar (empresas.terminos_*). Pruebas: lógica 306, pantallas 140.
