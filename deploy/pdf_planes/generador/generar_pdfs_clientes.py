#!/usr/bin/env python3
"""
Genera un PDF por programa para enviar a clientes (Tukán Software).

Fuente: fuente/VENTAS_PLANES_Y_PRECIOS.md (el informe interno de ventas).
Salida: salida/Tukan_<Programa>_Planes_y_Precios.pdf

Qué cambia frente al informe interno:
  - Se quitan las «Notas para ventas» y las frases internas («Vale la pena ofrecerlo», «Ojo: ...»).
  - El texto se pasa a «tú» (para el cliente) y los ejemplos se llaman «Ejemplos de planes».
  - Se agrega «Cómo contratar», «Condiciones» y el contacto al final de cada PDF.

Uso:  python3 generar_pdfs_clientes.py            (todos)
      python3 generar_pdfs_clientes.py TuSalón    (uno solo)
"""
import html
import re
import subprocess
import sys
import unicodedata
from pathlib import Path

BASE = Path(__file__).resolve().parent
FUENTE = BASE / "fuente" / "VENTAS_PLANES_Y_PRECIOS.md"
SALIDA = BASE / "salida"
HTML_DIR = BASE / "html"
VIGENCIA = "noviembre 2026"
WHATSAPP = "098 366 2181"
CORREO = "info@tukansoftware.com"
WEB = "tukansoftware.com"

NAVY = "#15213B"
AMARILLO = "#FFC93C"

# --------------------------------------------------------------------------------------
# 1. Leer el markdown y separar por programa
# --------------------------------------------------------------------------------------

def leer_programas(texto):
    progs = []
    actual = None
    seccion = None
    for linea in texto.splitlines():
        if linea.startswith("## "):
            actual = {"nombre": linea[3:].strip(), "secciones": {}, "orden": []}
            progs.append(actual)
            seccion = "_cabecera"
            actual["secciones"][seccion] = []
            continue
        if actual is None:
            continue
        if linea.startswith("### "):
            seccion = linea[4:].strip()
            actual["secciones"][seccion] = []
            actual["orden"].append(seccion)
            continue
        actual["secciones"][seccion].append(linea)
    for p in progs:
        cab = " ".join(p["secciones"]["_cabecera"])
        m = re.search(r"\*\*Para quién:\*\*\s*(.+?)\s*·\s*\*\*Color", cab)
        p["para_quien"] = m.group(1).strip() if m else ""
        m = re.search(r"`(#[0-9A-Fa-f]{6})`", cab)
        p["color"] = m.group(1) if m else "#F28C28"
    return progs


def tablas_y_resto(lineas):
    """Devuelve una lista de bloques: ('tabla', filas) | ('lista', items) | ('texto', str) | ('titulo', str)."""
    bloques = []
    i = 0
    while i < len(lineas):
        l = lineas[i].rstrip()
        if not l.strip():
            i += 1
            continue
        if l.startswith("|"):
            filas = []
            while i < len(lineas) and lineas[i].startswith("|"):
                celdas = [c.strip() for c in lineas[i].strip().strip("|").split("|")]
                if not all(re.fullmatch(r"-+", c) for c in celdas):
                    filas.append(celdas)
                i += 1
            bloques.append(("tabla", filas))
            continue
        if l.startswith("- "):
            items = []
            while i < len(lineas) and lineas[i].startswith("- "):
                items.append(lineas[i][2:].strip())
                i += 1
            bloques.append(("lista", items))
            continue
        if re.fullmatch(r"\*\*.+\*\*:?", l.strip()):
            bloques.append(("titulo", l.strip().strip("*").rstrip(":").strip("*")))
            i += 1
            continue
        bloques.append(("texto", l.strip()))
        i += 1
    return bloques

# --------------------------------------------------------------------------------------
# 2. Pasar el texto a lenguaje de cliente
# --------------------------------------------------------------------------------------

FRASES_FUERA = [
    r"\s*Ojo: hoy TuFactura todavía no se vende \(falta la autorización del SRI\)\.",
    r"\s*Ojo: en TuAcademia la sugerencia la hace el vendedor \(el programa no la muestra sola\)\.",
    r"\s*El plan Hostal ya no se vende\.",
    r"\s*Los planes Torneo y Liga Grande ya no se venden\.",
    r"\s*\(precio fijo del plan, aunque el catálogo tenga otro valor\)",
    r": en el código no tienen un precio fijo",
    r"\s*Sirve para probar y enganchar: el paso natural es el plan TuDron",
]

