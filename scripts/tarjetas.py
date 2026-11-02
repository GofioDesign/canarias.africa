"""Tarjetas para compartir (Open Graph): la imagen que enseñan WhatsApp, Telegram, Facebook, X, LinkedIn… al pegar un enlace.

Formato: 1200x630 (1,91:1), menos de 300 KB (WhatsApp no muestra la vista previa si la imagen pesa más).
Identidad: fondo oscuro de la cabecera, estrella verde de cinco puntas, franja blanco·azul·amarillo de la bandera.

- python3 scripts/tarjetas.py              → web/compartir.png (la de la portada; se guarda en el repositorio)
- generar-fichas.py la usa al publicar para la tarjeta de cada obra, soporte y trayectoria (si hay Pillow).
Fuentes: Inter Display si está instalada (paquete fonts-inter), si no DejaVu o Liberation.
"""
import io
import sys
from pathlib import Path

from PIL import Image, ImageDraw, ImageFont

ANCHO, ALTO = 1200, 630
FONDO, TINTA, APAGADO = (18, 22, 20), (236, 239, 234), (155, 166, 159)
VERDE, VERDE_CLARO, AZUL, AMARILLO = (10, 138, 58), (47, 168, 79), (7, 104, 169), (255, 204, 0)
# La misma estrella que la marca de la cabecera (clip-path de web/index.html), en tantos por uno.
ESTRELLA = [(.5, .04), (.612, .385), (.976, .385), (.682, .599), (.794, .945), (.5, .731), (.206, .945), (.318, .599), (.024, .385), (.388, .385)]

FUENTES = {
    "negra": ["/usr/share/fonts/opentype/inter/InterDisplay-ExtraBold.otf", "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf",
              "/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf"],
    "media": ["/usr/share/fonts/opentype/inter/InterDisplay-SemiBold.otf", "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf",
              "/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf"],
    "texto": ["/usr/share/fonts/opentype/inter/InterDisplay-Regular.otf", "/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf",
              "/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf"],
    "cursiva": ["/usr/share/fonts/opentype/inter/InterDisplay-Italic.otf", "/usr/share/fonts/truetype/dejavu/DejaVuSans-Oblique.ttf",
                "/usr/share/fonts/truetype/liberation/LiberationSans-Italic.ttf"],
    "mono": ["/usr/share/fonts/truetype/jetbrains-mono/JetBrainsMono-Regular.ttf", "/usr/share/fonts/truetype/dejavu/DejaVuSansMono.ttf",
             "/usr/share/fonts/truetype/liberation/LiberationMono-Regular.ttf"],
}


def fuente(estilo, tam):
    for ruta in FUENTES[estilo]:
        if Path(ruta).exists():
            return ImageFont.truetype(ruta, tam)
    return ImageFont.load_default(tam)


def estrella(d, cx, cy, lado, color):
    d.polygon([(cx + (x - .5) * lado, cy + (y - .5) * lado) for x, y in ESTRELLA], fill=color)


def envolver(d, texto, f, ancho, max_lineas):
    """Corta el texto en líneas que caben en `ancho`; la última lleva «…» si sobra texto."""
    palabras, lineas, actual = texto.split(), [], ""
    for p in palabras:
        prueba = f"{actual} {p}".strip()
        if d.textlength(prueba, font=f) <= ancho:
            actual = prueba
        else:
            if actual:
                lineas.append(actual)
            actual = p
        if len(lineas) == max_lineas:
            break
    if actual and len(lineas) < max_lineas:
        lineas.append(actual)
    if len(lineas) == max_lineas and " ".join(lineas) != " ".join(palabras):
        while lineas[-1] and d.textlength(lineas[-1] + "…", font=f) > ancho:
            lineas[-1] = lineas[-1][:-1]
        lineas[-1] = lineas[-1].rstrip(" ,.;:·") + "…"
    return lineas


def titulo_ajustado(d, texto, ancho, tams=(96, 84, 72, 62, 54), max_lineas=3):
    for t in tams:
        f = fuente("negra", t)
        lineas = envolver(d, texto, f, ancho, max_lineas)
        if len(lineas) <= 2 or t == tams[-1]:
            return f, lineas, t
    return f, lineas, t


