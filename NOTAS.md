# WhatsApp Tukán — Estado y pasos para continuar
*Actualizado: 23 nov 2026*

## 🔎 Idioma del cliente: arreglos de la revisión independiente (23 nov 2026, rama idioma-arreglos)
- CRÍTICO arreglado: en Automático los textarea de traducciones tenían marcas `<!--notr-->` que el navegador guardaba como texto
  (al cliente le llegaba como saludo). Quitadas de los campos; `Traducir::texto()` las borra al guardar y al leer; `db/schema.sql`
  limpia las ya guardadas en `lineas.traducciones` y `flujos.traducciones` (idempotente).
- Detección (`src/IdiomaCliente.php`): el texto en español manda; sin señal ("ok", "1", emoji, correo, enlace, número) no cambia nada;
  otro idioma pide 2 palabras propias o 1 + código de país de ese idioma; cambiar un idioma guardado pide 2; Caribe hispano (+1 809/829/849/787/939)
  = español; "info" neutra; sin idioma guardado = español (también en bandeja, contactos, reportes y ficha).
- Webhook: solo detecta con mensajes de texto (no botones, plantillas, fotos, archivos, ubicación) ni cuando el chatbot espera un dato.
- Agente IA: "Responde SIEMPRE en X" solo si X no es español y la función está encendida; si no, la regla de siempre.
- Chatbot de ejemplo sin traducciones; palabras de un idioma solo valen para clientes de ese idioma (las del español, para todos).
- Carrito: sin "menu", "compra", "finalizar". Traductor del chat: solo envía sin confirmar si el texto está muy claro en el idioma del cliente.
- Traducciones de opciones de chatbots alineadas por paso y opción (`$mapaOps`), como el menú.
- Pruebas nuevas: `tests/prueba_idioma_cliente.php` (en correr.sh) + bloques en prueba_logica y prueba_web.

## 🌐 Contestar en el idioma del cliente (23 nov 2026)
Pedido (Dimitry): «hay gente que escribe en inglés, que si recibe en inglés responda en inglés; el programa es nuestro, sacar el mayor beneficio».
- Hecho: menú automático, chatbots, agente con IA y textos fijos (pedido, pago Plux, carrito, catálogo, llamada fuera de horario, cotización PDF) salen en el idioma del cliente (es/en/pt/fr/it).
- Archivos nuevos: `src/Traducir.php` (traducciones del menú y chatbots + botón con IA) y `src/FrasesCliente.php` (frases fijas en 5 idiomas).
- Base: `lineas.traducciones`, `lineas.idioma_auto`, `lineas.idiomas`, `flujos.traducciones` (ALTER idempotentes en `db/schema.sql`).
- Traducir con IA = una sola llamada (`funcion` traducir_menu / traducir_chatbot), solo con el extra `ia`; al contestar no se usa IA.
- Pruebas: bloque «Contestar en el idioma del cliente» en `tests/prueba_logica.php` y en `tests/prueba_web.sh`; el simulador de IA entiende `TRADUCIR_TUKAN`.
- Pendiente / ideas: las plantillas de Meta (campañas, tienda) siguen en el idioma con que se aprobaron (hay que aprobar una por idioma);
  los mensajes de error del carrito (sin stock, etc.) siguen en español; las páginas de pago (Plux) en español.
## 🌍 Atender clientes en otro idioma: bandera y traductor del chat (23 nov 2026)
Pedido de Dimitry: escribe gente en inglés; sacarle el mayor provecho al programa. Esta parte es la del **equipo que atiende**
(las respuestas automáticas multilingües — menú, chatbots, agente IA, mensajes fijos — van en otra rama).
- **Idioma del cliente** (sin IA, todos los planes): bandera + «Habla inglés» en la cabecera del chat, banderita en la bandeja y la lista
  de chats, selector en la ficha (**Detectar solo** / es / en / pt / fr / it → `IdiomaCliente::fijar`), filtro por idioma en Bandeja (`&i=en`)
  y Contactos, Reportes → «Conversaciones por idioma del cliente», columna **Idioma** al final de la descarga de contactos, `contacto.idioma`
  en el webhook de integraciones y `idioma` en la API `accion=contacto` (entrada opcional y salida).
- **Traductor del chat** (MÓDULO: extra de IA `ia`; `src/Traductor.php`): enlace **Traducir** en los mensajes del cliente que no están en el
  idioma del panel (se guarda en `mensajes.traduccion` / `traduccion_idioma`, la 2.ª vez no llama a la IA); casilla «Escribo en español, se
  envía en inglés» (marcada sola si el cliente habla otro idioma, se recuerda por chat en la sesión); al enviar se confirma la traducción
  (Enviar traducción / Editar / Enviar sin traducir) y el original queda en `mensajes.original` / `original_idioma` («Escrito en español: …»).
  Si el texto ya está en el idioma del cliente (p. ej. lo propuso Sugerir respuesta), se envía sin traducir y sin gastar.
  Sugerir respuesta: en el idioma del cliente. Resumir: en el idioma del panel. Sin el extra: «Con el extra de IA traduces los mensajes con un toque».
- Seguridad: todas las rutas nuevas son POST con CSRF; traducir/guardar original filtran por `linea_id` (y `contacto_id`): otra cuenta no puede.
- Pruebas: `prueba_logica.php` (bloque «Idioma del cliente y traductor del chat») y `prueba_web.sh` (mismo nombre), con la IA simulada
  (`tests/meta_simulado.php` → marca `TRADUCTOR_TUKAN_CHAT` devuelve «[EN] texto»).
- Servidor: solo actualizar con la línea de siempre (`instalar.sh`, no borra nada) (el schema agrega 4 columnas a `mensajes`, idempotente). El traductor usa la misma IA
  compartida (`/etc/tukan-ia.env`) y el mismo tope por cuenta que el asistente.

## 📱 Avisos NATIVOS en la app de Android, como WhatsApp (14 nov 2026, noche) — Firebase Cloud Messaging
Problema (Dimitry): en el celular no llegaban los avisos aunque los aprobaba; Android los veía como "página web de Chrome". En App en el
celular solo aparecía "Chrome · Windows": el Web Push dentro de la app (TWA) nunca registró el celular.
- **Servidor (`src/Fcm.php`, `public/fcm.php`):** la app registra su celular (dispositivo 32 hex + secreto 64 hex, en la base solo el sha256), su token
  de Firebase y `avisos` (1 = el celular permite las notificaciones; con 0 no cuenta como "avisos funcionando" y App en el celular dice que están bloqueados).
  Vuelve a registrarse al abrir (al menos una vez al día) y cuando cambia `avisos`. Límite: 20 celulares nuevos por hora por IP real (`ip_cliente()`, CF-Connecting-IP).