REEMPLAZOS = [
    ("el cliente tiene la base y elige 3 módulos", "tienes la base y eliges 3 módulos"),
    ("el cliente elige 3 módulos", "eliges 3 módulos"),
    ("el programa le sugiere solo pasarse al", "el programa te sugiere pasarte al"),
    ("el programa le sugiere pasarse al", "el programa te sugiere pasarte al"),
    ("y si le faltan $5 o menos, le muestra cuánto más pagaría por tenerlo todo",
     "y si te faltan $5 o menos, te muestra cuánto más pagarías por tenerlo todo"),
    ("Si con sus módulos el", "Si con tus módulos el"),
    ("conviene ofrecerle el Completo", "te conviene el Completo"),
    ("conviene ofrecer el plan completo", "te conviene el plan completo"),
    ("Si con los extras el hotel llegaría", "Si con los extras llegarías"),
    ("el cliente elige plan, meses", "eliges el plan, los meses"),
    ("Con tarjeta puede marcar", "Con tarjeta puedes marcar"),
    ("los paga el cliente directo a Meta con su tarjeta", "los pagas directo a Meta con tu tarjeta"),
    ("El cliente pega su clave tf_", "Pegas tu clave tf_"),
    ("En el Tuner ve el estado de sus celulares", "En el Tuner ves el estado de tus celulares"),
    ("Cuenta en el Tuner (tuner.tukanec.com) con sus celulares", "Cuenta en el Tuner (tuner.tukanec.com) con tus celulares"),
    ("se enciende cuando el cliente contrata TuFactura", "se enciende cuando contratas TuFactura"),
    ("Tukán le cobra y le factura desde el Panel", "Tukán te cobra y te factura directamente"),
    ("Tukán le factura desde el Panel", "Tukán factura directamente"),
    ("Al conectarlos se elige quién cobra al cliente", "Al conectarlos eliges quién cobra al cliente"),
    ("Al conectar se elige quién cobra", "Al conectar eliges quién cobra"),
    ("llena los datos por usted", "llena los datos por ti"),
    ("Cuánto paga en 6 meses", "Cuánto pagas en 6 meses"),
    ("Más su TuSalón ($12,99 + IVA al mes)", "Más tu plan TuSalón Básica ($12,99 + IVA al mes)"),
    ("Pegas tu clave tf_ en Configuración → Conexiones del programa y factura cada cobro con un toque o solo",
     "Pegas tu clave tf_ en Configuración → Conexiones del programa y cada cobro se factura con un toque o de forma automática"),
    ("Cada empresa (RUC) es un cliente", "El precio es por empresa: cada RUC lleva su propio plan"),
    (" (también TuRemolque, TuPass y el ERP, que se cotizan aparte)", ""),
    ("desde este panel", "desde el Tuner"),
    ("sin conexión con el panel", "sin conexión con el Tuner"),
    ("con el ERP de la productora", "con el ERP para productoras de Tukán (se cotiza aparte)"),
    ("se conectan por API", "se conectan entre sí"),
    ("Todo lo del Escuela Básico", "Todo lo del plan Escuela Básico"),
    ("La misma persona con una liga y una escuela paga 30 % menos en los dos planes",
     "si tienes una liga y una escuela, pagas 30 % menos en los dos planes"),
    ("Olimpiadas multideportivas: $10,50", "Olimpiadas multideportivas: $10,50 al mes"),
    ("1 espacio (salón) y 2 usuarios (los administradores, hasta 4, no cuentan)",
     "1 espacio (salón) y 2 usuarios (los administradores, hasta 4, no cuentan); para más, el plan Completo"),
    ("2 usuarios (los administradores no cuentan); lugares sin límite",
     "2 usuarios (los administradores no cuentan; para más, el plan Completo); lugares sin límite"),
    ("Hasta 5 vehículos", "Hasta 5 vehículos (¿más? pide una cotización)"),
    # Gramática de TuSalón (Básica / Completa son femeninos)
    ("En el Básica", "En el plan Básica"),
    ("Todo lo del Básica", "Todo lo del plan Básica"),
    ("el Básica", "el plan Básica"),
    ("el Completa", "el plan Completa"),
    ("al Completa", "al plan Completa"),
]

TUFACTURA_CLIENTE = ("Factura electrónica: el enlace con TuFactura viene en el programa sin costo. "
                     "TuFactura estará disponible próximamente.")


def a_cliente(t):
    t = re.sub(r"El Asistente IA \((\$[\d,]+) al mes\) y cada sede extra y la factura electrónica no cuentan entre los 3: siempre se cobran aparte\.",
               r"El Asistente IA (\1 al mes) y cada sede extra ($10,00 al mes) no cuentan entre los 3 y se pagan aparte. La factura electrónica tampoco cuenta: el enlace es sin costo, solo necesitas TuFactura.", t)
    t = re.sub(r"El Asistente IA \((\$[\d,]+) al mes\) y la factura electrónica no cuentan entre los 3(: siempre se cobran aparte)?\.",
               r"El Asistente IA (\1 al mes) no cuenta entre los 3 y se paga aparte. La factura electrónica tampoco cuenta: el enlace es sin costo, solo necesitas TuFactura.", t)
    t = re.sub(r"^Le suma ", "Si sumas ", t)
    for patron in FRASES_FUERA:
        t = re.sub(patron, "", t)
    for a, b in REEMPLAZOS:
        t = t.replace(a, b)
    return t.strip()


