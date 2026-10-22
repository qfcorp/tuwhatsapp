/* Botón flotante de WhatsApp Tukán para cualquier página web (módulo "Enlaces, QR y botón web").
 * Se pega una sola línea antes de </body>:
 *   <script src="https://whatsapp.tukanec.com/boton.js" data-enlace="https://whatsapp.tukanec.com/w.php?c=abc123" data-color="#25D366" data-texto="Escríbenos" async></script>
 * data-enlace: el enlace corto (cuenta los clics) o un https://wa.me/... · data-texto: lo que dice el botón (vacío = solo el ícono)
 * data-lado: "derecha" (por defecto) o "izquierda".
 */
(function () {
  var s = document.currentScript;
  if (!s || window.__tukanBoton) return;
  window.__tukanBoton = true;
  var enlace = s.getAttribute('data-enlace') || '';
  if (!/^https:\/\//.test(enlace)) return;
  var color = s.getAttribute('data-color') || '#25D366';
  if (!/^#[0-9a-fA-F]{3,8}$/.test(color)) color = '#25D366';
  var texto = s.getAttribute('data-texto');
  if (texto === null) texto = 'Escríbenos';
  var lado = s.getAttribute('data-lado') === 'izquierda' ? 'left' : 'right';

  function poner() {
    var a = document.createElement('a');
    a.href = enlace;
    a.target = '_blank';
    a.rel = 'noopener';
    a.setAttribute('aria-label', texto || 'Escríbenos por WhatsApp');
    a.style.cssText = 'position:fixed;bottom:18px;' + lado + ':18px;z-index:2147483000;display:flex;align-items:center;gap:8px;' +
      'background:' + color + ';color:#fff;text-decoration:none;font:600 15px/1 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;' +
      'padding:' + (texto ? '12px 18px 12px 14px' : '14px') + ';border-radius:999px;box-shadow:0 4px 14px rgba(0,0,0,.25)';
    // Ícono genérico de chat (globo con teléfono)
    a.innerHTML = '<svg width="26" height="26" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' +
      '<path d="M4 20l1.3-3.9A8 8 0 1 1 8 19.1z"/><path d="M9.5 9.5c.3 1.8 1.6 3.4 3.4 4.3l1.2-1.1 1.7.8-.4 1.6c-3.2.2-6.4-2.8-6.6-6l1.6-.4.8 1.7z" fill="#fff" stroke="none"/></svg>';
    if (texto) {
      var t = document.createElement('span');
      t.textContent = texto;
      a.appendChild(t);
    }
    document.body.appendChild(a);
  }
  if (document.body) poner(); else document.addEventListener('DOMContentLoaded', poner);
})();
