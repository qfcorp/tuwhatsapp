/* WhatsApp Tukán: muestra los avisos de mensajes nuevos (también con el navegador cerrado) y abre el chat al tocarlos. */
self.addEventListener('install', function () { self.skipWaiting(); });
self.addEventListener('activate', function (e) { e.waitUntil(self.clients.claim()); });
self.addEventListener('push', function (e) {
  var d = {};
  try { d = e.data ? e.data.json() : {}; } catch (x) { d = { texto: e.data ? e.data.text() : '' }; }
  e.waitUntil(self.registration.showNotification(d.titulo || 'WhatsApp Tukán', {
    body: d.texto || 'Mensaje nuevo', tag: d.tag || 'tuwa', renotify: true,
    icon: '/assets/icono-192.png', badge: '/assets/icono-192.png', data: { url: d.url || '/?r=bandeja' }
  }));
});
self.addEventListener('notificationclick', function (e) {
  e.notification.close();
  var url = (e.notification.data && e.notification.data.url) || '/?r=bandeja';
  e.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (lista) {
    for (var i = 0; i < lista.length; i++) {
      if ('focus' in lista[i]) { lista[i].navigate(url); return lista[i].focus(); }
    }
    return self.clients.openWindow(url);
  }));
});