def nota_ejemplo(t, basico):
    t = t.strip("*").strip()
    if t.startswith("Le conviene el Básico tal cual"):
        return f"Todo entra en el plan {basico}, sin pagar extras."
    m = re.match(r"Por (\$[\d,]+) más al mes tiene el (.+?) con todo \((.+?)\)\. Vale la pena ofrecerlo\.", t)
    if m:
        return f"Por {m.group(1)} más al mes tienes el plan {m.group(2)} con todo ({m.group(3)})."
    m = re.match(r"Cuesta igual o más que el (.+?) \((.+?)\)\. Ofrece el .+?: (.+)$", t)
    if m:
        precio = m.group(2)
        if "con lo mismo de sedes" in precio:
            precio = "con tu segunda sede: $25,00 + $10,00 = $35,00 + IVA"
        return f"Aquí te conviene más el plan {m.group(1)} ({precio}): {m.group(3)}"
    return a_cliente(t)

# --------------------------------------------------------------------------------------
# 3. Datos propios de cada programa (cómo contratar y períodos)
# --------------------------------------------------------------------------------------

MODULARES = {"TuEvento", "TuPlanner", "TuSalón", "TuSpa", "TuGroomer", "TuGuardería Canina",
             "TuLavadora", "TuMecánica", "TuAcademia", "TuVocalía"}


def pasos_contratar(nombre):
    if nombre in MODULARES or nombre == "TuHospedaje":
        elige = "tu plan y tus módulos" if nombre in MODULARES else "tu plan"
        pasos = [
            f"Pide tu demo de 3 días por WhatsApp ({WHATSAPP}) o en {WEB}: trae todos los módulos para que lo pruebes completo (la factura electrónica no viene en la demo).",
            f"Eliges {elige} y pagas desde «Mi plan» dentro del programa: con tarjeta (puede quedar como cobro automático), por transferencia o con DeUna.",
            "Se activa al aprobarse el pago. Tu demo pasa a ser tu cuenta, con todo lo que ya cargaste.",
            "Puedes subir de plan o agregar módulos cuando quieras: solo pagas los días que faltan de tu período; desde el siguiente cobro rige el valor nuevo.",
        ]
        return pasos
    if nombre == "TuDron":
        return [
            "Empieza sin costo con el Plan Inicial (1 dron, hasta 20 vuelos): sin vencimiento, para que lo pruebes con tus vuelos.",
            "Para pasarte al plan TuDron pagas desde «Mi plan»: con tarjeta, por transferencia o con DeUna.",
            "Se activa al aprobarse el pago y conservas todo lo que ya registraste.",
        ]
    if nombre == "WhatsApp Tukán":
        return [
            f"Escríbenos por WhatsApp ({WHATSAPP}) y te ayudamos a conectar tu número de WhatsApp Business.",
            "Pagas desde «Mi cuenta» con tarjeta (cobro automático cada mes) o por transferencia.",
            "Se activa al aprobarse el pago.",
            "¿Quieres sumar la IA a mitad de mes? Solo pagas los días que faltan.",
        ]
    if nombre == "TuBroadcast":
        return [
            "Compras en tuner.tukanec.com/?r=comprar con tarjeta y recibes tu código de activación por correo.",
            "Activas el celular con el código o el QR y empiezas a transmitir.",
            "Para renovar: «Renovar con mi código» y pagas los meses que quieras.",
        ]
    if nombre.startswith("Flota Tukán"):
        return [
            f"Pide tu demo de 3 días por WhatsApp ({WHATSAPP}) o en {WEB}.",
            "Pagas con el enlace de pago de la web (tarjeta) o con Google Play dentro de la app.",
            "Se activa al aprobarse el pago.",
        ]
    if nombre == "TuFactura":
        return [
            "Empiezas con una demo de práctica de 15 días: clientes, productos y facturas de ejemplo (sin RUC ni firma; no van al SRI).",
            "Para facturar de verdad necesitas tu firma electrónica y tu RUC (Configuración → Empezar a facturar de verdad).",
            "Pagas por 1, 2 o 3 años.",
        ]
    return []


def linea_periodos(nombre):
    if nombre == "WhatsApp Tukán":
        return "Se paga mes a mes, sin contrato (no hay planes semestrales ni anuales)."
    if nombre == "TuBroadcast":
        return "Se paga por meses × celulares (1, 3, 6 o 12 meses), sin meses de regalo; o con el Pase de 24 horas."
    if nombre == "TuFactura":
        return "Se paga por años: 1, 2 o 3."
    return "Mensual, semestral (pagas 5 meses y recibes 6) o anual (pagas 10 meses y recibes 12)."