- **Vínculo (protocolo v2, tras la revisión):** la app abre la página con `&disp=D&ts=T&prueba=P`, P = HMAC-SHA256(clave = sha256 crudo del secreto,
  "vincular:D:T"). Si P es buena, |ahora−T| ≤ 10 min y T es más nuevo que el último usado por ese celular → cookie firmada `tuwa_disp` y 302 a la misma
  página sin esos datos; con la sesión abierta, el celular queda vinculado. Sin prueba válida se ignora (el Referer y el botón de confirmar ya NO existen).
- **Envío:** FCM HTTP v1, mensaje solo de datos (titulo, texto, url, tag), prioridad alta, espera 24 h; OAuth con JWT RS256 de la cuenta de servicio, token de acceso
  cifrado ~50 min. Token muerto (404 UNREGISTERED / 400 token no válido) → se borra el celular. Sale al FINAL del webhook (no atrasa la respuesta automática).
  También va en `Push::enviar` (llamadas, pagos, Copiloto, aviso de prueba). Cuenta para la cinta amarilla y para el aviso a los administradores.
- **Desvincular:** Salir desde ese celular; cerrar esa sesión desde Mi cuenta (el recuerdo guarda su `disp`); contraseña cambiada/reseteada, usuario
  desactivado o «Cerrar en todos los demás» (TODOS; el celular desde donde se hizo se vuelve a vincular solo con su cookie); Quitar o «Apagar en este celular».
- **Sin repetidos:** a quien le funciona la app nueva no se le manda el Web Push de Chrome dentro de la app vieja ("App Tukán Mensajes · …"); y el JS de la app
  nueva desuscribe ese Web Push y borra su fila. Token dado de baja por Google → el celular queda sin token (se conserva) hasta que la app se registre de nuevo.
- Solo cuentan como "avisos funcionando" si la clave de Firebase está subida. Limpieza diaria: registros por IP viejos y celulares nunca vinculados de más de 7 días.
- **Clave de Firebase:** menú Tukán → **Avisos de la app (Firebase)** (solo personal de Tukán, `es_super()`): subir el .json de la cuenta de servicio (se guarda cifrado con
  TUWA_LLAVE en `ajustes.fcm_clave`; se ve proyecto y correo, nunca la llave), aviso de prueba, quitar. Por consola: `bash deploy/configurar_firebase.sh /ruta/clave.json`.
- **App (qfcorp/tukan-apps):** la parte de Android la hace la app 1.0.4 (FirebaseMessagingService, canal propio, registro en /fcm.php, `&disp=` en la URL de inicio).
- Pruebas: Firebase y OAuth SIMULADOS (`tests/fcm_simulado.php`, FCM_PUERTO 8093). `tests/correr.sh` ahora fija `PGTZ=America/Guayaquil` (de noche, la fecha de la base en UTC
  no coincidía con la del programa y fallaban pruebas de fechas).

## ✈️ Avisos por Telegram (14 nov 2026)
Pedido de Dimitry: «podemos mandar una notificación a Telegram con el bot».
- Bot PROPIO de Tukán Mensajes (no el de las alertas internas): `deploy/configurar_telegram.sh` + `bin/telegram.php conectar|estado`; `.env`: TUWA_TELEGRAM_TOKEN,
  TUWA_TELEGRAM_SECRETO, TUWA_TELEGRAM_BOT (opcional TUWA_TELEGRAM_API_URL para pruebas); el instalador los conserva y muestra la línea 14.
- `src/Telegram.php`: vincular con código de un solo uso (sha256 en `usuarios.tg_codigo`, 15 min) → `/start <código>` en `public/telegram.php`
  (secreto en X-Telegram-Bot-Api-Secret-Token, responde en la misma respuesta HTTP); `/parar` desconecta; un chat de Telegram solo en una persona.
- Modos (`usuarios.tg_modo`): -1 al momento (enganchado en Webhook después de Push, en paralelo con curl_multi, ráfagas en `tg_rafagas`: 1 cada 2 min por chat),
  5/15/30/60 = si nadie contesta (`Telegram::procesarPendientes` desde `bin/campanias.php`, marcas 't<id>'/'tfin' en `avisos_correo`, candado y reserva de turno
  con `usuarios.tg_ultimo`, 10 min entre resúmenes), 0 apagado. 403 → se desconecta sola. Texto con parse_mode HTML escapado.
- Pantalla: App en el celular → 4. Avisos por Telegram. Cuenta para la cinta amarilla. Manual cap. 19 + novedades + pregunta frecuente.
- Pruebas: Telegram simulado `tests/telegram_simulado.php` (TG_PUERTO 8094); idiomas 43, lógica 1000, TuFactura 29, Panel Central 230, pantallas 521, Android 45, iPhone 93.
- Revisión independiente (arreglado): contraseña cambiada/reseteada o usuario desactivado → `Telegram::desvincular`; destinatarios al momento con la misma
  regla que Push (`Telegram::destinatarios`: asignado activo, si no equipo que recibe chats/admins, y admins si a nadie le llega ningún aviso); cambio de bot
  (`bin/telegram.php cambio_bot`, token viejo por stdin) desconecta a todos y hace deleteWebhook del viejo; Telegram con 1 s/2 s y al final de `entrante()`
  (finally, después de IA/chatbot/menú); desconectar solo con JSON 403 o 400 "chat not found" y solo ese chat (no borra el código); limpieza diaria de
  `avisos_correo` aunque no haya SMTP y de `tg_rafagas` > 1 día; aviso de prueba 3/hora (`tg_pruebas`) y bloqueado en la cuenta de muestra.
  Pruebas: lógica 1000, pantallas 521, Android 45 (incluye correr `configurar_telegram.sh` con sudo: mismo bot, otro bot, token malo).
- **Al actualizar el servidor:** crear el bot en @BotFather y correr `bash /var/www/tuwhatsapp/deploy/configurar_telegram.sh`. La app de Android no cambia.

## 🔓 La app se queda abierta como WhatsApp + entrar con huella (14 nov 2026)
Pedido de Dimitry: «debo ingresar a cada rato; debería ingresar una vez y que quede ahí abierta, por último con huella; si me olvidé de loguearme se pierde».
- Causa: en Ubuntu el cron `sessionclean` borraba las sesiones de `/var/lib/php/sessions` a los **24 minutos** (php.ini), aunque el programa pedía 30 días.
  Arreglo: sesiones en `/var/lib/tuwhatsapp/sesiones` (lo crea `deploy/instalar.sh`, con limpieza diaria de las de más de 30 días).
