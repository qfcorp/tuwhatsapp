#!/usr/bin/env python3
"""Pantallas crudas del celular (1080 x 2160) para la ficha de Google Play de «Tukán Mensajes», en español e inglés.
La llama tests/capturas_tienda.sh con un WhatsApp Tukán LOCAL y la Tienda Muestra Tukán. Se niega a usar otra dirección que no sea local.
Uso: python3 tests/capturas_tienda.py URL CORREO CLAVE ID_CONTACTO ID_PEDIDO CARPETA"""
import sys
from pathlib import Path
from urllib.parse import urlparse
from playwright.sync_api import sync_playwright

url, correo, clave, contacto, pedido, carpeta = sys.argv[1:7]
if urlparse(url).hostname not in ('127.0.0.1', 'localhost'):
    sys.exit('Solo se sacan capturas de un WhatsApp Tukán local (127.0.0.1), nunca del servidor real.')

# En inglés, pedidos y catálogo todavía salen en español (falta su diccionario): ahí van Seguimientos y Gasto en Meta, que sí están traducidos
PANTALLAS = {
    'es': [('01_bandeja', '/?r=bandeja'), ('02_chat', f'/?r=chat&c={contacto}'), ('03_pedido', f'/?r=pedido&id={pedido}'),
           ('04_pedidos', '/?r=pedidos'), ('05_embudo', '/?r=embudo'), ('06_automatico', '/?r=reglas'),
           ('07_contactos', '/?r=contactos'), ('08_reportes', '/?r=reportes')],
    'en': [('01_bandeja', '/?r=bandeja'), ('02_chat', f'/?r=chat&c={contacto}'), ('03_seguimientos', '/?r=seguimientos'),
           ('04_gasto', '/?r=gasto'), ('05_embudo', '/?r=embudo'), ('06_automatico', '/?r=reglas'),
           ('07_contactos', '/?r=contactos'), ('08_reportes', '/?r=reportes')],
}

with sync_playwright() as pw:
    nav = pw.chromium.launch()
    for lang in ('es', 'en'):
        dest = Path(carpeta) / f'cap_{lang}'
        dest.mkdir(parents=True, exist_ok=True)
        ctx = nav.new_context(viewport={'width': 412, 'height': 824}, device_scale_factor=1080 / 412, is_mobile=True, has_touch=True,
                              locale='es-EC' if lang == 'es' else 'en-US', permissions=['notifications'],
                              user_agent='Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Mobile Safari/537.36')
        ctx.route('**/*', lambda r: r.continue_() if r.request.url.startswith(url) or r.request.url.startswith('data:') else r.abort())
        pg = ctx.new_page()
        pg.goto(f'{url}/?r=login&app=play&lang={lang}')
        pg.fill('input[name=correo]', correo)
        pg.fill('input[name=clave]', clave)
        pg.click('form.tarjeta button')
        pg.wait_for_load_state('networkidle')
        for nombre, ruta in PANTALLAS[lang]:
            pg.goto(url + ruta + f'&lang={lang}')
            pg.wait_for_load_state('networkidle')
            # El aviso «los avisos están bloqueados» es del navegador de las capturas (sin permiso de notificaciones): no va en la foto
            pg.evaluate("document.querySelectorAll('#avisos-navegador').forEach(function (e) { e.hidden = true; })")
            if nombre == '02_chat':   # los últimos mensajes y, debajo, los botones para responder
                pg.evaluate("window.scrollTo(0, document.getElementById('mensajes').getBoundingClientRect().bottom + window.scrollY - 560)")
            pg.wait_for_timeout(500)
            pg.screenshot(path=str(dest / f'{nombre}.png'))
            print('listo', lang, nombre)
        ctx.close()
    nav.close()