def condiciones(nombre):
    c = [
        "Precios en dólares, sin IVA. Al pagar se suma el IVA del 15 %.",
        linea_periodos(nombre),
        "Cada pago lleva su factura electrónica autorizada por el SRI.",
    ]
    if nombre in MODULARES or nombre == "TuHospedaje":
        c.append("No se baja de plan ni se quitan módulos durante el período pagado; pasarte al plan completo sí puedes siempre.")
    c += [
        "Cancelas cuando quieras y sigues activo hasta el final del período pagado. No hay devoluciones si el programa se usó después del pago.",
        "Si un pago se atrasa más de 15 días se puede suspender el acceso (tus datos no se borran). Cualquier cambio de precio se avisa con al menos 30 días.",
        "Soporte por WhatsApp y correo en horario de oficina de Ecuador. Respaldos automáticos cifrados todos los días.",
        f"Términos completos: {WEB}/terminos.html",
    ]
    return c

# --------------------------------------------------------------------------------------
# 4. HTML
# --------------------------------------------------------------------------------------

def esc(t):
    t = html.escape(t, quote=False)
    t = re.sub(r"(\d) %", "\\1&nbsp;%", t)
    t = re.sub(r"\*\*(.+?)\*\*", r"<strong>\1</strong>", t)
    t = re.sub(r"\*(.+?)\*", r"<em>\1</em>", t)
    return t


def nombres_planes(tabla_precios):
    filas = [f for f in tabla_precios[1:] if "referencia" not in f[0].lower()]
    basico = re.sub(r"\s*\(.*?\)", "", filas[0][0]).strip()
    completo = re.sub(r"\s*\(.*?\)", "", filas[-1][0]).strip() if len(filas) > 1 else basico
    return basico, completo


def html_precios(filas):
    cab = filas[0]
    grupos = []  # (etiqueta, [sub]) o (etiqueta, None)
    j = 1
    while j < len(cab):
        h = cab[j]
        if "sin IVA" in h and j + 1 < len(cab) and "con IVA" in cab[j + 1]:
            etiqueta = h.replace(" sin IVA", "").strip()
            etiqueta = etiqueta.replace("(pagas 5)", "· pagas 5").replace("(pagas 10)", "· pagas 10")
            etiqueta = etiqueta.replace("Semestral", "Semestral (6 meses)").replace("Anual", "Anual (12 meses)")
            grupos.append((etiqueta, ["sin IVA", "con IVA"]))
            j += 2
        else:
            grupos.append((h, None))
            j += 1
    h1 = '<tr><th rowspan="2" class="izq">Plan</th>'
    h2 = "<tr>"
    for etq, sub in grupos:
        if sub:
            h1 += f'<th colspan="2">{esc(etq)}</th>'
            h2 += "".join(f"<th class='sub'>{s}</th>" for s in sub)
        else:
            h1 += f'<th rowspan="2">{esc(etq)}</th>'
    h1 += "</tr>"
    h2 += "</tr>"
    cuerpo = ""
    for f in filas[1:]:
        ref = "referencia" in f[0].lower()
        nombre = esc(f[0].replace("*(referencia)*", "").strip())
        if ref:
            nombre += ' <span class="ref">referencia</span>'
        tds = ""
        if any("(pago único)" in v for v in f[1:]):
            nombre += " · pago único"
            f = [f[0]] + [v.replace(" (pago único)", "") for v in f[1:]]
        for k, v in enumerate(f[1:]):
            # en cada par, la primera celda (sin IVA) va resaltada
            cls = "precio" if (k % 2 == 0) else "coniva"
            if v in ("No aplica", "—"):
                cls = "na"
            tds += f'<td class="{cls}">{esc(v)}</td>'
        cuerpo += f'<tr class="{"fila-ref" if ref else ""}"><td class="izq plan">{nombre}</td>{tds}</tr>'
    return f'<table class="precios"><thead>{h1}{h2}</thead><tbody>{cuerpo}</tbody></table>'


def html_planes(bloques):
    tarjetas = []
    base = None
    actual = None
    for tipo, val in bloques:
        if tipo == "titulo":
            if val.lower().startswith("la base"):
                actual = None
                base = []
                continue
            partes = re.split(r"\s+—\s+", val, maxsplit=1)
            actual = {"nombre": partes[0], "precio": partes[1] if len(partes) > 1 else "", "items": []}
            tarjetas.append(actual)
        elif tipo == "lista":
            if actual is not None and base is None:
                actual["items"] += val
            elif base is not None:
                base += val
    n = len(tarjetas)
    cols = 3 if n == 3 else (2 if n in (2, 4) else 1)
    out = f'<div class="planes cols{cols}">'
    for t in tarjetas:
        items = "".join(f"<li>{esc(a_cliente(i))}</li>" for i in t["items"] if a_cliente(i))
        out += (f'<div class="plan-card"><div class="plan-nombre">{esc(t["nombre"])}</div>'
                f'<div class="plan-precio">{esc(t["precio"])}</div><ul>{items}</ul></div>')
    out += "</div>"
    if base:
        out += '<div class="base"><div class="base-titulo">La base: viene en todos los planes</div>'
        sub = all(": " in b and b.count(";") >= 2 for b in base)
        if sub:
            for b in base:
                titulo, resto = b.split(": ", 1)
                out += f'<div class="base-sub">{esc(titulo)}</div><ul class="dos-col">'
                out += "".join(f"<li>{esc(x.strip())}</li>" for x in resto.split(";"))
                out += "</ul>"
        else:
            out += '<ul class="dos-col">' + "".join(f"<li>{esc(a_cliente(b))}</li>" for b in base) + "</ul>"
        out += "</div>"
    return out


