// WhatsApp Tukán — pequeñas ayudas en el navegador (todo funciona también sin esto)
(function () {
    'use strict';
    // Textos traducidos (los pone la página con tukan_idioma_js; si no hay, queda en español)
    var TR = function (t) { return (window.TUKAN_TR && window.TUKAN_TR[t]) || t; };
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

    // Revisa cada 10 segundos si llegó algo nuevo (también con la pestaña en segundo plano, para avisar)
    var globo = $('#globo-bandeja');
    var conv = $('#conversaciones');
    var ultimoVisto = lista ? +lista.dataset.ultimo : null;
    var tituloBase = document.title.replace(/^\(\d+\)\s*/, '');
    var iconoBase = null, avisado = null;
    try { avisado = +sessionStorage.getItem('tuwa_avisado') || null; } catch (e) {}

    // Ícono de la pestaña con un círculo rojo y el número de mensajes sin leer
    function ponerIcono(n) {
        var link = document.querySelector('link[rel="icon"]');
        if (!link) { return; }
        if (iconoBase === null) { iconoBase = link.href; }
        if (!n) { link.href = iconoBase; return; }
        var img = new Image();
        img.onload = function () {
            var c = document.createElement('canvas'); c.width = 64; c.height = 64;
            var x = c.getContext('2d');
            x.drawImage(img, 0, 0, 64, 64);
            x.fillStyle = '#E02424'; x.beginPath(); x.arc(46, 18, 18, 0, 2 * Math.PI); x.fill();
            x.fillStyle = '#fff'; x.font = 'bold 26px system-ui, sans-serif'; x.textAlign = 'center'; x.textBaseline = 'middle';
            x.fillText(n > 9 ? '9+' : String(n), 46, 19);
            link.href = c.toDataURL('image/png');
        };
        img.src = iconoBase;
    }

    // Sonido corto (sin archivos): dos tonos suaves
    var audio = null;
    function sonar() {
        try { if (localStorage.getItem('tuwa_sonido') === 'no') { return; } } catch (e) {}
        try {
            audio = audio || new (window.AudioContext || window.webkitAudioContext)();
            [880, 1320].forEach(function (f, i) {
                var o = audio.createOscillator(), g = audio.createGain(), t = audio.currentTime + i * 0.16;
                o.frequency.value = f; o.type = 'sine';
                g.gain.setValueAtTime(0.0001, t); g.gain.exponentialRampToValueAtTime(0.18, t + 0.02); g.gain.exponentialRampToValueAtTime(0.0001, t + 0.15);
                o.connect(g); g.connect(audio.destination); o.start(t); o.stop(t + 0.16);
            });
        } catch (e) {}
    }

    // Aviso del navegador (en Android Chrome tiene que ir por el service worker)
    var registro = null;
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('/sw.js').then(function (r) { registro = r; }).catch(function () {});
    }
    function avisarNavegador(a) {
        if (!('Notification' in window) || Notification.permission !== 'granted') { return; }
        var op = { body: a.texto || TR('Mensaje nuevo'), tag: 'tuwa-' + a.id, icon: iconoBase || undefined, data: { url: a.url } };
        try {
            if (registro && registro.showNotification) { registro.showNotification(a.nombre, op); return; }
            var n = new Notification(a.nombre, op);
            n.onclick = function () { window.focus(); location.href = a.url; };
        } catch (e) {}
    }

    // Botón "Avisarme" en la Bandeja
    var cajaAvisos = $('#avisos-navegador'), botonAvisos = $('#activar-avisos'), estadoAvisos = $('#avisos-estado');
    function pintarAvisos() {
        if (!cajaAvisos || !('Notification' in window)) { return; }
        var p = Notification.permission;
        cajaAvisos.hidden = p === 'granted';
        if (p === 'denied') {
            botonAvisos.hidden = true;
            estadoAvisos.textContent = TR('Los avisos están bloqueados: actívalos en el candado junto a la dirección (Notificaciones → Permitir).');
        }
    }
    if (botonAvisos) {
        botonAvisos.addEventListener('click', function () {
            sonar();   // el primer toque habilita el sonido
            Notification.requestPermission().then(function () { pintarAvisos(); if (Notification.permission === 'granted') { estadoAvisos.textContent = ''; } });
        });
        pintarAvisos();
    }

    function revisar() {
        var u = '/?r=nuevos' + (lista ? '&c=' + lista.dataset.contacto : '');
        fetch(u, { credentials: 'same-origin' }).then(function (r) { return r.ok ? r.json() : null; }).then(function (d) {
            if (!d) { return; }
            if (globo) { globo.hidden = d.sin_leer < 1; globo.textContent = d.sin_leer; }
            document.title = (d.sin_leer > 0 ? '(' + d.sin_leer + ') ' : '') + tituloBase;
            ponerIcono(d.sin_leer);
            if (d.aviso && avisado !== null && d.aviso.id > avisado) {
                sonar();
                if (document.hidden || !document.hasFocus()) { avisarNavegador(d.aviso); }
            }
            if (d.aviso && (avisado === null || d.aviso.id > avisado)) {
                avisado = d.aviso.id;
                try { sessionStorage.setItem('tuwa_avisado', String(avisado)); } catch (e) {}
            } else if (avisado === null) {
                avisado = 0;
            }
            if (document.hidden) { return; }   // lo de abajo cambia la página: solo si se está mirando
            if (lista && d.ultimo > ultimoVisto) {
                if (!texto || texto.value.trim() === '') { location.reload(); return; }
                if (!$('#hay-nuevos')) {
                    var a = document.createElement('a');
                    a.id = 'hay-nuevos'; a.className = 'hay-nuevos'; a.href = location.href; a.textContent = TR('Llegó un mensaje nuevo · tocar para ver');
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
                    if (!filas.length) { estado.textContent = TR('No elegiste contactos con número.'); return; }
                    $('#imp-lista').value = filas.join('\n');
                    archivo.value = '';
                    estado.textContent = TR('Guardando {n} números…').replace('{n}', filas.length);
                    formI.submit();
                }).catch(function () { estado.textContent = TR('No se pudo abrir la agenda. Usa "Subir archivo de contactos".'); });
            });
        }
        archivo.addEventListener('change', function () {
            var f = archivo.files[0];
            if (!f) { return; }
            estado.textContent = TR('Leyendo {s}…').replace('{s}', f.name);
            var lector = new FileReader();
            lector.onload = function () {
                // Se quitan las fotos de los contactos para que el archivo pese poco
                var txt = String(lector.result).replace(/\r\n/g, '\n').replace(/^PHOTO[^\n]*\n(?:[ \t][^\n]*\n)*/gmi, '');
                if (txt.length < 7000000) {
                    $('#imp-contenido').value = txt;
                    $('#imp-nombre').value = f.name;
                    archivo.disabled = true;   // no se manda el archivo grande, solo el texto
                }
                estado.textContent = TR('Guardando contactos…');
                formI.submit();
            };
            lector.onerror = function () { formI.submit(); };
            lector.readAsText(f);
        });
    }

    // Conectar con WhatsApp Business (código QR): ventana de Meta (Embedded Signup v5, coexistencia con la app del celular)
    var btnQr = $('#btn-qr');
    if (btnQr) {
        var estadoQr = $('#qr-estado');
        var datosMeta = null, codigo = null, enviado = false, espera = null;
        var decir = function (t) { estadoQr.textContent = t; };
        var enviar = function () {
            if (enviado || !codigo) { return; }
            if (!datosMeta) {   // a veces el código llega antes que los datos: se espera un momento
                if (!espera) { espera = setTimeout(function () { espera = 'fin'; enviar(); }, 4000); }
                if (espera !== 'fin') { return; }
            }
            enviado = true;
            decir(TR('Guardando la conexión…'));
            var f = new URLSearchParams();
            f.append('csrf', btnQr.dataset.csrf);
            f.append('code', codigo);
            f.append('evento', datosMeta ? datosMeta.event : '');
            var dm = (datosMeta && datosMeta.data) || {};
            var lista = function (x) { return Array.isArray(x) && x.length ? String(x[0]) : ''; };
            // v4/v5 de Meta: waba_id o messaging_account_ids (la cuenta convertida) — el servidor completa lo que falte
            f.append('waba_id', dm.waba_id || lista(dm.messaging_account_ids) || lista(dm.waba_ids) || '');
            f.append('phone_number_id', dm.phone_number_id || '');
            f.append('nombre', ($('#qr-nombre') || {}).value || '');
            fetch('/?r=conectar_qr', { method: 'POST', body: f, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d.ok) { decir(TR('¡Listo! Abriendo tu número…')); location.href = d.ir; }
                    else { decir(d.error || TR('No se pudo conectar.')); enviado = false; btnQr.disabled = false; }
                })
                .catch(function () { decir(TR('No se pudo hablar con el servidor. Revisa el internet e intenta de nuevo.')); enviado = false; btnQr.disabled = false; });
        };
        window.addEventListener('message', function (ev) {
            if (!/(^|\.)facebook\.com$/.test(String(ev.origin).replace(/^https:\/\//, ''))) { return; }
            var d;
            try { d = typeof ev.data === 'string' ? JSON.parse(ev.data) : ev.data; } catch (e) { return; }
            if (!d || d.type !== 'WA_EMBEDDED_SIGNUP') { return; }
            try { console.log('[TukanQR] Meta:', d.event, JSON.stringify(d.data || {})); } catch (e) {}
            if (d.event === 'CANCEL') {
                decir(d.data && d.data.error_message ? 'Meta: ' + d.data.error_message : TR('Cerraste la ventana de Meta antes de terminar.'));
                btnQr.disabled = false;
                return;
            }
            datosMeta = d;
            enviar();
        });
        window.fbAsyncInit = function () {
            FB.init({ appId: btnQr.dataset.app, autoLogAppEvents: true, xfbml: false, version: btnQr.dataset.version });
            btnQr.disabled = false;
        };
        btnQr.disabled = true;
        decir(TR('Cargando Meta…'));
        var s = document.createElement('script');
        s.src = 'https://connect.facebook.net/es_LA/sdk.js';
        s.async = true; s.defer = true; s.crossOrigin = 'anonymous';
        s.onload = function () { decir(''); };
        s.onerror = function () { decir(TR('No se pudo cargar Meta. Desactiva el bloqueador de anuncios o prueba con Chrome.')); };
        document.head.appendChild(s);
        btnQr.addEventListener('click', function () {
            if (!window.FB) { return; }
            btnQr.disabled = true; datosMeta = null; codigo = null; enviado = false; espera = null;
            decir(TR('Sigue los pasos en la ventana de Meta…'));
            FB.login(function (resp) {
                try { console.log('[TukanQR] permiso:', resp && resp.status, !!(resp && resp.authResponse && resp.authResponse.code)); } catch (e) {}
                if (resp && resp.authResponse && resp.authResponse.code) { codigo = resp.authResponse.code; enviar(); }
                else { decir(TR('No se completó el permiso en Meta. Intenta de nuevo.')); btnQr.disabled = false; }
            }, {
                config_id: btnQr.dataset.config,
                response_type: 'code',
                override_default_response_type: true,
                extras: { version: 'v5', setup: {} }
            });
        });
    }


    // Plantilla nueva: ejemplos solo para las variables escritas y vista previa en vivo
    var formN = $('#form-nueva-plantilla');
    if (formN) {
        var cuerpoN = $('textarea[name=cuerpo]', formN), vista = $('#vista-plantilla');
        var esc = function (t) { var d = document.createElement('div'); d.textContent = t; return d.innerHTML; };
        var pintar = function () {
            var texto = cuerpoN.value.replace(/\{\{\s*(\d+)\s*\}\}/g, '{{$1}}');
            var usadas = {};
            (texto.match(/\{\{(\d+)\}\}/g) || []).forEach(function (v) { usadas[v.replace(/\D/g, '')] = true; });
            $$('#ejemplos label', formN).forEach(function (l) {
                var usa = !!usadas[l.dataset.n];
                l.hidden = !usa;
                $('input', l).required = usa;
            });
            var html = esc(texto).replace(/\{\{(\d+)\}\}/g, function (m, n) {
                var ej = $('input[name="ejemplo[' + n + ']"]', formN);
                return '<mark>' + esc(ej && ej.value ? ej.value : '{{' + n + '}}') + '</mark>';
            }).replace(/\n/g, '<br>');
            $('.vp-encabezado', vista).textContent = $('input[name=encabezado]', formN).value;
            $('.vp-cuerpo', vista).innerHTML = html || '<span class="suave">' + TR('Escribe el texto…') + '</span>';
            $('.vp-pie', vista).textContent = $('input[name=pie]', formN).value;
            var bots = '';
            $$('.fila-boton', formN).forEach(function (f) {
                var tipo = $('select', f).value, txt = $('input[name^=boton_texto]', f).value;
                $('input[name^=boton_destino]', f).hidden = !(tipo === 'URL' || tipo === 'PHONE_NUMBER');
                $('input[name^=boton_texto]', f).hidden = tipo === 'NINGUNO';
                if (tipo !== 'NINGUNO' && txt) { bots += '<span>' + esc(txt) + '</span>'; }
            });
            $('.vp-botones', vista).innerHTML = bots;
        };
        formN.addEventListener('input', pintar);
        formN.addEventListener('change', pintar);
        pintar();
    }


    // Campaña nueva: mostrar solo los datos que pide la plantilla elegida
    var formC = $('#form-campania');
    if (formC) {
        var verDatos = function () {
            var sel = $('input[name=plantilla]:checked', formC);
            var n = sel ? +sel.dataset.espacios : 0;
            $$('#datos-campania fieldset', formC).forEach(function (fs) {
                var usa = +fs.dataset.n <= n;
                fs.hidden = !usa;
                $$('input, select', fs).forEach(function (x) { x.disabled = !usa; });
            });
        };
        $$('input[name=plantilla]', formC).forEach(function (r) { r.addEventListener('change', verDatos); });
        verDatos();
    }


    // Respuesta rápida: pega el texto en el mensaje
    var rapida = $('#rapida');
    if (rapida && texto) {
        rapida.addEventListener('change', function () {
            if (!rapida.value) { return; }
            texto.value = texto.value.trim() ? texto.value.replace(/\s*$/, '\n') + rapida.value : rapida.value;
            texto.dispatchEvent(new Event('input'));
            rapida.value = '';
            texto.focus();
        });
    }

    // Chat: adjuntar archivo o grabar nota de voz (se envía con "Enviar por el sistema")
    var archivo = $('#archivo'), nombreAdj = $('#adjunto-nombre'), grabar = $('#grabar');
    if (archivo && texto) {
        var alCambiar = function () {
            var f = archivo.files[0];
            texto.required = !f;
            texto.placeholder = f ? TR('Texto debajo del archivo (opcional)…') : TR('Escribe tu respuesta…');
            nombreAdj.textContent = f ? f.name + ' · ' + Math.max(1, Math.round(f.size / 1024)) + ' KB ' + TR('(toca "Enviar por el sistema")') : '';
        };
        archivo.addEventListener('change', alCambiar);
        if (grabar && window.MediaRecorder && navigator.mediaDevices && window.DataTransfer) {
            grabar.hidden = false;
            var rec = null, partes = [];
            grabar.addEventListener('click', function () {
                if (rec && rec.state === 'recording') { rec.stop(); return; }
                navigator.mediaDevices.getUserMedia({ audio: true }).then(function (flujo) {
                    var tipo = MediaRecorder.isTypeSupported('audio/ogg;codecs=opus') ? 'audio/ogg;codecs=opus' : 'audio/webm;codecs=opus';
                    rec = new MediaRecorder(flujo, { mimeType: tipo });
                    partes = [];
                    rec.ondataavailable = function (e) { if (e.data.size) { partes.push(e.data); } };
                    rec.onstop = function () {
                        flujo.getTracks().forEach(function (t) { t.stop(); });
                        var ext = tipo.indexOf('ogg') >= 0 ? 'ogg' : 'webm';
                        var f = new File(partes, 'nota-de-voz.' + ext, { type: tipo.split(';')[0] });
                        var dt = new DataTransfer(); dt.items.add(f); archivo.files = dt.files;
                        grabar.textContent = TR('🎤 Nota de voz'); grabar.classList.remove('grabando');
                        alCambiar();
                    };
                    rec.start();
                    grabar.textContent = TR('⏹ Detener'); grabar.classList.add('grabando');
                    nombreAdj.textContent = TR('Grabando… toca "Detener" cuando termines.');
                }).catch(function () { nombreAdj.textContent = TR('No se pudo usar el micrófono. Revisa el permiso del navegador.'); });
            });
        }
    }

    // Botones "Copiar"
    $$('.btn-copiar').forEach(function (b) {
        b.addEventListener('click', function () {
            var i = b.previousElementSibling;
            i.select();
            (navigator.clipboard ? navigator.clipboard.writeText(i.value) : Promise.reject()).catch(function () { document.execCommand('copy'); });
            b.textContent = TR('¡Copiado!');
            setTimeout(function () { b.textContent = TR('Copiar'); }, 1500);
        });
    });
    // Formularios que se mandan solos al elegir (embudo); sin JavaScript queda el botón
    $$('select.auto-enviar').forEach(function (sel) {
        var b = sel.form.querySelector('.sin-js');
        if (b) { b.hidden = true; }
        sel.addEventListener('change', function () { sel.form.submit(); });
    });

    // Seguimientos: la fecha solo cuando se elige "Elegir fecha y hora"
    $$('select.seg-cuando').forEach(function (sel) {
        var caja = sel.form.querySelector('.seg-fecha');
        var ver = function () { caja.hidden = sel.value !== 'fecha'; caja.querySelector('input').required = sel.value === 'fecha'; };
        sel.addEventListener('change', ver);
        ver();
    });

    // Copiar un enlace y código para la web
    $$('button.copiar').forEach(function (b) {
        b.addEventListener('click', function () {
            var t = b.dataset.copiar, txt = b.textContent;
            (navigator.clipboard ? navigator.clipboard.writeText(t) : Promise.reject()).then(function () {
                b.textContent = TR('¡Copiado!');
            }, function () { b.textContent = TR('Mantén presionado el enlace para copiarlo'); });
            setTimeout(function () { b.textContent = txt; }, 1800);
        });
    });
    $$('textarea.codigo').forEach(function (t) { t.addEventListener('focus', function () { t.select(); }); });

    // Códigos QR de los enlaces (librería qrcode.js, MIT)
    if (window.qrcode) {
        $$('.qr[data-qr]').forEach(function (caja) {
            var q = window.qrcode(0, 'M');
            q.addData(caja.dataset.qr);
            q.make();
            caja.innerHTML = q.createSvgTag({ cellSize: 6, margin: 4, scalable: true });
            var bajar = caja.parentNode.querySelector('.bajar-qr');
            if (bajar) {
                bajar.href = q.createDataURL(12, 4);
                bajar.download = (caja.dataset.nombre || 'qr') + '.gif';
                bajar.hidden = false;
            }
        });
    }
})();
