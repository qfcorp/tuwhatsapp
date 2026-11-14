/* WhatsApp Tukán (14 nov 2026): entrar con huella (WebAuthn / llaves de acceso). La huella nunca sale del celular: solo se manda una firma. */
(function () {
  'use strict';
  var TR = function (t) { return (window.TUKAN_TR && window.TUKAN_TR[t]) || t; };
  if (!window.PublicKeyCredential || !navigator.credentials) { return; }

  function aBuf(b64u) {
    var s = atob(String(b64u).replace(/-/g, '+').replace(/_/g, '/') + '==='.slice((String(b64u).length + 3) % 4));
    var a = new Uint8Array(s.length);
    for (var i = 0; i < s.length; i++) { a[i] = s.charCodeAt(i); }
    return a.buffer;
  }
  function aB64u(buf) {
    var b = new Uint8Array(buf), s = '';
    for (var i = 0; i < b.length; i++) { s += String.fromCharCode(b[i]); }
    return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  }
  function csrf() {
    var c = document.querySelector('input[name="csrf"]');
    return c ? c.value : '';
  }
  function pedir(accion, datos, extra) {
    var f = new FormData();
    f.append('csrf', csrf());
    f.append('accion', accion);
    if (datos) { f.append('datos', JSON.stringify(datos)); }
    Object.keys(extra || {}).forEach(function (k) { f.append(k, extra[k]); });
    return fetch('/?r=huella', { method: 'POST', body: f, credentials: 'same-origin' }).then(function (r) { return r.json(); });
  }
  function avisar(caja, texto, malo) {
    if (!caja) { if (malo) { alert(texto); } return; }
    caja.textContent = texto;
    caja.hidden = false;
    caja.className = 'tk-aviso ' + (malo ? 'mal' : 'ok') + ' aviso ' + (malo ? 'aviso-error' : 'aviso-ok');
  }
  function errorHuella(e) {
    return e && (e.name === 'NotAllowedError' || e.name === 'AbortError')
      ? TR('Cancelaste o se venció la huella. Intenta de nuevo.')
      : TR('No se pudo usar la huella. Intenta de nuevo o entra con tu contraseña.');
  }

  /** Activar la huella en este celular (Mi cuenta y la tarjeta «¿Entrar con tu huella la próxima vez?»). */
  function activar(boton) {
    var caja = document.getElementById(boton.getAttribute('data-huella-activar') || 'huella-estado');
    boton.disabled = true;
    avisar(caja, TR('Pon tu dedo en el sensor del celular…'), false);
    var campoClave = document.getElementById(boton.getAttribute('data-huella-clave') || '');
    pedir('opciones_registro', null, campoClave && campoClave.value ? { clave: campoClave.value } : {}).then(function (r) {
      if (!r.ok) { throw new Error(r.error); }
      var o = r.opciones;
      o.challenge = aBuf(o.challenge);
      o.user.id = aBuf(o.user.id);
      o.excludeCredentials = (o.excludeCredentials || []).map(function (c) { return { type: 'public-key', id: aBuf(c.id) }; });
      return navigator.credentials.create({ publicKey: o }).catch(function (e) { throw new Error(errorHuella(e)); });
    }).then(function (cred) {
      return pedir('registrar', { id: cred.id, clientDataJSON: aB64u(cred.response.clientDataJSON), attestationObject: aB64u(cred.response.attestationObject) });
    }).then(function (r) {
      if (!r.ok) { throw new Error(r.error); }
      try { localStorage.setItem('tuwa_huella', '1'); } catch (x) {}
      avisar(caja, r.mensaje || TR('Listo: la próxima vez entras con tu huella.'), false);
      var oferta = document.getElementById('ofrecer-huella');
      if (oferta) { setTimeout(function () { oferta.hidden = true; }, 2500); }
      if (boton.hasAttribute('data-recargar')) { setTimeout(function () { location.reload(); }, 1200); }
    }).catch(function (e) {
      avisar(caja, e.message || TR('No se pudo usar la huella. Intenta de nuevo o entra con tu contraseña.'), true);
      boton.disabled = false;
    });
  }

  /** Entrar con huella (pantalla de entrar). */
  function entrar(boton) {
    var caja = document.getElementById('huella-estado');
    var recordar = document.querySelector('input[name="recordar"]');
    boton.disabled = true;
    avisar(caja, TR('Pon tu dedo en el sensor del celular…'), false);
    pedir('opciones_entrar').then(function (r) {
      if (!r.ok) { throw new Error(r.error); }
      var o = r.opciones;
      o.challenge = aBuf(o.challenge);
      o.allowCredentials = [];
      return navigator.credentials.get({ publicKey: o }).catch(function (e) { throw new Error(errorHuella(e)); });
    }).then(function (cred) {
      var resp = cred.response;
      return pedir('entrar', {
        id: cred.id, clientDataJSON: aB64u(resp.clientDataJSON), authenticatorData: aB64u(resp.authenticatorData),
        signature: aB64u(resp.signature), userHandle: resp.userHandle ? aB64u(resp.userHandle) : ''
      }, { recordar: recordar && !recordar.checked ? '0' : '1' });
    }).then(function (r) {
      if (!r.ok) { throw new Error(r.error); }
      try { localStorage.setItem('tuwa_huella', '1'); } catch (x) {}
      location.href = r.ir || '/';
    }).catch(function (e) {
      avisar(caja, e.message || TR('No se pudo usar la huella. Intenta de nuevo o entra con tu contraseña.'), true);
      boton.disabled = false;
    });
  }

  function marcado(k) { try { return localStorage.getItem(k) === '1'; } catch (x) { return false; } }

  var disponible = PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable
    ? PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable() : Promise.resolve(false);
  disponible.then(function (si) {
    if (!si) { return; }
    document.querySelectorAll('[data-huella]').forEach(function (el) { el.hidden = false; });
    var b = document.getElementById('entrar-huella');
    if (b) { b.addEventListener('click', function () { entrar(b); }); }
    document.querySelectorAll('[data-huella-activar]').forEach(function (btn) {
      btn.addEventListener('click', function () { activar(btn); });
    });
    var oferta = document.getElementById('ofrecer-huella');
    if (oferta && !marcado('tuwa_huella') && !marcado('tuwa_huella_no')) {
      oferta.hidden = false;
      var no = document.getElementById('huella-ahora-no');
      if (no) {
        no.addEventListener('click', function () {
          try { localStorage.setItem('tuwa_huella_no', '1'); } catch (x) {}
          oferta.hidden = true;
        });
      }
    }
  }).catch(function () {});
})();