- **Recordar este celular** (marcado por defecto): cookie de 400 días, tabla `sesiones_recordadas` (solo hash). Si se pierde la sesión, entra sola.
  Mi cuenta → **Dispositivos con sesión abierta** (cerrar uno / en todos los demás). Cambiar la contraseña cierra los demás.
- **Entrar con huella** (WebAuthn, `src/Huella.php`, tabla `huellas`, `public/assets/huella.js`): botón en la pantalla de entrar, oferta después de entrar
  con contraseña y Mi cuenta → Activar huella / Quitar. Contraseña nueva puesta por el administrador borra las huellas.
- Pruebas: autenticador simulado en PHP (EC y RSA): firma mala, desafío reusado o vencido, origen y dominio equivocados, sin huella (UV), contador hacia
  atrás, usuario desactivado; cookie válida/robada/vencida/borrada al salir y al cambiar la clave; de punta a punta en pantallas con `tests/huella_celular.php`.
- Manual: capítulo 21 nuevo (+ novedades). **Falta regenerar el PDF del manual** después de unir con los cambios de avisos.
- **Arreglos de la revisión (14 nov 2026):** «Cerrar sesión» de un celular, «Cerrar en todos los demás», cambiar la contraseña y el reset o desactivar del
  administrador cortan también la sesión de PHP que sigue viva en el otro aparato (columna `usuarios.sesion_gen` + id del recuerdo en la sesión; ver
  `usuario_de_sesion()` y `sellar_sesion()` en `app/bootstrap.php`). Activar una huella pide la contraseña (o haber entrado con ella hace menos de 5 min),
  cambiar la contraseña borra las huellas, llega un correo al activar una huella, `Huella::registrar` es atómico y `Sesiones::limpiarAlDia()` corre desde el cron.
- Al actualizar el servidor: correr el instalador (crea la carpeta de sesiones y las 2 tablas nuevas). Los usuarios entran **una vez más** con la casilla marcada.
## 🔔 Que no se pierda ningún cliente: avisos más fuertes + respaldo por correo (14 nov 2026)
Pedido de Dimitry: "hemos perdido muchas notificaciones por estar apagada la app; debería enviar alguna notificación por mail o algo; con eso se pierden clientes".
- **Avisos (Web Push):** el servidor de push guarda el aviso **24 horas** (antes 1 hora: si el celular estaba apagado o sin internet más de una hora, el aviso se perdía).
  Una suscripción con fallas temporales ya **no se apaga para siempre** a las 5 fallas: se vuelve a intentar cada día (solo 404/410 la borran). Se guarda la última falla y el nombre del dispositivo.
  Si a quien atiende el chat no le llegan avisos, **también les llega a los administradores**.
- **Respaldo por correo** (`src/AvisoCorreo.php`, corre cada minuto desde `bin/campanias.php`): si nadie lee ni contesta un mensaje de un cliente en N minutos, correo a quien atiende el chat (o al equipo que recibe chats; si nadie, a los administradores). Cada persona elige **apagado / 5 / 15 / 30 / 60 min** (por defecto 5) y otro correo si quiere; un solo correo con todos sus chats pendientes y enlace a cada uno; máximo uno cada 15 min; cada mensaje se avisa una sola vez (`avisos_correo`). Contactos personales y chats ya contestados (desde el panel o el celular) no. Solo mensajes de las últimas 12 horas y de después de instalarlo. El administrador puede poner un **correo de copia** para la empresa (ventas@…). Si el correo falla, reintenta en 5 minutos.
- **Correo:** cliente SMTP propio `src/Correo.php` (465 SSL / 587 STARTTLS, AUTH PLAIN/LOGIN). Se configura con `bash /var/www/tuwhatsapp/deploy/configurar_correo.sh` (SMTP_HOST, SMTP_PORT, SMTP_USUARIO, SMTP_CLAVE, SMTP_DE, SMTP_DE_NOMBRE). **Sin configurarlo, no se mandan correos** (el panel lo dice).
- **Arreglos de la revisión (14 nov 2026):** candado de PostgreSQL + reservas antes de mandar (dos corridas del cron a la vez ya no repiten correos); consulta por chat, del más nuevo al más viejo, con tope de 50 chats por empresa y 1000 por corrida (una empresa con miles de chats sin leer ya no deja sin correo a las demás) y marca `fin` para los mensajes ya avisados a todos; la respuesta del agente con IA, chatbot, respuesta automática o tienda cuenta como contestado, salvo que la IA haya pasado el chat a una persona (`contactos.traspaso_en`) y nadie haya contestado después; sendmail solo con `SMTP_MODO=sendmail`; sin STARTTLS no se manda la clave en claro (error claro; solo `SMTP_SEGURIDAD=ninguna` lo permite); correo de prueba máximo 3 por hora y bloqueado en la cuenta de muestra; limpieza diaria de `avisos_correo` (más de 30 días); `configurar_correo.sh` guarda los valores entre comillas (clave con `;` o `#` llega entera; no acepta comillas dobles), sin copias del .env en /tmp, y conserva el dueño del archivo. **Importante:** `deploy/instalar.sh` reescribe `config/.env` con una lista fija de variables: ahora conserva también `SMTP_*` (antes cada actualización habría borrado la configuración del correo).
- **Pantallas:** App en el celular muestra cada dispositivo con su último aviso entregado y fallas (y Quitar), los pasos de batería para Android (Chrome y Tukán Mensajes "Sin restricciones"; Xiaomi, Samsung, Huawei) y la sección Respaldo por correo. **Cinta amarilla** en Inicio y Bandeja si a la persona no le llegaría ningún aviso. **Dentro de la app de Android**, si ese celular no tiene los avisos activos, tarjeta grande "Activa los avisos en este celular" en Inicio y Bandeja. Si el navegador ya tenía la suscripción y el servidor la perdió, se vuelve a guardar sola.
- Al tocar un aviso o el enlace del correo de un chat de **otro número** de la misma cuenta, el programa cambia solo a ese número (antes decía "Esa conversación no existe").
- Pruebas: SMTP falso `tests/smtp_falso.php`; idiomas 43, lógica 833, Panel Central 230, pantallas 462, app Android 44, iPhone 93 — TODO BIEN.
- **Al actualizar el servidor:** correr `bash /var/www/tuwhatsapp/deploy/configurar_correo.sh` con un buzón (p. ej. avisos@tukansoftware.com del VPS de correos).