def con_foto(img, foto):
    """La foto de la obra a sangre en la mitad derecha, fundida con el fondo hacia la izquierda."""
    try:
        f = Image.open(io.BytesIO(foto)).convert("RGB")
    except Exception:  # noqa: BLE001 — una foto rota no impide la tarjeta
        return False
    w = 560
    esc = max(w / f.width, ALTO / f.height)
    f = f.resize((round(f.width * esc), round(f.height * esc)), Image.LANCZOS)
    x0, y0 = (f.width - w) // 2, (f.height - ALTO) // 2
    f = f.crop((x0, y0, x0 + w, y0 + ALTO))
    mascara = Image.linear_gradient("L").rotate(90).resize((w, ALTO))  # transparente a la izquierda, opaca a la derecha
    mascara = mascara.point(lambda v: min(255, int(v * 1.6)))
    img.paste(f, (ANCHO - w, 0), mascara)
    return True


def tarjeta(titulo, antetitulo="", texto="", pie="canarias.africa", ident="", foto=None, portada=False):
    """Devuelve los bytes de la imagen (PNG sin foto, JPEG con foto para que pese poco)."""
    img = Image.new("RGB", (ANCHO, ALTO), FONDO)
    d = ImageDraw.Draw(img)
    hay_foto = bool(foto) and con_foto(img, foto)
    d = ImageDraw.Draw(img)
    if not hay_foto:  # la estrella grande, recortada por el borde derecho
        estrella(d, 1010, 300, 560, VERDE)
        estrella(d, 1010, 300, 250, FONDO)
        estrella(d, 1010, 300, 170, VERDE_CLARO)
    margen, ancho_txt = 72, (600 if hay_foto else 650)
    # Marca: estrella pequeña + CANARIAS.AFRICA
    estrella(d, margen + 15, 78, 32, VERDE_CLARO)
    fm = fuente("negra", 30)
    d.text((margen + 42, 78), "CANARIAS", font=fm, fill=VERDE_CLARO, anchor="lm")
    d.text((margen + 42 + d.textlength("CANARIAS", font=fm), 78), ".AFRICA", font=fm, fill=TINTA, anchor="lm")
    y = 150
    if antetitulo:
        fa = fuente("mono", 24)
        d.text((margen, y), envolver(d, antetitulo.upper(), fa, ANCHO - 2 * margen - 120, 1)[0], font=fa, fill=APAGADO)
        y += 46
    if portada:
        f = fuente("negra", 132)
        d.text((margen, y - 14), titulo, font=f, fill=TINTA)
        y += 150
    else:
        f, lineas, t = titulo_ajustado(d, titulo, ancho_txt)
        for ln in lineas:
            d.text((margen, y), ln, font=f, fill=TINTA)
            y += round(t * 1.08)
        y += 14
    if texto:
        ft = fuente("cursiva" if portada else "texto", 34 if portada else 30)
        for ln in envolver(d, texto, ft, ancho_txt, 3 if portada else 2):
            d.text((margen, y), ln, font=ft, fill=TINTA if portada else APAGADO)
            y += 46 if portada else 42
    # Pie: identificador y dominio
    fp = fuente("media", 30)
    d.text((margen, ALTO - 64), pie, font=fp, fill=TINTA, anchor="ls")
    if ident:
        fi = fuente("mono", 20)
        d.text((margen + d.textlength(pie, font=fp) + 22, ALTO - 66), ident, font=fi, fill=APAGADO, anchor="ls")
    # Franja de la bandera
    tercio = ANCHO / 3
    for i, c in enumerate(((255, 255, 255), AZUL, AMARILLO)):
        d.rectangle((round(i * tercio), ALTO - 14, round((i + 1) * tercio), ALTO), fill=c)
    salida = io.BytesIO()
    if hay_foto:
        img.save(salida, "JPEG", quality=82, optimize=True, progressive=True)
    else:
        img.quantize(colors=64, method=Image.Quantize.MEDIANCUT).save(salida, "PNG", optimize=True)
    return salida.getvalue(), ("jpg" if hay_foto else "png")


def portada():
    datos, _ = tarjeta("CANARIAS", antetitulo="Catálogo Atlántico de Núnea Arqueología",
                       texto="«El arte no nos pertenece. Su memoria la construimos entre todos.»",
                       pie="canarias.africa", ident="Registro de Intervenciones Artísticas Socializado", portada=True)
    return datos


if __name__ == "__main__":
    destino = Path(sys.argv[1] if len(sys.argv) > 1 else "web/compartir.png")
    destino.write_bytes(portada())
    print(f"{destino}: {destino.stat().st_size // 1024} KB")