def celda_modulo(v):
    v2 = v.replace("Uno de los 3 o $5,00", "Uno de tus 3, o $5,00 al mes")
    if v.startswith("Sí"):
        return f'<span class="si">{esc(v2)}</span>'
    if v.startswith("No"):
        return f'<span class="no">{esc(v2)}</span>'
    if v in ("Incluido",):
        return f'<span class="si">{esc(v2)}</span>'
    return esc(v2)


def html_tabla_modulos(filas, basico, completo):
    cab = list(filas[0])
    if cab[2] == "Precio en el Básico":
        cab[2] = f"Precio en el plan {basico}"
    if cab[3] == "¿En el Completo?":
        cab[3] = f"¿En el plan {completo}?"
    if cab[0] == "Accesorio (módulo)":
        cab[0] = "Módulo"
    h = "".join(f"<th>{esc(c)}</th>" for c in cab)
    cuerpo = ""
    for f in filas[1:]:
        nombre = f[0]
        if nombre.startswith("Factura electrónica con TuFactura"):
            nombre += " (próximamente)"
        desc = f[1].replace("(hora extra.", "(hora extra y más).").replace("..", ".")
        if "(próximamente)" in f[0] and f[2].startswith("Uno de los 3"):
            f = [f[0], f[1], "Próximamente (pídelo por cotización)", f[3]]
        desc = a_cliente(desc)
        cuerpo += (f"<tr><td class='mod'>{esc(nombre)}</td><td>{esc(desc)}</td>"
                   f"<td class='c'>{celda_modulo(f[2])}</td><td class='c'>{celda_modulo(f[3])}</td></tr>")
    return f'<table class="modulos"><thead><tr>{h}</tr></thead><tbody>{cuerpo}</tbody></table>'


def html_modulos(bloques, basico, completo, nombre=""):
    out = ""
    n_tabla = 0
    for tipo, val in bloques:
        if tipo == "tabla":
            b, c = basico, completo
            if nombre == "TuVocalía":
                b, c = (("Básico (ligas)", "Profesional") if n_tabla == 0 else ("Escuela Básico", "Escuela Completo"))
                if n_tabla == 0:
                    out += '<div class="subtitulo">Módulos de ligas (Básico / Profesional)</div>'
            out += html_tabla_modulos(val, b, c)
            n_tabla += 1
        elif tipo == "titulo":
            t = val
            if t.startswith("Otros extras"):
                t = "Otros extras"
            out += f'<div class="subtitulo">{esc(t)}</div>'
        elif tipo == "lista":
            out += "<ul class='extras'>" + "".join(f"<li>{esc(a_cliente(i))}</li>" for i in val) + "</ul>"
        elif tipo == "texto":
            out += f"<p>{esc(a_cliente(val))}</p>"
    return out


def html_lista_simple(bloques, nombre_prog=None):
    items = []
    for tipo, val in bloques:
        if tipo == "lista":
            for i in val:
                if i.startswith("Factura electrónica con TuFactura: el módulo de enlace"):
                    items.append(TUFACTURA_CLIENTE)
                    continue
                if i.startswith("Flota Tukán no lleva enlace con TuFactura"):
                    items.append("Flota Tukán no se conecta con TuFactura: sirve para controlar tus vehículos, no para facturar.")
                    continue
                if i.startswith("No llevan enlace: Flota Tukán"):
                    items.append("No necesitan TuFactura: Flota Tukán (es para controlar vehículos, no para facturar) ni TuBroadcast (Tukán le factura directamente).")
                    continue
                items.append(a_cliente(i))
        elif tipo == "texto":
            items.append(a_cliente(val))
    return "<ul>" + "".join(f"<li>{esc(i)}</li>" for i in items if i) + "</ul>"


