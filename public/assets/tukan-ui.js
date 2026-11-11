/* tukan-ui.js · comportamiento común (v1 · 10 nov 2026). IDÉNTICO en todos los programas. Sin dependencias. */
(function () {
  // Menú del celular: botón ☰ abre/cierra, el fondo oscuro lo cierra
  var boton = document.querySelector('.tk-boton-menu'), menu = document.querySelector('.tk-menu'), fondo = document.querySelector('.tk-fondo-menu');
  function cerrar() { if (menu) menu.classList.remove('abierto'); if (fondo) fondo.classList.remove('abierto'); if (boton) boton.setAttribute('aria-expanded', 'false'); }
  if (boton && menu) boton.addEventListener('click', function () {
    var abre = !menu.classList.contains('abierto');
    menu.classList.toggle('abierto', abre); if (fondo) fondo.classList.toggle('abierto', abre); boton.setAttribute('aria-expanded', abre ? 'true' : 'false');
  });
  if (fondo) fondo.addEventListener('click', cerrar);
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') cerrar(); });
  // Botón «ocupado»: al enviar un formulario, su botón queda gris y dice «Procesando…» (o data-ocupado="texto"); no se envía dos veces
  document.addEventListener('submit', function (e) {
    var f = e.target; if (!(f instanceof HTMLFormElement) || f.hasAttribute('data-sin-ocupado')) return;
    if (f.dataset.tkEnviado) { e.preventDefault(); return; }
    f.dataset.tkEnviado = '1';
    var b = e.submitter || f.querySelector('button[type=submit], button:not([type])');
    if (b) { setTimeout(function () { b.disabled = true; b.setAttribute('aria-disabled', 'true'); b.textContent = b.getAttribute('data-ocupado') || 'Procesando…'; }, 0); }
  }, true);
  // Si el navegador vuelve atrás con la página guardada, los botones vuelven a la normalidad
  window.addEventListener('pageshow', function (e) { if (e.persisted) document.querySelectorAll('form[data-tk-enviado]').forEach(function (f) { delete f.dataset.tkEnviado; }); });
})();
