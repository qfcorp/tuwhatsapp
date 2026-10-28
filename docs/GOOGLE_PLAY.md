# App de Android «Tukán Mensajes» (Google Play)

La app abre este sistema a pantalla completa (Trusted Web Activity). Se compila en el repositorio `qfcorp/tukan-apps`
(app `tuwhatsapp`, paquete `com.tukansoftware.tuwhatsapp`). Guía completa para publicar: `tukan-apps/tienda/tuwhatsapp/PUBLICAR_TUWHATSAPP_CON_CLAUDE.md`
(textos de la ficha y respuestas de Contenido de la app: `tukan-apps/tienda/tuwhatsapp/TEXTOS_GOOGLE_PLAY.md`).

| Qué | Dónde |
|---|---|
| Archivo que Android revisa para abrir sin barra de navegador | `public/.well-known/assetlinks.json` |
| Bloque de nginx que lo muestra | `deploy/instalar.sh` (marca `tuwhatsapp-app-android`) y `deploy/app_android.sh` (lo revisa y lo pone si faltara) |
| Política de privacidad (pública, con cómo borrar la cuenta y los datos) | `public/privacidad.php` y `public/eliminar-datos.php` → https://whatsapp.tukanec.com/privacidad.php |
| Modo app (sin precios del programa ni pagos por fuera) | `en_app_android()` y `cobro_google_play()` |
| Cuenta para el revisor de Google | `deploy/demo_google.php crear | borrar` y `src/DemoGoogle.php` |
| Pruebas | `tests/prueba_app_android.sh` (dentro de `tests/correr.sh`) |
| Capturas para la tienda | `tests/capturas_tienda.sh` (programa LOCAL con datos de ejemplo, nunca el servidor real) |

## Modo app
La app abre `/?r=bandeja&app=play`: el parámetro deja la cookie `tuwa_app` (1 año) y `en_app_android()` sabe que es la app. Dentro de la app:
- El programa se llama **Tukán Mensajes** (título, barra de arriba y pantalla de entrar): Google no deja usar la marca «WhatsApp» como nombre propio
  (`nombre_programa()`). En la web sigue siendo «WhatsApp Tukán».
- **Más → Agente con IA** (si la cuenta no lo tiene) dice «No está activo en tu cuenta» sin el enlace «Pedir a Tukán».
- **Cuenta no disponible** (vencida o suspendida) no muestra el botón «Escribir a Tukán por WhatsApp» para renovar.
- Un extra apagado dice «no está activo en tu cuenta» (sin «Escribe a Tukán para activarlo»).
- Lo que el **negocio** vende a sus clientes (catálogo, pedidos, enlaces de pago, Plux) y el costo de Meta se ven igual: no son pagos del programa.

## En el servidor (qfcorp, usuario qf)

```bash
curl -fsSL https://raw.githubusercontent.com/qfcorp/tuwhatsapp/main/deploy/instalar.sh -o /tmp/instalar_tuwhatsapp.sh && bash /tmp/instalar_tuwhatsapp.sh
```
```bash
sudo bash /var/www/tuwhatsapp/deploy/app_android.sh
```
El segundo debe terminar en `OK: la app de Android ya puede verificar el sitio.`

## Cuenta de demostración para el revisor de Google

```bash
php /var/www/tuwhatsapp/deploy/demo_google.php crear
```
Crea la **Tienda Muestra Tukán**, una cuenta de **muestra** aparte (`empresas.muestra = true`, demo de 180 días, plan Todo incluido **sin IA**,
términos ya aceptados) con el administrador `revisor.google@tukanec.com`, un agente de ejemplo, un «número» de ficción (`phone_number_id` que empieza con
`muestra-`, sin token), 8 contactos con números de ficción (+1 202 555 01xx), conversaciones, 6 productos, 3 pedidos, pendientes, respuestas rápidas y un seguimiento.
- `src/Meta.php` **nunca llama a Meta** con un número `muestra-…` (enviar, subir o bajar archivos, plantillas): «Cuenta de demostración: no se envía nada a WhatsApp (Meta)».
- `public/index.php` no deja, en una cuenta de muestra, conectar números (a mano o con el QR), cambiar la contraseña o el equipo (Mi cuenta), ni poner
  claves de cobro, tiendas o avisos a otros sistemas.
- La contraseña sale **una sola vez** (`Demo-` + 6 números). Nunca toca otra cuenta: si ese correo es de una cuenta que no es de muestra, el script se niega.

Cuando Google apruebe (o para dejar de usarla):
```bash
php /var/www/tuwhatsapp/deploy/demo_google.php borrar
```

## Después de subir la app a Google Play por primera vez (MUY importante)

Google vuelve a firmar la app con **su propia llave**. Hay que agregar esa huella o la app bajada de Google Play se abre con barra de navegador.

1. Play Console (cuenta fierrodimitry@gmail.com → «tukan software») → Tukán Mensajes → **Prueba y lanzamiento → Configuración → Integridad de la app → Firma de apps**.
2. Copia el **Certificado SHA-256** de la «Clave de firma de apps».
3. Pídeselo a Claude (*"Agrega esta huella de Google al assetlinks de tuwhatsapp: ..."*) o, desde el celular, en GitHub abre
   `qfcorp/tuwhatsapp/public/.well-known/assetlinks.json` → lápiz → deja el archivo así, cambiando solo `HUELLA_DE_GOOGLE` → **Commit changes**:

```json
[{
  "relation": ["delegate_permission/common.handle_all_urls"],
  "target": {
    "namespace": "android_app",
    "package_name": "com.tukansoftware.tuwhatsapp",
    "sha256_cert_fingerprints": [
      "39:0C:08:10:D4:40:C1:71:C4:30:C8:D0:F1:93:7B:8F:E4:37:03:25:27:2E:B2:F6:2E:4E:A6:5E:01:98:40:DC",
      "HUELLA_DE_GOOGLE"
    ]
  }
}]
```
4. En el servidor, los dos comandos de arriba (instalar y app_android). `app_android.sh` revisa que el JSON esté bien escrito antes de tocar nada.
5. Revisa: https://whatsapp.tukanec.com/.well-known/assetlinks.json debe mostrar las dos huellas.

## Cobro con Google Play (APAGADO en la versión 1.0.0)

La versión 1.0.0 es solo para entrar con la cuenta que Tukán le da al cliente: **no** hay precios del programa, planes, botones de compra
ni enlaces para pagar afuera dentro de la app. Queda preparado con dos interruptores apagados:
- Servidor: `TUWA_GOOGLE_PLAY_COBRO=1` en `config/.env` prende `cobro_google_play()` (el instalador lo conserva). Hoy ninguna pantalla lo usa todavía.
- App: `"cobroGooglePlay": true` en `tukan-apps/apps/tuwhatsapp/app.json` agrega Google Play Billing a la app (versión nueva).

Antes de prenderlo falta programar la pantalla de planes (solo dentro de la app) y la verificación de compras con la API de Google Play
(cuenta de servicio). Pasos y lo que cambia en Play Console: guía de publicación, sección «Más adelante: cobro con Google Play».