def html_ejemplos(bloques, basico):
    tarjetas = []
    actual = None
    for tipo, val in bloques:
        if tipo == "titulo":
            titulo = a_cliente(re.sub(r"^Ejemplo \d+:\s*", "", val))
            actual = {"titulo": titulo, "lineas": [], "total": "", "nota": ""}
            tarjetas.append(actual)
        elif tipo == "lista" and actual is not None:
            for i in val:
                if i.startswith("**Total:"):
                    actual["total"] = i.strip("*").replace("Total: ", "")
                elif i.startswith("*") and i.endswith("*"):
                    actual["nota"] = nota_ejemplo(i, basico)
                else:
                    m = re.match(r"(.+):\s*(-?\$[\d.,]+)$", i)
                    if m:
                        actual["lineas"].append((m.group(1), m.group(2)))
                    else:
                        actual["lineas"].append((i, ""))
    out = f'<div class="ejemplos{" tres" if len(tarjetas) == 3 else ""}">'
    for n, t in enumerate(tarjetas, 1):
        filas = "".join(f'<tr><td>{esc(a)}</td><td class="r">{esc(b)}</td></tr>' for a, b in t["lineas"])
        nota = f'<div class="nota">{esc(t["nota"])}</div>' if t["nota"] else ""
        out += (f'<div class="ej"><div class="ej-num">Ejemplo {n}</div><div class="ej-tit">{esc(t["titulo"])}</div>'
                f'<table>{filas}</table><div class="ej-total">{esc(t["total"])}</div>{nota}</div>')
    out += "</div>"
    return out


CSS = """
@page { size: A4; margin: 14mm 13mm 16mm 13mm;
  @bottom-left { content: "Tukán Software · __PROG__ · Precios vigentes __VIG__ · Valores sin IVA"; font: 7.5pt Inter, sans-serif; color: #7a8194; }
  @bottom-right { content: "Página " counter(page) " de " counter(pages); font: 7.5pt Inter, sans-serif; color: #7a8194; }
}
* { box-sizing: border-box; }
body { font-family: Inter, sans-serif; color: #1d2433; font-size: 9.3pt; line-height: 1.42; margin: 0; }
.head { background: var(--c); color: #fff; border-radius: 10px; padding: 16px 20px 15px; position: relative; overflow: hidden; }
.head .marca { font-size: 7.6pt; letter-spacing: .12em; text-transform: uppercase; opacity: .92; font-weight: 600; }
.head h1 { font-size: 27pt; margin: 4px 0 2px; font-weight: 800; letter-spacing: -.01em; }
.head .para { font-size: 10.5pt; opacity: .95; max-width: 78%; }
.head .vig { position: absolute; right: 18px; top: 16px; background: rgba(255,255,255,.18); padding: 4px 10px; border-radius: 20px; font-size: 7.6pt; font-weight: 600; }
.head .circulo { position: absolute; right: -40px; bottom: -70px; width: 170px; height: 170px; border-radius: 50%; background: rgba(255,255,255,.12); }
.prox { background: #FFF4D6; border: 1px solid #F2C94C; color: #6b4e00; padding: 8px 12px; border-radius: 8px; margin-top: 10px; font-weight: 600; }
h2 { font-size: 12.5pt; margin: 18px 0 8px; color: #15213B; display: flex; align-items: center; gap: 8px; break-after: avoid; }
h2::before { content: ""; width: 9px; height: 9px; background: var(--c); border-radius: 2px; display: inline-block; }
table { border-collapse: collapse; width: 100%; }
.precios { font-size: 9pt; }
.precios th { background: #15213B; color: #fff; font-weight: 600; padding: 6px 6px; text-align: right; font-size: 8.2pt; }
.precios th.sub { background: #26324f; font-weight: 500; font-size: 7.6pt; }
.precios th.izq, .precios td.izq { text-align: left; }
.precios td { padding: 7px 6px; border-bottom: 1px solid #e6e9f0; text-align: right; }
.precios td.precio { font-weight: 700; font-size: 10pt; }
.precios td.coniva { color: #69708a; }
.precios td.na { color: #9aa0b2; }
.precios td.plan { font-weight: 700; }
.precios tr.fila-ref td { color: #69708a; font-weight: 500; font-style: italic; }
.precios tr.fila-ref td.precio { font-weight: 600; font-size: 9pt; }
.ref { font-size: 7pt; background: #eef0f5; color: #69708a; padding: 1px 6px; border-radius: 10px; font-style: normal; font-weight: 600; }
.nota-precios { font-size: 8pt; color: #69708a; margin-top: 5px; }
.caja { background: #f6f7fa; border: 1px solid #e6e9f0; border-radius: 8px; padding: 10px 13px; }
.caja p { margin: 0; }
.planes { display: grid; gap: 10px; }
.planes.cols1 { grid-template-columns: 1fr; } .planes.cols2 { grid-template-columns: 1fr 1fr; } .planes.cols3 { grid-template-columns: 1fr 1fr 1fr; }
.plan-card { border: 1px solid #e1e5ee; border-top: 4px solid var(--c); border-radius: 8px; padding: 10px 12px; break-inside: avoid; }
.plan-nombre { font-weight: 800; font-size: 11pt; color: #15213B; }
.plan-precio { font-weight: 700; color: var(--c); font-size: 11pt; margin-bottom: 5px; }
.plan-card ul { margin: 0; padding-left: 15px; } .plan-card li { margin: 2px 0; }
.base { margin-top: 10px; border: 1px solid #e1e5ee; border-radius: 8px; padding: 10px 13px; background: #fbfbfd; break-inside: avoid; }
.base-titulo { font-weight: 700; color: #15213B; margin-bottom: 4px; }
.base-sub { font-weight: 700; color: var(--c); margin-top: 6px; }
ul.dos-col { columns: 2; column-gap: 22px; margin: 0; padding-left: 15px; } ul.dos-col li { break-inside: avoid; margin: 2px 0; }
.modulos { font-size: 8.4pt; }
.modulos th { background: #15213B; color: #fff; text-align: left; padding: 6px 7px; font-weight: 600; font-size: 8pt; }
.modulos td { padding: 6px 7px; border-bottom: 1px solid #e6e9f0; vertical-align: top; }
.modulos tr { break-inside: avoid; }
.modulos tbody tr:nth-child(even) td { background: #fafbfd; }
.modulos td.mod { font-weight: 700; width: 23%; color: #15213B; }
.modulos td.c { width: 15%; }
.modulos td.c:nth-child(3) { width: 19%; }
.si { color: #1f8a4c; font-weight: 700; } .no { color: #c0601a; font-weight: 700; }
.subtitulo { font-weight: 700; color: #15213B; margin: 12px 0 6px; }
ul.extras { margin: 0; padding-left: 16px; }
.dos { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.mini { border: 1px solid #e1e5ee; border-radius: 8px; padding: 10px 13px; break-inside: avoid; }
.mini.amar { background: #FFFBEF; border-color: #F5DE9A; }
.mini-t { font-weight: 700; color: #15213B; margin-bottom: 3px; }
.mini ul { margin: 0; padding-left: 15px; } .mini li { margin: 2px 0; }
.ejemplos { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.ejemplos.tres { grid-template-columns: 1fr 1fr 1fr; gap: 8px; }
.ej { border: 1px solid #e1e5ee; border-radius: 8px; padding: 10px 12px; break-inside: avoid; display: flex; flex-direction: column; }
.ej-num { font-size: 7.4pt; text-transform: uppercase; letter-spacing: .1em; color: var(--c); font-weight: 700; }
.ej-tit { font-weight: 700; color: #15213B; margin: 2px 0 6px; }
.ej table td { padding: 3px 0; border-bottom: 1px dashed #e6e9f0; font-size: 8.4pt; vertical-align: top; }
.ej td.r { text-align: right; white-space: nowrap; padding-left: 8px; font-weight: 600; }
.ej-total { margin-top: 7px; background: #15213B; color: #fff; border-radius: 6px; padding: 6px 9px; font-weight: 700; }
.nota { margin-top: 6px; font-size: 8.3pt; color: #45506a; background: #f6f7fa; border-left: 3px solid var(--c); padding: 5px 8px; border-radius: 4px; }
ol.pasos { counter-reset: p; list-style: none; padding: 0; margin: 0; display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
ol.pasos li { counter-increment: p; position: relative; padding: 9px 11px 9px 40px; border: 1px solid #e1e5ee; border-radius: 8px; break-inside: avoid; }
ol.pasos li::before { content: counter(p); position: absolute; left: 10px; top: 9px; width: 21px; height: 21px; border-radius: 50%; background: var(--c); color: #fff; font-weight: 700; text-align: center; line-height: 21px; font-size: 9pt; }
.cond ul { margin: 0; padding-left: 15px; columns: 2; column-gap: 22px; } .cond li { break-inside: avoid; margin: 2px 0; font-size: 8.4pt; }
.contacto { margin-top: 14px; background: #15213B; color: #fff; border-radius: 10px; padding: 14px 18px; display: flex; justify-content: space-between; align-items: center; break-inside: avoid; }
.contacto .t { font-size: 13pt; font-weight: 800; } .contacto .t span { color: #FFC93C; }
.contacto .d { text-align: right; font-size: 9pt; line-height: 1.6; }
.contacto .d b { color: #FFC93C; }
.bloque { break-inside: avoid; }
"""