## 🎨 Identidad gráfica común de Tukán (10 nov 2026, noche)
Pedido de Dimitry: «todos los programas iguales al ERP, cada uno con sus colores».
- Kit común `public/assets/tukan-ui.css` + `tukan-ui.js` (idénticos en todos los programas). Naranja `#F28C28` con texto oscuro (AA).
- Barra de arriba naranja con el negocio, plan, cargo y Salir; menú a la izquierda con todo agrupado (Principal, Vender, Automático,
  Equipo, Ajustes) y emojis; ☰ en el celular; ya no hay barra de abajo (la pantalla «Más» sigue). Plantillas, Números y Mi cuenta,
  que eran íconos de la barra, están en Ajustes; Modo claro u oscuro y el idioma, al final del menú.
- Inicio con el orden común: saludo, Primeros pasos (con «Ocultar»: `empresas.pasos_ocultos` en schema.sql), 5 cifras, Últimas
  conversaciones, Ventas y llamadas, Resumen de la IA, accesos rápidos. Modo oscuro conservado sobre el kit.
- Manual (cap. 2 y 3, «Más →» pasa a «Menú →», capturas nuevas de Inicio, menú, chat y modo oscuro) y PDF regenerado. `docs/LINEA_GRAFICA.md`.
- Pruebas: idiomas, lógica 778, Panel Central 230, app Android 44 bien; pantallas: todo bien salvo 3 de `registrar_pago` que fallan
  solo de noche (la prueba calcula la fecha con psql en UTC y el programa con la hora de Ecuador; no es de este cambio).

## 🎨 Nueva cara para competir + app lista para Google Play (7 nov 2026, noche)
Pedido de Dimitry: "haz todos los cambios de una vez para dejarla lista para publicarla en Google ya completa" (después del informe de la competencia).
- **Inicio** (pantalla nueva al entrar): sin leer, esperan respuesta, te escribieron hoy, clientes nuevos, cobrado hoy, pedidos por cobrar, en juego en el embudo, llamadas perdidas. **Primeros pasos** para el administrador (se tachan solos; no salen en la cuenta de muestra). `src/Inicio.php`, `app/vistas/inicio.php`.
- **Chat en tres paneles** en computadora (lista · conversación · ficha del cliente) con menú lateral; en celular, botón *Ficha*. Bandeja con espacio "Elige una conversación". Avatares de colores.
- **Modo oscuro** (botón 🌙 + automático), **embudo para arrastrar**, **botón ?** con las explicaciones largas, entrada (login) nueva con beneficios.
- **IA:** "Pruébalo aquí" (`Bot::probar`, no manda nada) y **nota para el equipo** al pasar a una persona (`contactos.traspaso_nota`).
- **Mi cuenta:** plan, precio (oculto dentro de la app Android), qué incluye, próxima renovación y **Cancelar mi plan** (`empresas.cancelar_en`, aviso por Telegram, *Seguir con mi plan*).
- Traducciones nuevas en en/fr/pt/it; fecha de Inicio en cada idioma.
- **App Tukán Mensajes 1.0.2 (código 3)**: abre en Inicio. Compilada aquí (GitHub Actions no arrancó: "pagos fallidos o límite de gasto" en la cuenta de GitHub), firmada con la llave de subida (huella 39:0C:…:40:DC). Guardada en `L:\github\apps\tuwhatsapp\` con el zip de la ficha. Capturas nuevas (Inicio y ficha del cliente) en tukan-apps/tienda/tuwhatsapp. Falta: crear la app en Play Console (guía `tienda/tuwhatsapp/PUBLICAR_TUWHATSAPP_CON_CLAUDE.md`).
- Manual (cap. 2 nuevo, IA, preguntas frecuentes) + PDF de 28 páginas, presentación (diapositiva 14 nueva), README.
- Pruebas: idiomas 43, lógica 778+, Panel Central 163, pantallas 378+, app de Android 43: todo bien.

## 💲 Respuestas gratis y gasto aproximado (7 nov 2026, noche)
Decisión de Dimitry: "si no se cobran no deberían aparecer en el saldo; decir que es un aproximado y dónde está el gasto real en Meta".
- Las respuestas normales dentro de las 24 horas (servicio) ya **no suman al gasto** (Meta no las cobra desde 2025). El tope solo frena plantillas pagadas; las respuestas siguen saliendo aunque se llegue al tope. Migración: precio_servicio = 0.
- Gasto y la barra de arriba dicen **"aproximado"**, con el enlace al gasto real en Meta (business.facebook.com → Facturación y pagos).
- "Te quedan $X: unas N plantillas de utilidad o M de marketing". Aviso de Telegram al 100 % actualizado.
- README (tabla de costos) y manual (preguntas frecuentes) actualizados. Pruebas: lógica 759, Panel Central 163, pantallas 365, todo bien.

## 🧪 Simulación de un cliente con la sandbox de Meta y llamadas fuera de horario (7 nov 2026)
- **Sandbox de Meta pedida** (Herramientas de socios, con llamadas): "Sandbox Business" / "Sandbox WhatsApp Account", número de prueba +1 555-163-5643 (se borra solo el 5 feb 2027). Se conectó como un cliente con el botón "Conectar con WhatsApp Business": el programa lo registró solo (PIN incluido).
- Probado en producción (whatsapp.tukanec.com): plantilla hello_world enviada y leída ✓✓, respuesta del cliente recibida, respuesta desde el panel (gratis) y **llamada del cliente contestada en el panel** ✅.
- Suscrito el campo **calls** del webhook de la app (v27.0). Cuenta "Meta Review" **suspendida**.
- Lo que salió y quedó arreglado:
  - **Llamadas fuera de horario:** antes Meta bloqueaba y al cliente solo le salía "no puede recibir llamadas". Ahora (modo normal "mensaje") la llamada no suena, se cuelga y **al cliente le llega un WhatsApp con el horario** ("Atendemos lunes a viernes de 8:00 a 18:00 y sábado de 9:00 a 13:00…"); al equipo le llega un aviso 🌙. Se puede cambiar el texto (con `{horario}`) o elegir que Meta bloquee. Estado nuevo de llamada: "Fuera de horario".
  - Más → Llamadas muestra el horario en palabras y avisa si ahora está fuera de horario. Al guardar el horario en Respuestas automáticas se le avisa a Meta solo.
  - Error 138018 de Meta explicado en español (falta el campo calls).
  - El nombre del número ya no viene lleno con "Tukán ventas" (si se deja vacío usa el nombre de Meta).
  - El aviso del chat decía "Pidió hablar con una persona" también cuando escribía el equipo: ahora dice "Respuestas automáticas en pausa hasta… (alguien del equipo contestó o el cliente pidió una persona)".
  - Los números de prueba de Meta (+1 555…) no suman gasto.
  - 2 pruebas que dependían de la fecha (escritas en octubre) quedaron firmes.
- Pruebas: idiomas 43, lógica 757, Panel Central 163, pantallas 364, schema sobre base con datos: todo bien.

## 🎉 Ya somos Tech Provider de Meta (7 nov 2026)
- Meta confirma: "Ya eres un proveedor de tecnología" (verificación del negocio ✅ + revisión de la app ✅). **Se puede vender**: cada cliente conecta su WhatsApp con el botón "Conectar con WhatsApp Business".
- Disponible: cuenta sandbox de Meta para pruebas y enlace de registro alojado por Meta.
- La verificación de acceso sigue en revisión; hay que completarla antes del 6 ene 2027.

## ✅ Meta aprobó la revisión de la app (5 nov 2026)
- Permisos **whatsapp_business_messaging**, **whatsapp_business_management** y **public_profile**: aprobados, con acceso avanzado.
- Falta solo la **verificación de acceso (Tech Provider)**, enviada el 28 oct y todavía "En revisión" (plazo de Meta: 4 ene 2027).
- Pendiente: suspender o borrar la cuenta de prueba "Meta Review" y probar el botón QR con el número de un cliente real.

## Qué es
Programa propio tipo WhatChimp (sin mensualidad) conectado a la **API oficial de WhatsApp de Meta**, para el número de ventas de Tukán (+593 98 366 2181) y después QF Producciones (+593 98 683 3077) en el mismo programa.

- Repositorio: https://github.com/qfcorp/tuwhatsapp (rama `main`)
- Dirección: **https://whatsapp.tukanec.com** (ya funciona)
- Servidor: **qfcorp** (usuario `qf`), túnel de Cloudflare `servidorqf` → `http://localhost:80`
- Tecnología: PHP + PostgreSQL + nginx
- Pruebas (Meta, IA y Plux simulados): **idiomas 43, lógica 743, Panel Central v2 163, pantallas 361 y app de Android 42, todo bien** (28 oct 2026)

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
| Número Tukán | +593 98 366 2181 → cuenta "tukansoftware" (ID `2250026202491413`), tipo **App de WhatsApp Business**, calidad Alta |
| Número QF | +593 98 683 3077 → cuenta "qfproducciones" (ID `3132653697006297`), tipo App de WhatsApp Business |
| Número de prueba de Meta | +1 555 675 0567 — Phone Number ID `1391982964001238` — cuenta `2501989233621271` |

