# Guía para cobrar con tarjeta por Pago Plux en WhatsApp Tukán

Esta guía la entrega Tukán a cada negocio que quiere cobrar con tarjeta de crédito o débito.
El dinero cae en la cuenta de Plux **del negocio**. Tukán no toca el dinero.

## Qué consigue el negocio
- Cada pedido tiene su **página de pago** con el valor exacto: `https://whatsapp.tukanec.com/pagar.php?p=…`
- El cliente paga con su tarjeta (con el código del banco o la validación 3D Secure si el banco lo pide).
- El pedido pasa **solo** a *Pagado*, al cliente le llega "Recibimos tu pago" por WhatsApp y al equipo un aviso en el celular.
- Si Plux avisa el pago por su cuenta (aviso de pago o *web hook*), también se marca solo.

## Paso 1. Abrir la cuenta en Plux
1. Entrar a **https://www.plux.ec/contactos/** y pedir abrir una cuenta de **Pago Plux** como comercio.
   Soporte de Plux: **099 845 2058**. Plux dice la lista exacta de documentos del negocio.
2. Elegir el plan con Plux. Lo que publica plux.ec (octubre 2026), a confirmar con Plux:
   | Plan | Arranque | Mensual | Por pago |
   |---|---|---|---|
   | Porcentual | $99 + IVA | — | crédito 1,50 % · débito 0,50 % + IVA |
   | Fijo | $99 + IVA | $49,99 + IVA | $0,40 + IVA |
3. Decirle a Plux: **"Voy a cobrar con la integración por API de tarjetas (credentials/paymentCardResource, guía v1.3) de WhatsApp Tukán"**.

## Paso 2. Datos que se le dan a Plux
Salen listos en **WhatsApp Tukán → Más → Cobros y cotizaciones → Pago Plux → "Datos para darle a Plux"** (cada número tiene su usuario y clave):

| Dato | Valor |
|---|---|
| Plataforma | WhatsApp Tukán (Tukán Software, QFCORP S.A.S.) |
| Integración | API de tarjetas v1.3 (credentials/paymentCardResource) con OTP y 3D Secure |
| Dominio | `whatsapp.tukanec.com` |
| Página de pago | `https://whatsapp.tukanec.com/pagar.php?p=…` (una por pedido) |
| Regreso del banco (3D Secure) | `https://whatsapp.tukanec.com/pagar.php` (**sin parámetros**) |
| Aviso de pago (Web Hook) | `https://whatsapp.tukanec.com/plux.php` · usuario `tukan<N>` · clave (la que muestra Cobros) |
| Manejo de la tarjeta | Los datos se cifran en el navegador del comprador (AES-256-ECB con una clave al azar de 32 caracteres; esa clave se cifra con la llave pública RSA de Plux, PKCS#1) y van cifrados a Plux. WhatsApp Tukán no guarda el número, la fecha ni el CVV en ninguna base de datos ni registro. |
| Montos | Valor final: con IVA en `baseAmount12`, sin IVA en `baseAmount0` (Plux no suma IVA) |
| Referencia | `description` empieza con `WT-<id del pedido>` |

## Paso 3. Poner los datos de Plux en WhatsApp Tukán
Plux entrega 4 datos. En **Más → Cobros y cotizaciones → Pago Plux**:
1. **Id de establecimiento**
2. **Id de cliente**
3. **Clave secreta** (se guarda cifrada)
4. **Llave pública** (se pega completa, en una línea o con BEGIN/END)

Dejar **Ambiente: Pruebas**, elegir **Cobrar con tarjeta usando: Pago Plux** y **Guardar**.

## Paso 4. Registrar el aviso de pago en Plux
En el panel de Plux → **Developers → Web Hook**: la dirección `https://whatsapp.tukanec.com/plux.php` con el **usuario** y la **clave** que muestra Cobros.
(También funciona si Plux firma el aviso con el id de cliente y la clave secreta del negocio.)

## Paso 5. Certificación y producción
1. Hacer pagos de prueba en **Pruebas** con las tarjetas de prueba que da Plux (aprobada, rechazada, con código OTP y con 3D Secure).
2. Plux revisa y **certifica** la instalación del negocio.
3. Cuando Plux confirme, cambiar en Cobros **Ambiente: Producción**. Desde ahí cobra de verdad.

## Si algo falla
- En Cobros sale un aviso rojo si Plux rechazó las credenciales: revisar los 4 datos y si está en Pruebas o en Producción
  (las credenciales de pruebas no sirven en producción, ni al revés).
- En la ficha del pedido se ve: "Pagado con tarjeta por Plux · transacción …" o en qué paso quedó el cliente.
- Máximo 12 intentos de pago por pedido en una hora (para frenar abusos).

## Para Tukán (técnico)
- Código: `src/Plux.php`, `public/pagar.php` (página, cobro y regreso 3DS), `public/plux.php` (aviso de pago), `public/assets/plux.js`.
- Es la misma integración probada en TuPass (qfcorp/tupass), con las credenciales por número.
- Pruebas: `tests/plux_falso.php` (Plux falso) y `tests/plux_tarjeta.php`; `bash tests/correr.sh`.