def construir_html(p):
    s = p["secciones"]
    nombre = p["nombre"]
    b_precios = tablas_y_resto(s.get("Precios", []))
    tabla_precios = next(v for t, v in b_precios if t == "tabla")
    basico, completo = nombres_planes(tabla_precios)

    if nombre == "TuAcademia":
        s = dict(s)
        s["Qué incluye cada plan"] = [l.replace("Página pública de la escuela", "Página pública de la academia") for l in s["Qué incluye cada plan"]]
    partes = []
    prox = ""
    if nombre == "TuFactura":
        prox = '<div class="prox">Próximamente: TuFactura estará disponible en cuanto el SRI complete la autorización. Esta hoja te muestra cómo será y cuánto costará.</div>'
    partes.append(f"""
<div class="head"><div class="circulo"></div>
  <div class="marca">Tukán Software · Planes y precios</div>
  <h1>{esc(nombre)}</h1>
  <div class="para">{esc(p['para_quien'])}</div>
  <div class="vig">Precios vigentes · {VIGENCIA}</div>
</div>{prox}""")

    # Precios
    nota_ref = ""
    for t, v in b_precios:
        if t == "texto" and v.startswith("*Referencia"):
            nota_ref = " " + v.strip("*").replace("el Básico", f"el plan {basico}")
    partes.append(f'<div class="bloque"><h2>Precios</h2>{html_precios(tabla_precios)}'
                  f'<div class="nota-precios">Valores en dólares. «sin IVA» es el precio; «con IVA» es lo que pagas con el IVA del 15 %.{esc(nota_ref)}</div></div>')

    # Cómo se arma
    if "Cómo se arma el plan" in s:
        txt = " ".join(v for t, v in tablas_y_resto(s["Cómo se arma el plan"]) if t == "texto")
        partes.append(f'<div class="bloque"><h2>Cómo se arma tu plan</h2><div class="caja"><p>{esc(a_cliente(txt))}</p></div></div>')

    # Planes
    if "Qué incluye cada plan" in s:
        bq = tablas_y_resto(s["Qué incluye cada plan"])
        uno = sum(1 for t, v in bq if t == "titulo" and not v.lower().startswith("la base")) == 1
        hp = html_planes(bq)
        if uno:
            hp = hp.replace("La base: viene en todos los planes", "La base del programa")
        partes.append(f'<h2>{"Qué incluye" if uno else "Qué incluye cada plan"}</h2>{hp}')

    # Módulos
    if "Accesorios (módulos)" in s:
        partes.append(f'<h2>Módulos</h2>{html_modulos(tablas_y_resto(s["Accesorios (módulos)"]), basico, completo, nombre)}')

    # Dependencias + otros programas
    dep = s.get("Van juntos (dependencias)")
    otros = s.get("Con otros programas Tukán")
    cajas = ""
    if dep:
        cajas += f'<div class="mini amar"><div class="mini-t">Módulos que van juntos</div>{html_lista_simple(tablas_y_resto(dep))}</div>'
    if otros:
        cajas += f'<div class="mini"><div class="mini-t">Con otros programas Tukán</div>{html_lista_simple(tablas_y_resto(otros))}</div>'
    if cajas:
        cls = "dos" if (dep and otros) else ""
        partes.append(f'<div class="bloque"><h2>Para tener en cuenta</h2><div class="{cls}">{cajas}</div></div>')

    # Ejemplos
    if "Ejemplos de venta" in s:
        partes.append(f'<h2>Ejemplos de planes</h2>{html_ejemplos(tablas_y_resto(s["Ejemplos de venta"]), basico)}')

    # Cómo contratar
    pasos = pasos_contratar(nombre)
    if pasos:
        partes.append('<div class="bloque"><h2>Cómo contratar</h2><ol class="pasos">' +
                      "".join(f"<li>{esc(x)}</li>" for x in pasos) + "</ol></div>")

    # Condiciones
    partes.append('<div class="bloque cond"><h2>Condiciones</h2><div class="caja"><ul>' +
                  "".join(f"<li>{esc(x)}</li>" for x in condiciones(nombre)) + "</ul></div></div>")

    partes.append(f"""<div class="contacto"><div class="t">¿Listo para empezar con <span>{esc(nombre.split(" (")[0])}</span>?</div>
<div class="d">WhatsApp <b>{WHATSAPP}</b><br>{CORREO} · {WEB}</div></div>""")

    css = CSS.replace("__PROG__", nombre.replace('"', "")).replace("__VIG__", VIGENCIA)
    return f"""<!DOCTYPE html><html lang="es"><head><meta charset="utf-8">
<title>{esc(nombre)} · Planes y precios · Tukán Software</title>
<style>{css}</style></head>
<body style="--c:{p['color']}">{''.join(partes)}</body></html>"""


