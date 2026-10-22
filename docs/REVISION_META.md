# Revisión de la app de Meta — WhatsApp Tukán ("Tukan Mensajes")

Para vender WhatsApp Tukán a otros negocios, Meta tiene que aprobar 3 permisos de la app: **whatsapp_business_messaging**, **whatsapp_business_management** y **public_profile**.

Página: developers.facebook.com → Mis apps → **Tukan Mensajes** → **Revisar → Revisión de la app**.

## Estado al 22 oct 2026

| Paso | Estado |
|---|---|
| Verificación del negocio | ✅ Lista |
| Llamadas de prueba a la API | ✅ Completadas |
| Plataforma "Sitio web" (https://whatsapp.tukanec.com/) | ✅ La agregó Claude |
| Descripción del negocio y texto del permiso de mensajes | ✅ Escritos por Claude (borrador, en la ventana abierta) |
| **Ícono de la app** (1024×1024) | ⬜ Lo sube Dimi |
| **Video 1** (mensajes) | ⬜ Lo graba Dimi |
| **Video 2** (plantillas y conectar número) | ⬜ Lo graba Dimi |
| Texto del permiso whatsapp_business_management | ⬜ Pegar el de abajo |
| public_profile | ⬜ Marcar la casilla de confirmación |
| Tratamiento de datos | ⬜ Lo responde Dimi (respuestas sugeridas abajo) |
| Instrucciones para revisores, con cuenta de prueba | ⬜ Crear la cuenta y pegar el texto de abajo |
| **Enviar para revisión** | ⬜ Lo toca Dimi al final (después ya no se puede editar) |

Meta suele responder entre 1 y 7 días hábiles.

---

## 1. Ícono de la app

Ve a **Configuración de la app → Básica → Ícono de la app** → "elige un archivo de tu dispositivo" → `icono_tukan_mensajes.png` → **Guardar cambios**.

## 2. Cómo grabar los videos (Windows)

1. Presiona **Windows + Shift + R**. Se abre la grabación de la Herramienta Recortes.
2. Marca toda la pantalla y toca **Iniciar**.
3. Al terminar, toca el botón rojo de detener y luego **Guardar** (queda un .mp4).

Los videos deben durar entre 1 y 3 minutos, ir sin sonido y verse claros. Abre el programa con el zoom del navegador al 110 %. Ten **WhatsApp Web** con tu número personal abierto en otra ventana, para que se vea cuando llega el mensaje.

### Video 1: whatsapp_business_messaging (enviar y recibir)

1. Abre https://whatsapp.tukanec.com y entra con tu usuario.
2. Abre la **Bandeja** → toca la conversación del número de prueba.
3. Escribe: `Hello! This message is sent from WhatsApp Tukán` → toca **Enviar por el sistema**.
4. Pasa a la ventana de **WhatsApp Web** (o muestra el celular): se ve el mensaje que llegó.
5. Desde WhatsApp Web responde `Thanks, received` → vuelve al programa → aparece en el chat.
6. Opcional: toca **📎 Adjuntar**, manda una foto y muestra que llega.

### Video 2: whatsapp_business_management (cuenta y plantillas)

1. En el programa toca el ícono de la **hoja** (Plantillas) → **Crear plantilla**.
2. Nombre `appointment_reminder`, categoría **Utilidad**, idioma **Inglés (EE. UU.)**, texto: `Hi {{1}}, this is a reminder of your appointment on {{2}}.` → **Enviar a Meta para aprobar**.
3. Muestra que aparece en la lista como **En revisión**.
4. Ve a **Más → Números conectados** → muestra el número conectado (nombre, número y estado).
5. Toca **Conectar otro número** → **Conectar con WhatsApp Business** → muestra que se abre la ventana de Meta para conectar una cuenta (Embedded Signup). Ciérrala sin terminar.

## 3. Textos para pegar (en inglés, que es lo que leen los revisores)

### whatsapp_business_messaging (Claude ya lo escribió en el borrador)

Por si se borra, aquí está completo:

```
WhatsApp Tukán (https://whatsapp.tukanec.com) is a shared inbox for small businesses in Ecuador (salons, hotels, event venues, retail). Each business connects its own WhatsApp Business number through Embedded Signup (Tech Provider) and its team answers customers from our web panel.

We use whatsapp_business_messaging to:
1. Receive the messages that customers send to the business (text, photos, voice notes, documents, button replies) through our webhook and show them in the business inbox.
2. Send replies written by a person of the business team (text, photos, documents, voice notes), always inside the 24-hour customer service window.
3. Send approved message templates when the 24-hour window is closed (for example appointment or payment reminders), only to customers who have contacted the business; marketing templates are sent only to contacts that opted in, and anyone who replies STOP/BAJA is excluded.
4. Send an optional automatic menu with reply buttons outside business hours, that the business configures (it never answers personal contacts).
5. Mark messages as read and download/upload media of the conversation.

Value for the user: one inbox for the whole team, with each conversation assigned to a person, quick replies, and the cost of every message shown before sending, with a monthly spending cap. We do not sell or share message data; it is only used to provide the inbox to the business that owns the number.
```

### whatsapp_business_management

Descripción del negocio: `Tukán Software (Ecuador) hace programas para pequeños negocios; WhatsApp Tukán es su bandeja para atender clientes con la API oficial de WhatsApp.`

```
WhatsApp Tukán is a Tech Provider app. Small businesses connect their own WhatsApp Business Account to our inbox with Embedded Signup (the "Connect with WhatsApp Business" button), including coexistence with the WhatsApp Business app.

We use whatsapp_business_management to:
1. Complete Embedded Signup: read the WhatsApp Business Account (WABA) and phone numbers the business shared with us, subscribe our app to the WABA webhooks and register the phone number for Cloud API.
2. Manage message templates of the business from our panel: list templates with their status (approved, pending, rejected with reason), create new templates for review and delete templates the business no longer uses.
3. Read the phone number information (display number, verified name, quality rating) to show the connection status to the business.
4. For numbers connected in coexistence mode, request the one-time sync of contacts and chat history so the inbox shows the existing conversations.

We only access the accounts each business connects itself, and each business only sees its own data in our panel.
```

### public_profile

Solo marca la casilla de confirmación y guarda. Si pide texto:

```
Used only to identify the Facebook user who completes Embedded Signup to connect their WhatsApp Business Account. We do not store or use profile data for any other purpose.
```

### Instrucciones para revisores

Primero crea la cuenta de prueba en el programa: ícono del **edificio** → **Clientes de Tukán** → crear cliente **"Meta Review"**, estado **Demo**, vence en 30 días, 1 número y 2 usuarios, correo `review@tukanec.com`. Copia la contraseña que aparece (se muestra una sola vez) y pégala abajo en lugar de CONTRASEÑA.

```
Website: https://whatsapp.tukanec.com
Test account (business admin): review@tukanec.com / CONTRASEÑA

Steps:
1. Log in. Accept the terms (first login only).
2. You will see "Connect your number". Click "Conectar con WhatsApp Business" to start Embedded Signup with your test WhatsApp Business Account. After finishing, the number appears in "Más > Números conectados" and our app is subscribed to its webhooks.
3. Inbox ("Bandeja"): messages sent to that number appear here. Open a conversation, write a reply and click "Enviar por el sistema" (whatsapp_business_messaging). Photos and documents can be sent with "Adjuntar".
4. Templates (sheet icon at the top): create a template and send it to Meta for review; the list shows the status of each template (whatsapp_business_management).
The interface is in Spanish: "Bandeja" = Inbox, "Enviar" = Send, "Plantillas" = Templates, "Números" = Phone numbers.
```

## 4. Tratamiento de datos (respuestas sugeridas; tú decides)

1. **¿Tienes encargados del tratamiento de datos?** → **Sí**:
   - **Cloudflare, Inc.**: el túnel por donde pasan los datos para llegar al servidor.
   - **Anthropic, PBC**: solo si el negocio usa el asistente con IA; lee el texto de la conversación para sugerir una respuesta.
   - Si prefieres no declarar la IA, hay que dejarla apagada.
2. **¿Quién es el responsable de los datos?** → el **nombre legal** de tu empresa, tal como está en el RUC y en la verificación del negocio de Meta.
3. **País** → **Ecuador**.
4. **¿Diste datos a autoridades por seguridad nacional en los últimos 12 meses?** → **No.**
5. **Procesos ante pedidos de autoridades** → marca los cuatro primeros: revisión de la legalidad, poder recusar, minimización de datos y documentación.

---

## Después de la aprobación

- Pasar a **acceso avanzado**: los otros negocios ya pueden conectar su número con el botón.
- Reintentar el código QR con el número de Tukán (+593 99 640 8397).
- Poner el método de pago en WhatsApp Manager cuando se conecte el número real.
