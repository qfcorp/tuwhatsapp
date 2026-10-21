// WhatsApp Tukán — pequeñas ayudas en el navegador (todo funciona también sin esto)
(function () {
    'use strict';
    var $ = function (s, r) { return (r || document).querySelector(s); };
    var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

    // Chat: el botón "Enviar desde el celular" lleva el texto escrito
    var texto = $('#texto'), celular = $('#enviar-celular');
    if (texto && celular) {
        var actualizar = function () {
            celular.href = 'https://wa.me/' + celular.dataset.tel + (texto.value.trim() ? '?text=' + encodeURIComponent(texto.value) : '');
        };
        texto.addEventListener('input', actualizar);
        actualizar();
    }
    var lista = $('#mensajes');
    if (lista) { window.scrollTo(0, document.body.scrollHeight); }

    // Revisa cada 10 segundos si llegó algo nuevo
    var globo = $('#globo-bandeja');
    var conv = $('#conversaciones');
    var ultimoVisto = lista ? +lista.dataset.ultimo : null;
    function revisar() {
        if (document.hidden) { return; }
        var u = '/?r=nuevos' + (lista ? '&c=' + lista.dataset.contacto : '');
        fetch(u, { credentials: 'same-origin' }).then(function (r) { return r.ok ? r.json() : null; }).then(function (d) {
            if (!d) { return; }
            if (globo) { globo.hidden = d.sin_leer < 1; globo.textContent = d.sin_leer; }
            if (lista && d.ultimo > ultimoVisto) {
                if (!texto || texto.value.trim() === '') { location.reload(); return; }
                if (!$('#hay-nuevos')) {
                    var a = document.createElement('a');
                    a.id = 'hay-nuevos'; a.className = 'hay-nuevos'; a.href = location.href; a.textContent = 'Llegó un mensaje nuevo · tocar para ver';
                    lista.after(a);
                }
            }
            if (conv && !$('input:focus') && d.ultimo > (+conv.dataset.ultimo || 0)) {
                if (conv.dataset.ultimo) { location.reload(); return; }
                conv.dataset.ultimo = d.ultimo;
            }
        }).catch(function () {});
    }
    if (globo || lista || conv) { revisar(); setInterval(revisar, 10000); }

    // Pendientes: después de abrir WhatsApp, preguntar si se envió
    $$('.pendiente').forEach(function (li) {
        var pregunta = $('.pend-pregunta', li);
        $('.abrir-wa', li).addEventListener('click', function () { setTimeout(function () { pregunta.hidden = false; }, 400); });
        $('.no-aun', li).addEventListener('click', function () { pregunta.hidden = true; });
        $('.marcar', li).addEventListener('click', function () {
            var f = new FormData();
            f.append('csrf', $('input[name=csrf]', li).value);
            f.append('id', li.dataset.id);
            f.append('accion', 'enviado');
            f.append('ajax', '1');
            fetch('/?r=pendientes', { method: 'POST', body: f, credentials: 'same-origin' }).then(function (r) {
                if (r.ok) { li.classList.add('hecho'); setTimeout(function () { li.remove(); }, 300); }
                else { location.reload(); }
            });
        });
    });

    // Plantillas: mostrar solo los datos que pide la elegida
    var formP = $('#form-plantilla');
    if (formP) {
        var mostrar = function () {
            var sel = $('input[name=plantilla]:checked', formP);
            var n = sel ? +sel.dataset.espacios : 0;
            $$('#datos-plantilla label', formP).forEach(function (l) {
                var usa = +l.dataset.n <= n;
                l.hidden = !usa;
                $('input', l).disabled = !usa;
                $('input', l).required = usa;
            });
        };
        $$('input[name=plantilla]', formP).forEach(function (r) { r.addEventListener('change', mostrar); });
        mostrar();
    }


    // Contactos: traer de la agenda del celular (Android con Chrome) o de un archivo .vcf/.csv
    var formI = $('#form-importar');
    if (formI) {
        var estado = $('#imp-estado');
        var archivo = $('#imp-archivo');
        var btnAgenda = $('#btn-agenda');
        if ('contacts' in navigator && 'ContactsManager' in window) {
            btnAgenda.hidden = false;
            $('#txt-agenda').hidden = false;
            btnAgenda.addEventListener('click', function () {
                navigator.contacts.select(['name', 'tel'], { multiple: true }).then(function (lista) {
                    var filas = [];
                    lista.forEach(function (c) {
                        var nombre = (c.name && c.name[0]) ? c.name[0].replace(/[\r\n]+/g, ' ') : '';
                        (c.tel || []).forEach(function (t) { filas.push(t + ', ' + nombre); });
                    });
                    if (!filas.length) { estado.textContent = 'No elegiste contactos con número.'; return; }
                    $('#imp-lista').value = filas.join('\n');
                    archivo.value = '';
                    estado.textContent = 'Guardando ' + filas.length + ' números…';
                    formI.submit();
                }).catch(function () { estado.textContent = 'No se pudo abrir la agenda. Usa "Subir archivo de contactos".'; });
            });
        }
        archivo.addEventListener('change', function () {
            var f = archivo.files[0];
            if (!f) { return; }
            estado.textContent = 'Leyendo ' + f.name + '…';
            var lector = new FileReader();
            lector.onload = function () {
                // Se quitan las fotos de los contactos para que el archivo pese poco
                var txt = String(lector.result).replace(/\r\n/g, '\n').replace(/^PHOTO[^\n]*\n(?:[ \t][^\n]*\n)*/gmi, '');
                if (txt.length < 7000000) {
                    $('#imp-contenido').value = txt;
                    $('#imp-nombre').value = f.name;
                    archivo.disabled = true;   // no se manda el archivo grande, solo el texto
                }
                estado.textContent = 'Guardando contactos…';
                formI.submit();
            };
            lector.onerror = function () { formI.submit(); };
            lector.readAsText(f);
        });
    }

    // Botones "Copiar"
    $$('.btn-copiar').forEach(function (b) {
        b.addEventListener('click', function () {
            var i = b.previousElementSibling;
            i.select();
            (navigator.clipboard ? navigator.clipboard.writeText(i.value) : Promise.reject()).catch(function () { document.execCommand('copy'); });
            b.textContent = '¡Copiado!';
            setTimeout(function () { b.textContent = 'Copiar'; }, 1500);
        });
    });
})();
