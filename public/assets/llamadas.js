// Panel: cuando un cliente llama por WhatsApp, suena y aparece arriba "Contestar / Rechazar".
// Contestar abre la ventanita de la llamada (así la llamada sigue aunque cambies de página en el panel).
(function () {
  'use strict';
  var b = document.body;
  if (!b || b.dataset.llamadas !== '1') return;
  var TR = function (t) { return (window.TUKAN_TR && window.TUKAN_TR[t]) || t; };
  var barra = null, actual = null, timbre = null, ctx = null, ignoradas = {};
  function sonar() {
    try {
      ctx = ctx || new (window.AudioContext || window.webkitAudioContext)();
      [0, 0.35].forEach(function (d) {
        var o = ctx.createOscillator(), g = ctx.createGain(), t = ctx.currentTime + d;
        o.frequency.value = 760; o.type = 'sine';
        g.gain.setValueAtTime(0.0001, t); g.gain.exponentialRampToValueAtTime(0.2, t + 0.03); g.gain.exponentialRampToValueAtTime(0.0001, t + 0.3);
        o.connect(g); g.connect(ctx.destination); o.start(t); o.stop(t + 0.32);
      });
    } catch (e) {}
  }
  function quitar() {
    if (barra) { barra.remove(); barra = null; }
    clearInterval(timbre); timbre = null; actual = null;
  }
  function mostrar(l) {
    if (actual === l.id || ignoradas[l.id]) return;
    quitar(); actual = l.id;
    barra = document.createElement('div');
    barra.className = 'barra-llamada'; barra.setAttribute('role', 'alert');
    var t = document.createElement('span'); t.textContent = '📞 ' + TR('Llamada de') + ' ' + l.nombre;
    var si = document.createElement('button'); si.className = 'boton boton-verde'; si.textContent = TR('Contestar');
    var no = document.createElement('button'); no.className = 'boton boton-rojo'; no.textContent = TR('Rechazar');
    si.addEventListener('click', function () {
      window.open('/?r=llamar&id=' + l.id + '&contestar=1', 'llamada_' + l.id, 'width=380,height=620');
      ignoradas[l.id] = true; quitar();
    });
    no.addEventListener('click', function () {
      var f = new FormData(); f.append('csrf', b.dataset.csrf); f.append('accion', 'rechazar'); f.append('id', l.id);
      fetch('/?r=llamada', { method: 'POST', body: f, credentials: 'same-origin' });
      ignoradas[l.id] = true; quitar();
    });
    barra.appendChild(t); barra.appendChild(si); barra.appendChild(no);
    document.body.appendChild(barra);
    sonar(); timbre = setInterval(sonar, 2000);
  }
  function revisar() {
    fetch('/?r=llamada&accion=sonando', { credentials: 'same-origin' }).then(function (r) { return r.ok ? r.json() : null; }).then(function (d) {
      if (!d || !d.ok) return;
      if (d.llamada) mostrar(d.llamada); else if (actual) quitar();
    }).catch(function () {});
  }
  revisar(); setInterval(revisar, 3000);
})();
