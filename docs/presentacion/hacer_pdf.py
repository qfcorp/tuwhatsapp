"""Presentación de WhatsApp Tukán (diapositivas 16:9 en PDF), a partir de presentacion.html (que se edita a mano).
Uso: python3 hacer_pdf.py   ->  ../WhatsApp_Tukan_Presentacion.pdf"""
import os
from playwright.sync_api import sync_playwright
D = os.path.dirname(os.path.abspath(__file__))
out = os.path.join(os.path.dirname(D), 'WhatsApp_Tukan_Presentacion.pdf')
with sync_playwright() as pw:
    b = pw.chromium.launch(); pg = b.new_page(viewport={'width': 1920, 'height': 1080})
    pg.goto('file://' + D + '/presentacion.html'); pg.wait_for_load_state('networkidle'); pg.emulate_media(media='print')
    pg.evaluate('document.fonts.ready'); pg.wait_for_timeout(500)
    pg.pdf(path=out, width='1920px', height='1080px', print_background=True, margin={'top': '0', 'right': '0', 'bottom': '0', 'left': '0'})
    b.close()
print('listo', out)