En el servidor (`/var/www/tuwhatsapp/config/.env`) ya están: META_APP_ID, META_ES_CONFIG_ID=1865337081309109 y la clave secreta. ✅

---

## 📱 App de Android para Google Play: «Tukán Mensajes» (28 oct 2026, noche)
Pedido de Dimitry: todos los programas listos para Google Play (Producción, todos los países), **sin cobro dentro de la app** por ahora.
- App en qfcorp/tukan-apps: `apps/tuwhatsapp` (paquete `com.tukansoftware.tuwhatsapp`, abre `/?r=bandeja&app=play`), `.aab` y `.apk` en `salidas/tuwhatsapp/`,
  ficha en `tienda/tuwhatsapp/` (textos es/en, play.json, 8 capturas por idioma de la Tienda Muestra) y guía `PUBLICAR_TUWHATSAPP_CON_CLAUDE.md`.
- **Nombre**: en la tienda «Tukán Mensajes: chats y ventas» y en el celular «Tukán Mensajes». Google rechaza apps que usan como nombre una marca de
  otro (WhatsApp es de Meta). Dentro de la app (`?app=play`) el programa también se llama Tukán Mensajes (`nombre_programa()`); la web sigue igual.
- **Modo app** (`en_app_android()`, cookie `tuwa_app`): sin «Pedir a Tukán» (IA), sin «Escribir a Tukán por WhatsApp» en la cuenta vencida, sin
  «Escribe a Tukán para activarlo». Planes y precios sin cambios ($20 / $25, `src/Modulos.php`). Cobro con Google Play preparado y apagado (`TUWA_GOOGLE_PLAY_COBRO`).
- **Privacidad** reescrita (mensajes, fotos, audios, videos, documentos, contactos de terceros por la API de Meta, IA de Anthropic, Plux/Payphone,
  avisos, cómo borrar la cuenta) y eliminar-datos ampliada.
- **assetlinks.json** con la huella de la llave de subida; nginx lo muestra (instalador y `deploy/app_android.sh`).
- **Cuenta del revisor de Google**: `sudo php /var/www/tuwhatsapp/deploy/demo_google.php crear | borrar` → «Tienda Muestra Tukán» (`empresas.muestra`),
  número de ficción `muestra-…` que `src/Meta.php` nunca manda a Meta; no deja conectar números, cambiar la contraseña ni poner claves de cobro.
- Pendiente (Dimitry): en el servidor `instalar.sh` → `sudo bash deploy/app_android.sh` → `demo_google.php crear`; subir a Play Console; luego la huella de Google.

## 🆚 Comparación con Wati y respond.io (28 oct 2026)
**Wati** (wati.io, página Wati LATAM): Growth $69/mes ($59 anual, 3 usuarios, no se pueden agregar), Pro $149/mes ($119 anual, 5 usuarios, usuario extra $39), Business $279–349 (usuario extra $89). Aparte: número extra $29, IA $30 por 1.000 créditos, agente IA Astra $99 (Pro) o $399 (Business), automatizaciones $40 por 1.000, Shopify $4,99. **Recargo sobre Meta**: ~60 % marketing, ~79 % utilidad, ~21 % servicio. Pago anual, sin reembolso, cancelar escribiendo a facturación. Novedad en su página: "Wati AI — No hagas clic, solo pregunta" (segmentar clientes listos para comprar, crear campañas desde una idea, agentes que revisan la operación cada mañana).
**respond.io**: Starter $79, Growth $159, Advanced $279 al mes (anual, por 1.000 contactos activos al mes; extra $12–15 por 100 contactos), 5–10 usuarios, usuario extra $12–24, créditos de IA extra $15 por 1.000. Sin recargo sobre Meta. El plan barato NO tiene agentes IA, campañas, flujos ni API.
**Lo que ellos tienen y nosotros no:** Instagram, Messenger y TikTok en la misma bandeja; llamadas de WhatsApp; IA que crea campañas y segmentos con una frase; resumen diario con IA para el dueño; encuestas de satisfacción (CSAT/NPS); app en Play Store / App Store (nosotros: app instalable desde el navegador); conexión directa a HubSpot/Salesforce (nosotros: Make/Zapier/n8n y Excel); catálogo nativo de WhatsApp (Meta Commerce) y formularios WhatsApp Flows; SMS alterno.
**Lo que nosotros tenemos y ellos cobran aparte o no tienen:** todo en un plan (~$20) con hasta 50 usuarios; 0 % de recargo sobre Meta y tope de gasto; mes a mes; agente IA ~$5 (Wati $99–399); cobro con tarjeta por Plux/Payphone (Ecuador) con pedido que pasa solo a Pagado; cotización PDF con RUC e IVA; catálogo con stock; botón "enviar gratis desde el celular"; español y soporte en Ecuador.
Pendiente: Dimitry elige cuáles de las que faltan se hacen.

