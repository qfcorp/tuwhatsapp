// Pago con tarjeta (Pago Plux) en la página de pago de WhatsApp Tukán. Los datos de la tarjeta se cifran AQUÍ, en el navegador:
//  - AES-256-ECB (CryptoJS) con una clave al azar de 32 caracteres;
//  - esa clave se cifra con la llave pública RSA de Plux (JSEncrypt, PKCS#1) y viaja en la cabecera simetricKey.
// El servidor solo reenvía lo cifrado a Plux: nunca ve ni guarda el número, la fecha ni el CVV.
(function () {
  var caja = document.getElementById('plux-caja');
  if (!caja) return;
  var estado = {}, ocupado = false;
  function $(id) { return document.getElementById(id); }
  function msg(t, tipo) { var m = $('plux-mensaje'); m.className = 'aviso aviso-' + (tipo || 'info'); m.textContent = t; }
  function claveAlAzar() {
    var c = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789', r = '', a = new Uint32Array(32);
    window.crypto.getRandomValues(a);
    for (var i = 0; i < 32; i++) r += c.charAt(a[i] % c.length);
    return r;
  }
  function aes(t, k) {
    return CryptoJS.AES.encrypt(CryptoJS.enc.Utf8.parse(String(t)), CryptoJS.enc.Utf8.parse(k), { mode: CryptoJS.mode.ECB, padding: CryptoJS.pad.Pkcs7 }).toString();
  }
  function luhn(n) { var s = 0, d = false; for (var i = n.length - 1; i >= 0; i--) { var x = +n[i]; if (d) { x *= 2; if (x > 9) x -= 9; } s += x; d = !d; } return s % 10 === 0; }
  function preparar() {
    var num = $('plux-numero').value.replace(/\D/g, ''), mes = $('plux-mes').value, anio = $('plux-anio').value, cvv = $('plux-cvv').value.replace(/\D/g, '');
    if (num.length < 13 || num.length > 19 || !luhn(num)) { msg('Revisa el número de la tarjeta.', 'error'); return null; }
    if (!mes || !anio) { msg('Elige el mes y el año de vencimiento.', 'error'); return null; }
    if (cvv.length < 3) { msg('Escribe el CVV (3 o 4 números de atrás de la tarjeta).', 'error'); return null; }
    if (!$('plux-nombres').value.trim() || !$('plux-apellidos').value.trim()) { msg('Escribe nombres y apellidos del titular.', 'error'); return null; }
    if (!/^\d{10}(\d{3})?$/.test($('plux-cedula').value.replace(/\D/g, ''))) { msg('Escribe la cédula (10 números) o el RUC (13) del titular.', 'error'); return null; }
    if (!/^\S+@\S+\.\S+$/.test($('plux-email').value.trim())) { msg('Escribe un correo válido.', 'error'); return null; }
    var k = claveAlAzar(), rsa = new JSEncrypt();
    rsa.setPublicKey(caja.dataset.llave);
    var kc = rsa.encrypt(k);
    if (!kc) { msg('No se pudo preparar el pago. Recarga la página.', 'error'); return null; }
    estado = {
      card: { number: aes(num, k), expirationYear: aes(anio, k), expirationMonth: aes(mes, k), cvv: aes(cvv, k) },
      buyer: { documentNumber: $('plux-cedula').value.replace(/\D/g, ''), firstName: $('plux-nombres').value.trim(), lastName: $('plux-apellidos').value.trim(),
               phone: $('plux-telefono').value.trim(), email: $('plux-email').value.trim() },
      simetricKeyCifrada: kc
    };
    $('plux-cvv').value = '';   // el CVV no se queda en la pantalla
    num = cvv = null;
    return estado;
  }
  function enviar(extra) {
    if (ocupado) return;
    ocupado = true; $('plux-pagar').disabled = true; $('plux-otp-btn').disabled = true;
    msg('Procesando el pago, no cierres esta ventana…', 'info');
    var body = { p: caja.dataset.p, csrf: caja.dataset.csrf, card: estado.card, buyer: estado.buyer, simetricKeyCifrada: estado.simetricKeyCifrada };
    if (extra) body.paramsOtp = extra;
    fetch('/pagar.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body), credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(respuesta)
      .catch(function () { listo(); msg('Error de conexión. Revisa tu internet e intenta de nuevo.', 'error'); });
  }
  function listo() { ocupado = false; $('plux-pagar').disabled = false; $('plux-otp-btn').disabled = false; }
  function respuesta(r) {
    listo();
    if (r.status === 'success') { msg('✅ Pago aprobado. Un momento…', 'ok'); location.href = r.ir || location.href; return; }
    if (r.status === 'otp_required' || r.status === 'otp_incorrecto') {
      if (r.detail) estado.otp = r.detail;
      $('plux-paso-datos').hidden = true; $('plux-paso-otp').hidden = false;
      msg(r.message || 'Escribe el código que te envió tu banco.', r.status === 'otp_incorrecto' ? 'error' : 'info');
      return;
    }
    if (r.status === '3ds_required') {
      var d = r.detail || {};
      msg('Te llevamos a la validación de tu banco…', 'info');
      if (d.customFormChallenge || (d.parameters && d.parameters.length)) {
        var f = document.createElement('form'); f.method = 'POST'; f.action = d.url;
        (d.parameters || []).forEach(function (p) { var i = document.createElement('input'); i.type = 'hidden'; i.name = p.name; i.value = p.value; f.appendChild(i); });
        document.body.appendChild(f); f.submit();
      } else { location.href = d.url; }
      return;
    }
    $('plux-paso-otp').hidden = true; $('plux-paso-datos').hidden = false;
    msg('❌ ' + (r.message || 'El pago no fue aprobado.') + ' Puedes intentar de nuevo o con otra tarjeta.', 'error');
  }
  $('plux-numero').addEventListener('input', function () { var v = this.value.replace(/\D/g, '').slice(0, 19); this.value = v.replace(/(\d{4})(?=\d)/g, '$1 '); });
  $('plux-pagar').addEventListener('click', function () { if (preparar()) enviar(null); });
  $('plux-otp-btn').addEventListener('click', function () {
    var c = $('plux-otp').value.trim();
    if (!c) { msg('Escribe el código.', 'error'); return; }
    var o = estado.otp || {};
    enviar({ idTransaction: o.idTransaction, sessionId: o.sessionId, tkn: o.tkn, tknky: o.tknky, tkniv: o.tkniv, otpCode: c });
  });
})();