def slug(nombre):
    n = nombre.split(" (")[0]
    n = unicodedata.normalize("NFKD", n).encode("ascii", "ignore").decode()
    return re.sub(r"[^A-Za-z0-9]+", "_", n).strip("_")


def a_pdf(html_path, pdf_path):
    subprocess.run([
        "google-chrome", "--headless=new", "--no-sandbox", "--disable-gpu", "--no-pdf-header-footer",
        f"--print-to-pdf={pdf_path}", html_path.as_uri()
    ], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=120)


def main():
    progs = leer_programas(FUENTE.read_text(encoding="utf-8"))
    solo = sys.argv[1:] or None
    SALIDA.mkdir(exist_ok=True)
    HTML_DIR.mkdir(exist_ok=True)
    hechos = []
    for p in progs:
        if solo and p["nombre"] not in solo:
            continue
        h = construir_html(p)
        hp = HTML_DIR / f"Tukan_{slug(p['nombre'])}.html"
        hp.write_text(h, encoding="utf-8")
        pdf = SALIDA / f"Tukan_{slug(p['nombre'])}_Planes_y_Precios.pdf"
        a_pdf(hp, pdf)
        hechos.append(pdf.name)
        print("OK", pdf.name)
    print(f"{len(hechos)} PDF generados en {SALIDA}")


if __name__ == "__main__":
    main()
