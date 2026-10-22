/* WhatsApp Tukán: muestra los avisos de mensajes nuevos y abre el chat al tocarlos. */
self.addEventListener('install', function () { self.skipWaiting(); });
self.addEventListener('activate', function (e) { e.waitUntil(self.clients.claim()); });
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