## 📞 Guía para pasar el número a "solo API" (28 oct 2026, noche)
Pedido de Dimitry: "tenla lista y hay que poner en algún lado un explicativo".
- **Guía para el cliente:** [docs/GUIA_LLAMADAS.md](docs/GUIA_LLAMADAS.md) (por qué, qué cambia, 5 pasos: borrar cuenta en la app → esperar 3 min → Conectar con WhatsApp Business eligiendo "agregar tu número" → revisar → encender llamadas).
- **Explicativo dentro del programa:** en **Más → Llamadas** sale un cuadro azul "Tu número también está en la app WhatsApp Business del celular… no permite llamadas" con los pasos (desplegable) si el número está en coexistencia; en **Números conectados** una nota corta. También en el manual (cap. 12) y en sus preguntas frecuentes.
- **Mismo número = mismos chats:** al volver a conectar el número como solo API, el programa reconoce que es el mismo (mismo teléfono de una línea en coexistencia) y lo reemplaza en esa línea: se conservan chats, contactos y ajustes, y no cuenta como número nuevo del plan.
- Pruebas: idiomas 43, lógica 743, Panel Central 163, pantallas 361, todo bien.

## 📞 Llamadas de WhatsApp y "Pregúntale a la IA" (28 oct 2026, tarde)
Pedido de Dimitry: hacer las llamadas de WhatsApp y copiar Wati AI (sin grupos ni encuestas).
- **Llamadas** (Calling API de Meta, WebRTC en el navegador): suena en el panel ("📞 Llamada de…", Contestar/Rechazar), se contesta en una ventanita que sigue aunque cambies de página; la primera persona que contesta se la lleva; perdidas con aviso al celular y mensaje automático opcional; llamar al cliente después de su permiso (botón de Meta, 7 días o siempre; 1 pedido al día); historial en Más → Llamadas y en cada chat. Probado de punta a punta en Chromium: audio conectado entre el panel y un "Meta" falso.
  - Falta en Meta (una vez): suscribir el campo **calls** del webhook de la app. Meta pide número en la API (no coexistencia) y límite de 2.000 al día.
- **Pregúntale a la IA** (extra de IA): preguntas en palabras con los datos del número; lista de clientes → etiqueta → Campañas; campaña → plantilla lista en Plantillas; cifras. **Resumen de cada mañana** al celular (cron). **Tono de la marca** para toda la IA.
- Arreglado: `schema.sql` fallaba al correrlo otra vez si ya había mensajes del agente IA, chatbots o tienda (restricción de origen vieja). Ahora las pruebas lo corren sobre una base con datos.
- Grupos de WhatsApp por la API: se decidió **no** hacerlos (máximo 8 personas, check verde obligatorio, no en coexistencia).
- Pruebas: idiomas 43, lógica 739, Panel Central 163, pantallas 360, todo bien.

## 💳 Cobro con tarjeta por Pago Plux (28 oct 2026, tarde)
Pedido de Dimitry: "cobros con Plux (se requiere inscripción en Plux; nosotros les damos toda la información)".
- Misma integración probada de TuPass (API de tarjetas v1.3, cifrado en el navegador, OTP, 3D Secure, aviso de pago), pero **con las credenciales de cada negocio, por número**.
- Página de pago por pedido `/pagar.php?p=…` (valor exacto); regreso 3DS `/pagar.php` sin parámetros; aviso de pago `/plux.php` (usuario `tukan<N>` + clave, o id de cliente + clave secreta de Plux).
- Al pagar: pedido → *Pagado*, "Recibimos tu pago" por WhatsApp al cliente y aviso en el celular al equipo. La tarjeta nunca se guarda.
- En **Más → Cobros**: guía de inscripción paso a paso, cuadro "Datos para darle a Plux", los 4 datos, Pruebas/Producción y "Cobrar con tarjeta usando" (Plux o Payphone).
- Guía para entregar al cliente: `docs/GUIA_PLUX.md`. Manual: capítulo 7.
- Arreglado de paso: las notas (chat, pedido, cliente) y respuestas rápidas mostraban marcas `<!--notr-->` dentro de la caja de texto.
- Pruebas: lógica 684, Panel Central 163, pantallas 325 (con un Plux FALSO), todo bien; y un pago real en el navegador (Chromium) con la página de pago: rechazo, código OTP y aprobado.
- Pendiente: cada negocio debe inscribirse en Plux y Plux debe **certificar** su instalación antes de pasar a Producción.

✅ **Verificación de acceso de Tech Provider: ENVIADA el 28 oct 2026, 10:36 (por Leonel Fierro) → "En revisión"** (Meta responde en unos 5 días).

## 🛒 Todo incluido + agente con IA (28 oct 2026)
Decisión de Dimitry: **ya no va por módulos**. Un solo plan **Todo incluido (unos $20/mes)**; lo único aparte es la **IA (unos $5 más)**. Precios en `src/Modulos.php`. En piezas de venta no se publican precios (regla de la marca).

Hecho (tomado de la revisión de Mercately, todo menos Instagram/Messenger):
- **Agente con IA que contesta solo** (Más → Agente con IA): 24 h o fuera de horario, usa "Lo que sabe la IA" + catálogo, arma el carrito, confirma el pedido y manda cómo pagar, pasa a una persona, se calla cuando alguien del equipo contesta.
- **Reparto automático y supervisor** (Más → Equipo y reparto): a mano, por turnos o al de menos carga; reglas por tema; disponible sí/no; tablero de chats esperando; pasar chats.
- **Catálogo** con fotos, stock y CSV; **carrito y pedidos** (menú Pedidos); **cobros** con Payphone (enlace por pedido), enlace fijo o transferencia; **cotización PDF**.
- **Chatbots por pasos** con botones y listas (Más → Chatbots).
- **Campos propios** de contactos (Más → Campos de contactos).
- **Origen por anuncio** (clic a WhatsApp) en el chat y en Reportes.
- **Shopify y WooCommerce**: avisos de pedido y envío; carritos abandonados de Shopify (cron cada minuto).
- **App instalable con avisos** (Web Push) para cada persona del equipo.
- Manual nuevo: `docs/manual/manual.html` y **`docs/Manual_WhatsApp_Tukan.pdf`** (18 páginas, con capturas).
- Presentación de ventas en Claude (Slides): "WhatsApp Tukán — Presentación de ventas" (14 diapositivas).
- Pruebas (Meta, IA, Payphone y avisos simulados): **idiomas 43, lógica 635, Panel Central v2 163 y pantallas 286, todo bien**.

