// Ventanita de la llamada de WhatsApp (Calling API). El audio va directo entre este navegador y Meta (WebRTC);
// el servidor solo pasa las ofertas y respuestas SDP. Entrante: tomar -> respuesta SDP -> pre_accept -> accept.
// Saliente: oferta SDP -> connect -> Meta manda su respuesta (la buscamos con "estado").
(function () {
  'use strict';
  var caja = document.getElementById('llamada');
  if (!caja) return;
  var TR = function (t) { return (window.TUKAN_TR && window.TUKAN_TR[t]) || t; };
  var modo = caja.dataset.modo, id = caja.dataset.id, csrf = caja.dataset.csrf;
  var pc = null, micro = null, inicio = 0, reloj = null, sondeo = null, terminado = false, aceptado = false;
  function $(i) { return document.getElementById(i); }
  function estado(t) { $('ll-estado').textContent = TR(t); }
  function ver(i, si) { var e = $(i); if (e) e.hidden = !si; }
  function pedir(accion, datos) {
    var f = new FormData();
    f.append('csrf', csrf); f.append('accion', accion);
    Object.keys(datos || {}).forEach(function (k) { f.append(k, datos[k]); });
    return fetch('/?r=llamada', { method: 'POST', body: f, credentials: 'same-origin' }).then(function (r) { return r.json(); });
  }
  function consultar() {
    return fetch('/?r=llamada&accion=estado&id=' + encodeURIComponent(id), { credentials: 'same-origin' }).then(function (r) { return r.json(); });
  }
  function empezarReloj() {
    if (reloj) return;
    inicio = Date.now(); ver('ll-tiempo', true);
    reloj = setInterval(function () {
      var s = Math.floor((Date.now() - inicio) / 1000);
      $('ll-tiempo').textContent = Math.floor(s / 60) + ':' + ('0' + (s % 60)).slice(-2);
    }, 1000);
  }
  function terminar(texto) {
    if (terminado) return;
    terminado = true;
    clearInterval(reloj); clearInterval(sondeo);
    try { if (pc) pc.close(); } catch (e) {}
    try { if (micro) micro.getTracks().forEach(function (t) { t.stop(); }); } catch (e) {}
    ['ll-contestar', 'll-rechazar', 'll-llamar', 'll-silencio', 'll-colgar'].forEach(function (b) { ver(b, false); });
    estado(texto || 'Llamada terminada');
    setTimeout(function () { try { window.close(); } catch (e) {} }, 4000);
  }
  function conexion() {
    pc = new RTCPeerConnection({ iceServers: [{ urls: 'stun:stun.l.google.com:19302' }] });
    pc.ontrack = function (ev) {
      var a = $('ll-audio');
      a.srcObject = ev.streams[0] || new MediaStream([ev.track]);
      var p = a.play();
      if (p && p.catch) p.catch(function () { ver('ll-sonido', true); });   // el navegador pide un toque para sonar
    };
    pc.onconnectionstatechange = function () {
      if (pc.connectionState === 'connected') { empezarReloj(); if (modo === 'entra') aceptar(); }
      if (pc.connectionState === 'failed') { pedir('colgar', { id: id }); terminar('No se pudo conectar el audio'); }
    };
    micro.getTracks().forEach(function (t) { pc.addTrack(t, micro); });
    return pc;
  }
  // Espera a que el navegador junte sus direcciones de red (ICE), como mucho 3 segundos
  function candidatos() {
    return new Promise(function (listo) {
      if (pc.iceGatheringState === 'complete') { listo(); return; }
      var t = setTimeout(listo, 3000);
      pc.addEventListener('icegatheringstatechange', function () { if (pc.iceGatheringState === 'complete') { clearTimeout(t); listo(); } });
    });
  }
  function microfono() {
    return navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true }, video: false })
      .then(function (s) { micro = s; return s; });
  }
  function vigilar() {
    sondeo = setInterval(function () {
      consultar().then(function (d) {
        if (!d.ok) return;
        if (modo === 'sale' && d.respuesta && pc && !pc.currentRemoteDescription) {
          pc.setRemoteDescription({ type: 'answer', sdp: d.respuesta }).catch(function () {});
        }
        if (d.estado === 'sonando' && modo === 'sale') estado('Sonando…');
        if (d.estado === 'en_curso') { estado('En llamada'); empezarReloj(); }
        if (['terminada', 'perdida', 'rechazada', 'fallida'].indexOf(d.estado) >= 0) {
          terminar(d.estado === 'rechazada' ? 'No contestó o rechazó la llamada' : (d.estado === 'fallida' ? (d.error || 'La llamada falló') : 'Llamada terminada'));
        }
      }).catch(function () {});
    }, 1500);
  }
  function aceptar() {
    if (aceptado) return;
    aceptado = true;
    pedir('aceptar', { id: id }).then(function (d) {
      if (!d.ok) { terminar(d.error); return; }
      estado('En llamada'); empezarReloj();
    });
  }
  function enLlamada() {
    ['ll-contestar', 'll-rechazar', 'll-llamar'].forEach(function (b) { ver(b, false); });
    ver('ll-silencio', true); ver('ll-colgar', true);
  }

  // ----- entrante
  function contestar() {
    enLlamada(); estado('Conectando…');
    var oferta;
    pedir('tomar', { id: id }).then(function (d) {
      if (!d.ok) throw new Error(d.error);
      oferta = d.oferta;
      return microfono();
    }).then(function () {
      conexion();
      return pc.setRemoteDescription({ type: 'offer', sdp: oferta });
    }).then(function () { return pc.createAnswer(); })
      .then(function (a) { return pc.setLocalDescription(a); })
      .then(candidatos)
      .then(function () { return pedir('contestar', { id: id, sdp: pc.localDescription.sdp }); })
      .then(function (d) {
        if (!d.ok) throw new Error(d.error);
        vigilar();
        setTimeout(aceptar, 4000);   // si el audio tarda en conectarse, igual se contesta
      })
      .catch(function (e) { terminar(e && e.name === 'NotAllowedError' ? 'Permite el micrófono para contestar' : (e.message || 'No se pudo contestar')); });
  }
  // ----- saliente
  function llamar() {
    enLlamada(); estado('Llamando…');
    microfono().then(function () {
      conexion();
      return pc.createOffer({ offerToReceiveAudio: true });
    }).then(function (o) { return pc.setLocalDescription(o); })
      .then(candidatos)
      .then(function () { return pedir('llamar', { c: caja.dataset.c, sdp: pc.localDescription.sdp }); })
      .then(function (d) {
        if (!d.ok) throw new Error(d.error);
        id = String(d.id); estado('Sonando…'); vigilar();
      })
      .catch(function (e) { terminar(e && e.name === 'NotAllowedError' ? 'Permite el micrófono para llamar' : (e.message || 'No se pudo llamar')); });
  }

  if ($('ll-contestar')) $('ll-contestar').addEventListener('click', contestar);
  if ($('ll-rechazar')) $('ll-rechazar').addEventListener('click', function () { pedir('rechazar', { id: id }).finally(function () { terminar('Llamada rechazada'); }); });
  if ($('ll-llamar')) $('ll-llamar').addEventListener('click', llamar);
  if ($('ll-sonido')) $('ll-sonido').addEventListener('click', function () { $('ll-audio').play(); ver('ll-sonido', false); });
  $('ll-colgar').addEventListener('click', function () { pedir('colgar', { id: id }).finally(function () { terminar('Llamada terminada'); }); });
  $('ll-silencio').addEventListener('click', function () {
    if (!micro) return;
    var t = micro.getAudioTracks()[0]; t.enabled = !t.enabled;
    this.textContent = TR(t.enabled ? 'Silenciar micrófono' : 'Activar micrófono');
  });
  window.addEventListener('pagehide', function () {
    if (terminado || !id || !pc) return;
    var f = new FormData(); f.append('csrf', csrf); f.append('accion', 'colgar'); f.append('id', id);
    try { navigator.sendBeacon('/?r=llamada', f); } catch (e) {}
  });
  if (modo === 'entra' && caja.dataset.contestar === '1') contestar();
})();
