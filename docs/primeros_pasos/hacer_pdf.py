"""Primeros pasos de WhatsApp Tukán (guía corta para el cliente, PDF A4), a partir de primeros_pasos.html (que se edita a mano).
Uso: python3 hacer_pdf.py   ->  ../WhatsApp_Tukan_Primeros_Pasos.pdf"""
import os
from playwright.sync_api import sync_playwright
D = os.path.dirname(os.path.abspath(__file__))
u = lambda p: 'file://' + p
out = os.path.join(os.path.dirname(D), 'WhatsApp_Tukan_Primeros_Pasos.pdf')
with sync_playwright() as pw:
    b = pw.chromium.launch(); pg = b.new_page()
    pg.goto(u(f'{D}/primeros_pasos.html')); pg.wait_for_load_state('networkidle'); pg.evaluate('document.fonts.ready')
    pg.pdf(path=out, format='A4', print_background=True, display_header_footer=True, header_template='<span></span>',
           footer_template='<div style="font-size:8px;color:#4A5470;width:100%;text-align:center">WhatsApp Tukán · Primeros pasos · página <span class="pageNumber"></span> de <span class="totalPages"></span></div>',
           margin={'top': '16mm', 'bottom': '18mm', 'left': '15mm', 'right': '15mm'})
    b.close()
print('listo', out)