Para activarlo en el servidor: actualizar (línea de **Actualizar** abajo) y luego, por cada número, los 7 pasos de "Configurar lo nuevo" del README.

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
- (10 nov 2026) Ya tiene `registrar_pago` (tabla `pagos_plan`); sigue sin `finanzas` ni `cotizar`. Ver «Pagar el plan solo, con tarjeta» al final.

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
Meta responde **"Este número no cumple los requisitos para registrarse o migrar" (#3441034)** y **no ofrece** la opción de coexistencia (QR) para el +593 98 366 2181. Se probó de 3 formas (botón QR, Meta for Developers y crear cuenta de mensajes en el portafolio): Meta no deja usar ese número en la API por ahora.

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
2. Ventana de Meta: Continuar → Qfproducciones → número 0983662181 → debe ofrecer **conectar la app WhatsApp Business** → escanear el QR con el celular → aceptar compartir historial → **Finalizar**.
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
- Ofrecer WhatsApp Tukán (todo incluido) junto a los programas Tukán. Para venderlo a otros negocios falta: terminar la **revisión de la app** de Meta y que Meta apruebe la **verificación de Tech Provider** (enviada el 28 oct 2026, en revisión).
- Traducir al inglés, francés, portugués e italiano las pantallas nuevas (hoy salen en español).

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

## Precios del 10 nov 2026 (decisión de Dimitry, Excel «Precios y planes Tukán»)
- **Todo incluido: $15 al mes** (antes $20). **Todo incluido + IA: $20 al mes** (antes $25). Más IVA.
- La IA como extra sigue en $5. Meta se cobra aparte, directo a la tarjeta del cliente.
- Los precios están en `src/Modulos.php` (`PLANES`). El Panel Tukán Central los lee de ahí.
- Solo mensual (sin semestral ni anual).

## Pagar el plan SOLO, con tarjeta, desde «Mi cuenta» (10 nov 2026, pedido de Dimitry)
**Para qué:** que el cliente (en demo, pagando o con la cuenta vencida) elija su plan y pague con tarjeta sin que Dimitry le mande el enlace.

**Cómo queda para el cliente**
- En **Mi cuenta → Tu plan** (solo el administrador, solo en la web) salen los dos planes (Todo incluido / Todo incluido + IA, con el precio de
  `src/Modulos.php` «al mes + IVA» y el total con IVA) y el botón **Pagar con tarjeta**. Es **suscripción mensual**: se cobra sola cada mes. No hay 6 meses ni año.
- Lo manda a la página segura de pagos (Tukán Pagos). Al volver: «¡Pago recibido!» o «El pago no se completó».
- Cuenta **demo**: al aprobarse queda como cliente activo, un mes desde el día del pago, con el plan que pagó.
- Cuenta **vencida o demo terminada** (ya no entra): el mismo botón sale en la pantalla «Cuenta no disponible» (sigue también el WhatsApp de Tukán).
- Con una suscripción viva: se ve su estado (hasta cuándo está pagada) y NO se ofrece otra. Un cobro que no se terminó de pagar se puede «Terminar» o «Quitar».
- **Cancelar mi plan** ahora también cancela la tarjeta en el Panel (para que Plux no cobre el mes siguiente). Si no se logra cancelar la tarjeta, se le dice y NO se marca como cancelado.
- En modo de prueba (sandbox) avisa: «modo de prueba: no se cobra de verdad».
- Dentro de la **app de Android** no aparece nada de esto (regla de Google); tampoco en la cuenta de Tukán, ni en una suspendida, ni en la cuenta de muestra de Google.
- Si el Panel no contesta o el pago con tarjeta está apagado para WhatsApp Tukán: Mi cuenta queda **exactamente como antes**.

**Cómo está hecho**
- `src/PagosCentral.php`: pide al Panel (`POST {TUKAN_CENTRAL_URL}/plux_api.php`, token v2 «plux» firmado con `TUKAN_CENTRAL_SECRET`) `estado`, `crear`
  (suscripción mensual, `subtotal` SIN IVA), `cancelar` y `cancelar_plan`. WhatsApp Tukán no habla con Plux para esto ni guarda tarjetas.
- `app/vistas/_pago_tarjeta.php` (se incluye en `cuenta.php` y `bloqueada.php`); acciones `pagar_tarjeta` y `cancelar_tarjeta` en `public/index.php`.
- **Nuevo en el conector v2: `registrar_pago`** (`src/TukanCentralV2.php`, antes no existía). El Panel lo llama solo cuando Plux aprueba (y Dimitry lo puede
  usar a mano desde la ficha del cliente: botón «Registrar pago»). Solo mensual. Demo o vencida: un mes desde el pago; al día: suma un mes a su vencimiento.
  Pone el plan pagado, borra un pedido de cancelar pendiente y guarda el pago en la tabla nueva `pagos_plan` (sale en la ficha del Panel). Una suspendida sigue suspendida.
  `capacidades` ahora declara `registrar_pago` y las formas de pago (`plux`, tarjeta, transferencia…).
- Pruebas: Panel simulado `tests/panel_simulado.php` (puerto 8095). Resultado del 10 nov: idiomas 43, lógica 778, TuFactura 29, Panel Central v2 223 (antes 163),
  pantallas 439 (antes 394), app de Android 44. Todo bien.

**Para que funcione de verdad (Dimitry, en el servidor y en el Panel)**
1. Actualizar WhatsApp Tukán en el servidor (corre `db/schema.sql`: crea la tabla `pagos_plan`).
2. En el Panel Tukán Central → Programas → WhatsApp Tukán → **Probar conexión** (para que lea que ya acepta `registrar_pago`).
3. En el Panel → Más → **Pagos con Plux**: marcar WhatsApp Tukán entre los programas que cobran con tarjeta, y tener en Plux los planes de los dos
   valores con IVA ($17,25 y $23,00 mensual). Mientras eso no esté, Mi cuenta queda como antes.

**Falta / por decidir**
- Los textos nuevos están solo en español (el botón 🌐 no los traduce todavía).
- Un cliente con precio especial no existe en WhatsApp Tukán (los precios son fijos): si algún día se acuerda un precio distinto, hay que cobrarlo desde el Panel.
- Probado con un Panel simulado, no contra el Panel ni Plux de verdad: hacer un pago de prueba en sandbox antes de anunciarlo.

### Segunda parte (10 nov 2026, tarde): cambios de plan o extras con suscripción con tarjeta
Decisión de Dimitry: la suscripción se ajusta sola cuando cambia; si sube, se prorratea; no puede bajar durante el plan.
- El único lugar del programa donde cambian el plan o los extras es **Clientes de Tukán → ficha del cliente** (botones de plan y «Guardar extras»).
  El cliente no cambia módulos solo (en Mi cuenta solo los ve). Los cambios hechos desde el Panel (cambiar_plan / cambiar_modulos) los ajusta el Panel.
- `PagosCentral::revisarCambio()` antes de cambiar: si el valor mensual (plan + extras, `Modulos::detalle`) **baja** y el cliente tiene una suscripción
  con tarjeta **pagada** (activa o en gracia), se rechaza: «Los módulos ya contratados no se pueden quitar mientras dure el período pagado (hasta el dd/mm/aaaa)».
  Si el Panel no contesta, se usa el último pago con tarjeta guardado aquí (`pagos_plan`, método plux) para saber si el período sigue.
- `PagosCentral::ajustarSuscripcion()` después: si el valor cambió y tiene suscripción pagada, manda `cambiar` al Panel con el valor mensual nuevo
  sin IVA. Aviso: «La suscripción con tarjeta queda en $X + IVA desde el próximo cobro. Por los días que faltan se cobra aparte $Y: enlace».
  El cliente ve ese cobro proporcional en Mi cuenta («Terminar el pago»). Si el valor no cambia, no se manda nada. Panel caído: el cambio se hace
  y se avisa «se ajustará cuando el Panel responda» (sin reintento automático: revisarlo en el Panel). Primera cuota sin pagar: no se manda.
- Panel simulado con `cambiar` como el real (sube con prorrateo, 409 no_baja, igual, 409 sin_pagar).
- Pruebas: idiomas 43, lógica 778, TuFactura 29, Panel Central v2 230, pantallas 451, app de Android 44. Todo bien.

## 13 nov 2026 — App de iPhone: sin precios ni pagos (regla de Apple), se reconoce por TukanIOS
Regla SIEMPRE de Dimitry (12 nov 2026, regla 3.1.1 de la App Store): dentro de la app de iPhone no se ve ningún precio de lo que vende Tukán
(plan, agente con IA) ni botones, enlaces o formularios para pagar, contratar, pedir, mejorar o cancelar el plan, ni a quién escribir para eso.
- Se reconoce por «TukanIOS» en el User-Agent o por la cookie `canal=ios` que deja `?app=ios` (u `?origen=ios`). Funciones `en_app_ios()`,
  `en_app_tienda()` y `NO_EN_APP_IOS` en `app/bootstrap.php`. En iPhone también se llama «Tukán Mensajes».
- Lo que se escondía para Google Play ahora usa `en_app_tienda()`; solo en iPhone: sin Cancelar/Seguir con mi plan, sin «escribe a Tukán» para más
  usuarios o números, cuenta no disponible neutra. El servidor rechaza pagar con tarjeta y cancelar/seguir con el plan.
- `class="tukan-ios"` en `<body>`. Catálogo, pedidos, cotizaciones y cobros del negocio siguen igual.
- Archivos: `app/bootstrap.php`, `app/vistas/_diseno.php`, `bloqueada.php`, `cuenta.php`, `lineas.php`, `login.php`, `mas.php`, `public/index.php`,
  `src/Mensajeria.php`. Prueba nueva: `tests/prueba_app_iphone.sh` (en `tests/correr.sh`). Docs: `docs/GOOGLE_PLAY.md`, manual, README.
- **Falta:** regenerar el PDF del manual en una máquina con el generador.

## 16 nov 2026 — Arreglo: "No se pudo crear la carpeta de archivos" al adjuntar
- Causa: el instalador hace `chown -R qf:www-data` de todo el programa; las carpetas de cada cuenta en `archivos/` las crea www-data con 0750, y después de actualizar www-data ya no podía escribir (no se podía adjuntar ni guardar fotos/audios que llegan).
- Arreglo: `Archivos::guardarBytes` crea carpetas 2770 y archivos 660 (umask 0007); `deploy/instalar.sh` repara los permisos de `archivos/` en cada actualización. Prueba nueva en prueba_logica.

## 24 nov 2026 — Chatbot «Tienda gráficos vMix» en el número de Tukán
Pedido de Dimitry: «asegúrate que el chatbot tenga uno para la tienda, no había». La Tienda Tukán (tienda.tukansoftware.com) estrenó el botón
«¿Dudas? Escríbenos», que abre WhatsApp al 098 366 2181 con «Hola, tengo una duda sobre los gráficos para vMix de la Tienda Tukán: …»
(y desde Mis compras «Hola, compré en la Tienda Tukán (pedido N° …) y tengo una duda: …»). Antes esos mensajes caían en el menú general.
- `src/ChatbotsTukan.php`: 5.º chatbot **Tienda gráficos vMix**, en 5 idiomas, sin IA. Arranca con vmix, gráficos (para) vmix, marcador(es) vmix,
  títulos vmix, tienda tukan, tienda de gráficos, utc (edición) qf, diseñador qf, «compré en la tienda» (y en en/pt/fr/it). «tienda» suelta no lo arranca.
  Menú: Qué trae y precio (8 estilos × 7 deportes, USD 12 + IVA, 6 meses de soporte, tarjeta de socio) · Cómo comprar · Ya compré: ayuda
  (pide pedido o correo y qué pasa → persona) · Cómo se instala (enlace /instalar y resumen) · Hablar con asesor.
- «Precios y planes»: opción nueva **Gráficos vMix** (p24) con el mismo resumen (10 opciones en la lista).
- Sin la palabra «gratis» (regla de la prueba): el panel UTC va «incluido» y el de socio «de regalo».
- Instalar en el VPS-1: actualizar tuwhatsapp y correr `sudo php /var/www/tuwhatsapp/deploy/chatbots_tukan.php` (crea el nuevo y vuelve a poner los textos de fábrica de los otros 4).
- Pruebas: chatbots Tukán 1042 (antes 902), suite completa TODO BIEN.
