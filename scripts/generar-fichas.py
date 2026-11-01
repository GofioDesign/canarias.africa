"""Genera, al publicar, las páginas estáticas que hacen visible el rastro en archive.org.

- web/obra/<uuid>/index.html: ficha estática de cada obra (la que captura la Wayback Machine), con sus
  estratos en archive.org y su item de Internet Archive. Es HTML sin JavaScript, para que la captura
  se lea igual dentro de veinte años; enlaza a la ficha completa de la web (/#obra-<uuid>).
- web/estratos/index.html: todas las capturas, de la más reciente a la más antigua.
- web/datos/archivo.json: el mismo rastro para la web (sección «Estratos en archive.org» de cada ficha).
- web/datos/historial.json: identificador, versión, fecha y árbol de modificaciones de cada obra,
  sacados del historial de git de web/datos/obras.geojson (cada commit que cambia la obra es una versión),
  y su escala, espacio, líneas temporales y vínculos.

Modelo (propiedades de cada obra o expresión en el catálogo):
- "fecha" (y "fecha_fin"): el tiempo siempre está; el espacio no.
- "espacio": lugar, red o sin_espacio (si falta, se deduce de la geometría, el municipio o la url).
- "escala": una de datos/escalas.json (persona, familia, amistades, barrio, municipio, mancomunidad, isla,
  provincia, Canarias, Macaronesia, estado, continente, global…). Las íntimas no se archivan sin consentimiento.
- "autoria" y "colaboraciones": ["Nombre o alias", …] o [{"id", "nombre", "rol"}, …]: la trayectoria de cada
  artista, colectivo o colaborador es otra línea temporal que vincula espacios (/agente/<id>/ y
  /datos/trayectorias.json). Quienes colaboran también forman parte de la obra.
  Privacidad: las personas no son identificables en la red pública salvo que quieran publicarlo ("publica": true)
  o sean personajes públicos. La autoría acreditada es pública salvo "publica": false; una colaboración, solo con
  "publica": true. Lo que no es público no va en el catálogo: solo su número, "vinculos_privados", que el glifo
  dibuja en discontinuo y a poco contraste, sin nombres ni destinos.
- "relaciones": [{"tipo", "a": <uuid>, "nota"}]. El tipo "muestra_de" (o el atajo "muestra_de": <uuid> o
  [<uuid>, …]) liga la línea temporal propia a otra más general: un evento global puede dar lugar a x
  muestras locales, y una muestra puede estar ligada a varias líneas a la vez. Los demás tipos (responde_a,
  inspirada_en, cita, continua, se_superpone_a, dialoga… o cualquiera nuevo) son vínculos abiertos.

Lee web/datos/obras.geojson (ya sin coordenadas sensibles) y datos/archivo/registro.json.
Las páginas se generan en cada publicación y no se suben al repositorio.
Uso: python3 scripts/generar-fichas.py
"""
import hashlib
import html
import json
import math
import os
import re
import subprocess
import unicodedata
from pathlib import Path

CATALOGO = Path("web/datos/obras.geojson")
REGISTRO = Path("datos/archivo/registro.json")
WEB = Path("web")
REPO = os.environ.get("GITHUB_REPOSITORY", "GofioDesign/canarias.africa")
NOMBRES_CAMPO = {"name": "título", "descripcion": "descripción", "description": "descripción", "fase": "fase",
                 "fase_nombre": "fase", "sensibilidad": "ubicación (sensibilidad)", "municipio": "municipio",
                 "url": "obra web", "relaciones": "relaciones", "precision": "precisión"}

ESTILO = """
:root{--bg:#faf6ee;--fg:#16130f;--muted:#6b6358;--verde:#008000;--linea:#e2dacb}
@media (prefers-color-scheme:dark){:root{--bg:#12100d;--fg:#f4eee2;--muted:#a59c8e;--verde:#3fbf3f;--linea:#2c2822}}
body{margin:0;background:var(--bg);color:var(--fg);font:16px/1.55 system-ui,sans-serif}
main{max-width:46rem;margin:0 auto;padding:28px 16px 48px}
a{color:var(--verde)} h1{font-size:1.7rem;line-height:1.2;margin:.2em 0 .4em}
.eyebrow{font-size:.8rem;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);margin:0}
.muted{color:var(--muted)} dl{display:grid;grid-template-columns:max-content 1fr;gap:4px 16px}
dt{color:var(--muted)} dd{margin:0}
ol.estratos{list-style:none;padding:0;border-left:2px solid var(--verde);margin:16px 0}
ol.estratos>li{padding:4px 0 10px 14px;position:relative}
ol.estratos>li::before{content:"";position:absolute;left:-6px;top:11px;width:10px;height:10px;border-radius:50%;background:var(--verde)}
.idficha{display:flex;flex-wrap:wrap;gap:6px 14px;align-items:baseline;margin:6px 0 2px}
.idficha .mono{background:var(--fg);color:var(--bg);padding:2px 8px;border-radius:3px}
ul.cambios{margin:4px 0;padding-left:18px;list-style:disc;font-size:.95rem} del{color:var(--muted)} ins{text-decoration:none;font-weight:600}
.enlaces{font-size:.9rem}
.mundo{margin:18px 0;text-align:center}.mundo figcaption{font-size:.88rem;max-width:30rem;margin:6px auto 0}
.glifo{max-width:100%;height:auto;color:var(--fg)}
.tree ul{list-style:none;padding-left:16px;margin:2px 0;border-left:1px dashed var(--linea)}
.tree>ul{border-left:0;padding-left:0}
.mono{font-family:ui-monospace,monospace;font-size:.88rem;word-break:break-all}
"""

MOTIVOS = {
    "cambio en la web": "Cambio en la web",
    "commit": "Cambio en el repositorio",
    "obra nueva": "Obra registrada",
    "obra cambiada": "Ficha modificada",
    "obra web": "La obra web misma",
    "observaciones": "Observaciones aprobadas",
    "estrato semanal": "Estrato semanal",
}


def e(v):
    return html.escape(str(v or ""))


def pagina(titulo, cuerpo):
    return f"""<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{e(titulo)}</title>
<style>{ESTILO}</style>
</head>
<body><main>
{cuerpo}
</main></body>
</html>
"""


def git(*args):
    return subprocess.run(["git", *args], capture_output=True, text=True, check=True).stdout


def historial():
    """{uuid: [versión, …]}: cada commit que cambia una obra en el catálogo es una versión nueva."""
    try:
        log = git("log", "--reverse", "--format=%H%x09%cI", "--", str(CATALOGO)).split("\n")
    except (subprocess.CalledProcessError, FileNotFoundError):
        return {}
    hist, previas = {}, {}
    for linea in filter(None, log):
        sha, fecha = linea.split("\t")
        try:
            fs = json.loads(git("show", f"{sha}:{CATALOGO.as_posix()}")).get("features", [])
        except (subprocess.CalledProcessError, ValueError):
            continue
        for f in fs:
            p = f.get("properties", {})
            if not p.get("id"):
                continue
            uuid = p["id"].lower()
            antes = previas.get(uuid)
            if antes == f:
                continue
            cambios = []
            if antes is None:
                cambios.append({"campo": "alta"})
            else:
                pa = antes.get("properties", {})
                for k in sorted(set(pa) | set(p)):
                    if pa.get(k) != p.get(k):
                        cambios.append({"campo": k, "antes": pa.get(k), "ahora": p.get(k)})
                if antes.get("geometry") != f.get("geometry"):
                    cambios.append({"campo": "geometry"})  # sin valores: la ubicación puede ser sensible
            v = hist.setdefault(uuid, [])
            v.append({"version": len(v) + 1, "fecha": fecha, "commit": sha, "cambios": cambios})
            previas[uuid] = f
    return hist


# Escalas de las líneas temporales. Una expresión puede no tener espacio (en la red, o ninguno),
# pero siempre tiene tiempo, y su línea temporal puede estar ligada a otras de cualquier escala.
_ESC = json.loads(Path("datos/escalas.json").read_text(encoding="utf-8"))
ESCALAS = {x["id"]: x["nombre"] for x in _ESC["escalas"]}
ESCALA_DEF = _ESC["por_defecto"]
INTIMAS = {x["id"] for x in _ESC["escalas"] if x.get("intima")}
ESPACIOS = {"lugar": "En un lugar", "red": "En la red", "sin_espacio": "Sin espacio"}
# Vínculos tipados y abiertos: estos tienen nombre en los dos sentidos; cualquier otro tipo se muestra tal cual,
# así la comunidad puede proponer tipos nuevos sin tocar el código.
VINCULOS = {
    "muestra_de": ("muestra de", "muestras"),          # liga la línea temporal propia a una general
    "responde_a": ("responde a", "respuestas"),
    "inspirada_en": ("inspirada en", "ha inspirado"),
    "cita": ("cita", "citada por"),
    "continua": ("continúa", "continuada por"),
    "se_superpone_a": ("se superpone a", "debajo"),
    "dialoga": ("dialoga con", "dialoga con"),
}


def nombre_vinculo(tipo, inverso=False):
    par = VINCULOS.get(tipo)
    return (par[1] if inverso else par[0]) if par else tipo.replace("_", " ") + (" (recibido)" if inverso else "")


def relaciones(p):
    """Vínculos salientes de una obra: "relaciones": [{"tipo", "a", "nota"}] y el atajo "muestra_de"."""
    rs = [r for r in p.get("relaciones") or [] if isinstance(r, dict) and r.get("tipo") and r.get("a")]
    md = p.get("muestra_de")
    for a in (md if isinstance(md, list) else [md] if md else []):
        rs.append({"tipo": "muestra_de", "a": a})
    return [{**r, "a": str(r["a"]).lower()} for r in rs]


def espacio_de(f):
    p = f.get("properties", {})
    if p.get("espacio") in ESPACIOS:
        return p["espacio"]
    if f.get("geometry") or p.get("municipio") or p.get("sensibilidad") in ("difusa", "reservada"):
        return "lugar"
    return "red" if p.get("url") else "sin_espacio"


def genealogia(feats):
    """Escala, espacio, líneas temporales (arriba: a qué se liga; abajo: qué ha generado) y vínculos de cada obra."""
    props = {f["properties"]["id"].lower(): f["properties"] for f in feats if f.get("properties", {}).get("id")}
    esp = {f["properties"]["id"].lower(): espacio_de(f) for f in feats if f.get("properties", {}).get("id")}
    gen = {u: {"nombre": p.get("name") or u, "escala": p.get("escala") or ESCALA_DEF, "espacio": esp[u], "tiempo": {"inicio": p.get("fecha"), "fin": p.get("fecha_fin")},
               "arriba": [], "abajo": [], "vinculos": []} for u, p in props.items()}
    for u, p in props.items():
        for r in relaciones(p):
            if r["a"] not in props or r["a"] == u:
                continue
            if r["tipo"] == "muestra_de":
                gen[u]["arriba"].append(r["a"])
                gen[r["a"]]["abajo"].append(u)
            else:
                gen[u]["vinculos"].append({"tipo": r["tipo"], "a": r["a"], "sentido": "sale", **({"nota": r["nota"]} if r.get("nota") else {})})
                gen[r["a"]]["vinculos"].append({"tipo": r["tipo"], "a": u, "sentido": "entra", **({"nota": r["nota"]} if r.get("nota") else {})})
    for g in gen.values():
        g["abajo"].sort(key=lambda h: props[h].get("fecha") or "")
    return gen, props


def ident_agente(texto):
    t = unicodedata.normalize("NFKD", str(texto)).encode("ascii", "ignore").decode().lower()
    return re.sub(r"[^a-z0-9]+", "-", t).strip("-")[:60] or "anonimo"


def agente_publico(a, clase):
    """Las personas no son identificables en la red pública salvo que quieran publicarlo o sean personajes públicos.
    La autoría acreditada de una obra pública es pública salvo "publica": false; una colaboración solo con "publica": true."""
    return a.get("publica") is not False if clase == "autoria" else a.get("publica") is True


def glifo(uuid, publicos, privados, espectadores=0):
    """Mundo de grafos de la ficha: la obra en el centro, sus vínculos públicos en trazo continuo y los privados
    (personas, familias, amistades, trayectorias no publicadas) en discontinuo y a poco contraste, anónimos:
    se ve que existen, no quiénes son ni adónde llevan. Alrededor, un polvo de puntos: las huellas de quienes la
    han mirado, escaneado, verificado u observado. Los espectadores también son trazo de la obra."""
    W, C = 240, 120
    semilla = int(hashlib.sha256(uuid.encode()).hexdigest(), 16)
    partes = [f'<svg class="glifo" viewBox="0 0 {W} {W}" width="{W}" height="{W}" role="img" '
              f'aria-label="{len(publicos)} vínculos públicos, {privados} privados y {espectadores} huellas de espectadores">']
    if espectadores:
        puntos = min(120, round(7 * math.log2(1 + espectadores)))
        for k in range(puntos):
            hk = int(hashlib.sha256(f"{uuid}:{k}".encode()).hexdigest()[:12], 16)
            ang = (hk % 6283) / 1000
            r = 100 + (hk >> 13) % 18
            partes.append(f'<circle cx="{C + r * math.cos(ang):.1f}" cy="{C + r * math.sin(ang):.1f}" r="1.3" '
                          f'fill="currentColor" fill-opacity=".22"/>')
    privados = min(privados, 36)
    for k in range(privados):
        ang = 2 * math.pi * (k / max(privados, 1)) + (semilla % 628) / 100
        r = 78 + ((semilla >> (k % 60)) % 32)
        x, y = C + r * math.cos(ang), C + r * math.sin(ang)
        partes.append(f'<line x1="{C}" y1="{C}" x2="{x:.1f}" y2="{y:.1f}" stroke="currentColor" stroke-opacity=".28" stroke-dasharray="3 4"/>'
                      f'<circle cx="{x:.1f}" cy="{y:.1f}" r="2.5" fill="currentColor" fill-opacity=".28"/>')
    n = len(publicos)
    for k, (destino, _) in enumerate(publicos):
        ang = 2 * math.pi * k / max(n, 1) - math.pi / 2
        x, y = C + 58 * math.cos(ang), C + 58 * math.sin(ang)
        partes.append(f'<a href="/obra/{destino}/"><line x1="{C}" y1="{C}" x2="{x:.1f}" y2="{y:.1f}" stroke="var(--verde)" stroke-width="1.6"/>'
                      f'<circle cx="{x:.1f}" cy="{y:.1f}" r="6" fill="var(--verde)"/></a>')
    partes.append(f'<circle cx="{C}" cy="{C}" r="11" fill="var(--fg)"/></svg>')
    return "".join(partes)


API = os.environ.get("CA_API", "https://api.canarias.africa").rstrip("/")


def vinculos_api(uuid):
    """Vínculos de personas que guarda api.canarias.africa: el número de privados y los que todas las partes han publicado."""
    import urllib.request
    try:
        with urllib.request.urlopen(f"{API}/api/publico/obras/{uuid}/vinculos", timeout=10) as r:
            d = json.loads(r.read())
            return int(d.get("privados") or 0), [x for x in d.get("publicos") or [] if isinstance(x, dict)]
    except Exception:
        return 0, []  # sin servidor (pruebas, vista previa): solo cuenta lo del catálogo


def huellas_api(uuid):
    """Huellas anónimas de espectadores (visitas, escaneos, verificaciones, observaciones) en api.canarias.africa."""
    import urllib.request
    try:
        with urllib.request.urlopen(f"{API}/api/publico/obras/{uuid}/huellas", timeout=10) as r:
            d = json.loads(r.read())
            return {k: int(d.get(k) or 0) for k in ("visitas", "escaneos", "verificaciones", "observaciones")}
    except Exception:
        return {}


def trayectorias(props, extra=None):
    """Línea temporal de cada agente (artistas, colectivos y colaboradores): sus obras, en orden de fecha.

    "autoria": ["Nombre", …] o [{"id", "nombre", "rol"}, …]. "colaboraciones": lo mismo para quienes registran,
    mapean, fotografían, documentan o cuidan la obra (rol: registro, mapeo, foto, documentación, cuidado…):
    también forman parte de la obra y tienen su trayectoria. Se identifican por su alias público.
    La trayectoria une espacios distintos a través de una biografía; cada ficha enlaza con la obra
    anterior y la siguiente de cada agente."""
    ags = {}
    for u, p in props.items():
        for clase, lista_ag in (("autoria", p.get("autoria")), ("colaboracion", p.get("colaboraciones"))):
            for a in lista_ag or []:
                a = a if isinstance(a, dict) else {"nombre": a}
                if not a.get("nombre") and not a.get("id"):
                    continue
                if not agente_publico(a, clase):
                    continue  # no debería estar en el catálogo (lo vigila comprobar-catalogo.py)
                i = ident_agente(a.get("id") or a["nombre"])
                ag = ags.setdefault(i, {"nombre": a.get("nombre") or i, "obras": []})
                ag["obras"].append({"obra": u, "clase": clase, **({"rol": a["rol"]} if a.get("rol") else {})})
    # Colaboraciones publicadas desde la web por sus protagonistas (consentimiento expreso en api.canarias.africa).
    for u, publicos in (extra or {}).items():
        for v in publicos:
            if v.get("obra") and v.get("de") and u in props:
                i = ident_agente(v["de"])
                ag = ags.setdefault(i, {"nombre": v["de"], "obras": []})
                if not any(x["obra"] == u for x in ag["obras"]):
                    ag["obras"].append({"obra": u, "clase": "colaboracion", **({"rol": v["rol"]} if v.get("rol") else {})})
    for ag in ags.values():
        ag["obras"].sort(key=lambda x: (str(props[x["obra"]].get("fecha") or "9999"), props[x["obra"]].get("name") or ""))
    return ags


def valor(v):
    if v is None or v == "":
        return "—"
    t = v if isinstance(v, str) else json.dumps(v, ensure_ascii=False)
    return t if len(t) <= 140 else t[:139] + "…"


def arbol(versiones, capturas, uuid):
    """Árbol de modificaciones: cada versión con su commit, la ficha tal como era y su estrato en archive.org."""
    if not versiones:
        return '<p class="muted">Sin historial todavía: la primera versión aparecerá al publicarse la obra.</p>'
    caps = sorted((c for c in capturas if c.get("obra") == uuid and c.get("wayback") and "/obra/" in c.get("url", "")),
                  key=lambda c: c["fecha"])
    filas = []
    for i, v in enumerate(versiones):
        hasta = versiones[i + 1]["fecha"] if i + 1 < len(versiones) else "9999"
        estrato = next((c for c in caps if v["fecha"] <= c["fecha"] < hasta), None)
        cambios = []
        for c in v["cambios"]:
            if c["campo"] == "alta":
                cambios.append("<li>Alta de la obra en el catálogo</li>")
            elif c["campo"] == "geometry":
                cambios.append("<li>Ubicación modificada</li>")
            else:
                n = NOMBRES_CAMPO.get(c["campo"], c["campo"])
                cambios.append(f'<li>{e(n)}: <del>{e(valor(c.get("antes")))}</del> → <ins>{e(valor(c.get("ahora")))}</ins></li>')
        enlaces = [f'<a href="https://github.com/{REPO}/commit/{v["commit"]}">cambio {v["commit"][:7]}</a>',
                   f'<a href="https://github.com/{REPO}/blob/{v["commit"]}/{CATALOGO.as_posix()}">datos de esta versión</a>']
        if estrato:
            enlaces.append(f'<a href="{e(estrato["wayback"])}">ficha archivada</a>')
        filas.append(f'<li id="v{v["version"]}"><b>v{v["version"]}</b> <span class="muted">· {e(v["fecha"][:10])}</span>'
                     f'<ul class="cambios">{"".join(cambios)}</ul><span class="enlaces">{" · ".join(enlaces)}</span></li>')
    return '<ol class="estratos arbol" reversed>' + "".join(reversed(filas)) + "</ol>"


def lista(capturas, con_obra=None):
    if not capturas:
        return '<p class="muted">Todavía no hay estratos: la primera captura llegará tras la próxima publicación.</p>'
    filas = []
    for c in sorted(capturas, key=lambda c: c["fecha"], reverse=True):
        dia = c["fecha"][:16].replace("T", " ")
        que = MOTIVOS.get(c.get("motivo"), c.get("motivo", ""))
        obra = ""
        if con_obra and c.get("obra") in con_obra:
            obra = f' · <a href="/obra/{e(c["obra"])}/">{e(con_obra[c["obra"]])}</a>'
        if c.get("wayback"):
            enlace = f'<a class="mono" href="{e(c["wayback"])}">{e(c["url"])}</a>'
            if c.get("pantalla"):
                enlace += f' · <a href="{e(c["pantalla"])}">captura de pantalla</a>'
        else:
            enlace = f'<span class="mono">{e(c["url"])}</span> <span class="muted">(no se pudo archivar)</span>'
        filas.append(f'<li><span class="muted">{e(dia)} UTC · {e(que)}</span>{obra}<br>{enlace}</li>')
    return '<ol class="estratos">' + "".join(filas) + "</ol>"


def main():
    reg = json.loads(REGISTRO.read_text(encoding="utf-8")) if REGISTRO.exists() else {}
    capturas = [c for c in reg.get("capturas", []) if not c.get("error")]
    obras_reg = reg.get("obras", {})
    hist = historial()
    feats = json.loads(CATALOGO.read_text(encoding="utf-8")).get("features", []) if CATALOGO.exists() else []
    nombres = {}
    gen, props = genealogia(feats)
    api = {u: vinculos_api(u) for u in props if not props[u].get("ficticio")}
    huellas = {u: huellas_api(u) for u in api}
    ags = trayectorias(props, {u: v[1] for u, v in api.items()})
    glifos = {}

    def nodo(u):
        q = props.get(u, {})
        extra = " · ".join(x for x in (ESCALAS.get(q.get("escala") or ESCALA_DEF, q.get("escala")), q.get("municipio") or ESPACIOS[gen[u]["espacio"]],
                                       str(q.get("fecha") or "")[:10]) if x)
        return f'<a href="/obra/{e(u)}/">{e(q.get("name") or u)}</a> <span class="muted">({e(extra)})</span>'

    def rama(u, clave, vistos):
        """Árbol de líneas temporales hacia arriba o hacia abajo, sin repetir nodos (es un grafo, no siempre un árbol)."""
        hijos = [h for h in gen[u][clave] if h not in vistos]
        if not hijos:
            return ""
        vistos |= set(hijos)
        return "<ul>" + "".join(f"<li>{nodo(h)}{rama(h, clave, vistos)}</li>" for h in hijos) + "</ul>"

    for f in feats:
        p = f.get("properties", {})
        if not p.get("id"):
            continue
        uuid = p["id"].lower()
        nombres[uuid] = p.get("name") or uuid
        info = obras_reg.get(uuid, {})
        filas = [("Municipio", p.get("municipio")), ("Fase", p.get("fase_nombre") or p.get("fase")),
                 ("Ubicación", {"abierta": "Pública", "difusa": "Zona aproximada (~2 km)", "reservada": "Reservada"}.get(p.get("sensibilidad")))]
        g = gen[uuid]
        tiempo = " – ".join(str(x)[:10] for x in (g["tiempo"]["inicio"], g["tiempo"]["fin"]) if x) or None
        filas = [("Escala", ESCALAS.get(g["escala"], g["escala"])), ("Espacio", ESPACIOS[g["espacio"]]), ("Tiempo", tiempo)] + filas
        html_ok = {"Obra web"}
        if p.get("url"):
            filas.append(("Obra web", f'<a href="{e(p["url"])}">{e(p["url"])}</a>'))
        dl = "".join(f"<dt>{k}</dt><dd>{v if k in html_ok else e(v)}</dd>" for k, v in filas if v)
        lineas = ""
        if g["arriba"] or g["abajo"]:
            lineas = '<h2 id="lineas">Líneas temporales</h2>'
            if g["arriba"]:
                lineas += f'<p class="muted">Su línea temporal está ligada a:</p><div class="tree">{rama(uuid, "arriba", {uuid})}</div>'
            if g["abajo"]:
                lineas += f'<p class="muted">Ha dado lugar a {len(g["abajo"])} muestra(s), cada una con su línea temporal:</p><div class="tree">{rama(uuid, "abajo", {uuid})}</div>'
        vinc = ""
        if g["vinculos"]:
            vinc = '<h2 id="vinculos">Vínculos</h2><ul>' + "".join(
                f'<li>{e(nombre_vinculo(v["tipo"], v["sentido"] == "entra"))}: {nodo(v["a"])}'
                + (f' <span class="muted">— {e(v["nota"])}</span>' if v.get("nota") else "") + "</li>" for v in g["vinculos"]) + "</ul>"
        tray = ""
        for i, ag in ags.items():
            pos = next((k for k, x in enumerate(ag["obras"]) if x["obra"] == uuid), None)
            if pos is None:
                continue
            ant = nodo(ag["obras"][pos - 1]["obra"]) if pos > 0 else '<span class="muted">primera obra registrada</span>'
            sig = nodo(ag["obras"][pos + 1]["obra"]) if pos + 1 < len(ag["obras"]) else '<span class="muted">última obra registrada</span>'
            tray += (f'<li><a href="/agente/{e(i)}/">{e(ag["nombre"])}</a> <span class="muted">· {e(ag["obras"][pos].get("rol") or ("autoría" if ag["obras"][pos]["clase"] == "autoria" else "colaboración"))} · obra {pos + 1} de {len(ag["obras"])}</span>'
                     f'<br><span class="muted">anterior:</span> {ant}<br><span class="muted">siguiente:</span> {sig}</li>')
        vecinos = {}
        for x in g["arriba"] + g["abajo"]:
            vecinos.setdefault(x, "linea")
        for v in g["vinculos"]:
            vecinos.setdefault(v["a"], v["tipo"])
        for ag in ags.values():
            obras_ag = [x["obra"] for x in ag["obras"]]
            if uuid in obras_ag:
                k = obras_ag.index(uuid)
                for x in obras_ag[max(k - 1, 0):k] + obras_ag[k + 1:k + 2]:
                    vecinos.setdefault(x, "trayectoria")
        vecinos.pop(uuid, None)
        privados = int(p.get("vinculos_privados") or 0) + api.get(uuid, (0, []))[0]
        hu = huellas.get(uuid, {})
        espectadores = sum(hu.values())
        glifos[uuid] = glifo(uuid, list(vecinos.items()), privados, espectadores)
        mundo = (f'<figure class="mundo">{glifos[uuid]}<figcaption class="muted">Mundo de vínculos de esta obra. '
                 f'En trazo continuo, {len(vecinos)} vínculos públicos; en discontinuo, {privados} vínculos entre personas que no son públicos: '
                 'solo los ven quienes forman parte de ellos.'
                 + (f' Alrededor, {espectadores} huellas anónimas de quienes la han mirado ({hu.get("visitas", 0)}), escaneado ({hu.get("escaneos", 0)}), '
                    f'verificado ({hu.get("verificaciones", 0)}) u observado ({hu.get("observaciones", 0)}): también son trazo de la obra.' if espectadores else "")
                 + '</figcaption></figure>') if vecinos or privados or espectadores else ""
        if tray:
            tray = f'<h2 id="trayectoria">Trayectorias</h2><p class="muted">Quienes la crearon y quienes colaboran en ella también forman parte de la obra: la línea temporal de cada una une esta obra con otros lugares y tiempos.</p><ul>{tray}</ul>'
        if p.get("archivar") is False:
            rastro = '<p class="muted">Por decisión de su autoría, esta obra no se inscribe en archive.org.</p>'
        elif p.get("escala") in INTIMAS and p.get("archivar") is not True:
            rastro = '<p class="muted">Escala íntima: no se inscribe en archive.org sin el consentimiento expreso de quienes aparecen.</p>'
        elif p.get("ficticio"):
            rastro = '<p class="muted">Dato de ejemplo: no se inscribe en archive.org.</p>'
        else:
            item = f'<p>Capas de la ficha en Internet Archive: <a href="{e(info["item"])}">{e(info["item"])}</a> ({len(info.get("capas", []))})</p>' if info.get("item") else ""
            rastro = item + lista([c for c in capturas if c.get("obra") == uuid])
        versiones = hist.get(uuid, [])
        ultima = versiones[-1] if versiones else None
        idlinea = f'<span class="mono" title="Identificador permanente">{e(uuid)}</span>'
        if ultima:
            idlinea += f'<span>versión {ultima["version"]}</span><span class="muted">{e(ultima["fecha"][:10])}</span><a href="#arbol">árbol de modificaciones</a>'
        cuerpo = f"""<p class="eyebrow"><a href="/">CANARIAS</a> · Ficha de obra</p>
<h1>{e(p.get('name') or uuid)}</h1>
<p class="idficha">{idlinea}</p>
<p>{e(p.get('descripcion') or p.get('description'))}</p>
<dl>{dl}</dl>
<p><a href="/#obra-{e(uuid)}">Ficha completa, observaciones y mapa</a></p>
{mundo}
{lineas}
{tray}
{vinc}
<h2 id="arbol">Árbol de modificaciones</h2>
{arbol(versiones, capturas, uuid)}
<h2>Estratos en archive.org</h2>
{rastro}"""
        destino = WEB / "obra" / uuid / "index.html"
        destino.parent.mkdir(parents=True, exist_ok=True)
        destino.write_text(pagina(f"{p.get('name') or uuid} · CANARIAS", cuerpo), encoding="utf-8")

    cuerpo = f"""<p class="eyebrow"><a href="/">CANARIAS</a> · Arqueología de la red</p>
<h1>Estratos</h1>
<p>Cada cambio de canarias.africa y cada obra que se registra se inscriben en <a href="https://web.archive.org/web/*/canarias.africa*">archive.org</a> a medida que suceden. No es una copia de seguridad: es el yacimiento. Las capas se depositan solas y quedan fuera de nuestro alcance, para que cualquiera pueda excavar cómo era la red en cada momento.</p>
<p class="muted">{len(capturas)} capturas · última actualización {e((reg.get('actualizado') or '—')[:16].replace('T', ' '))} UTC · <a href="/datos/archivo.json">datos</a></p>
{lista(capturas, nombres)}"""
    (WEB / "estratos").mkdir(parents=True, exist_ok=True)
    (WEB / "estratos" / "index.html").write_text(pagina("Estratos · CANARIAS", cuerpo), encoding="utf-8")

    for i, ag in ags.items():
        filas = "".join(f'<li>{nodo(x["obra"])}{" · " + e(x.get("rol") or ("autoría" if x["clase"] == "autoria" else "colaboración"))}</li>' for x in ag["obras"])
        espacios = sorted({props[x["obra"]].get("municipio") or ESPACIOS[gen[x["obra"]]["espacio"]] for x in ag["obras"]})
        cuerpo = f"""<p class="eyebrow"><a href="/">CANARIAS</a> · Trayectoria</p>
<h1>{e(ag['nombre'])}</h1>
<p class="muted">{len(ag['obras'])} obras · {e(', '.join(espacios))}</p>
<p>Su línea temporal vincula los espacios donde ha creado, registrado, mapeado o cuidado: cada obra queda unida a la anterior y a la siguiente.</p>
<ol class="estratos">{filas}</ol>"""
        destino = WEB / "agente" / i / "index.html"
        destino.parent.mkdir(parents=True, exist_ok=True)
        destino.write_text(pagina(f"{ag['nombre']} · CANARIAS", cuerpo), encoding="utf-8")

    publico = {"actualizado": reg.get("actualizado"), "capturas": capturas,
               "obras": {u: {k: v for k, v in o.items() if k in ("item", "capas")} for u, o in obras_reg.items()}}
    (WEB / "datos").mkdir(parents=True, exist_ok=True)
    (WEB / "datos" / "archivo.json").write_text(json.dumps(publico, ensure_ascii=False) + "\n", encoding="utf-8")
    resumen = {u: {**({"version": hist[u][-1]["version"], "fecha": hist[u][-1]["fecha"], "versiones": hist[u]} if u in hist else {}),
                   **gen[u], "glifo": glifos.get(u), "vinculos_privados": int(props[u].get("vinculos_privados") or 0) + api.get(u, (0, []))[0], "huellas": huellas.get(u) or {}} for u in nombres}
    (WEB / "datos" / "historial.json").write_text(json.dumps(resumen, ensure_ascii=False) + "\n", encoding="utf-8")
    (WEB / "datos" / "trayectorias.json").write_text(json.dumps(ags, ensure_ascii=False) + "\n", encoding="utf-8")
    print(f"{len(ags)} trayectorias.")
    print(f"{len(nombres)} fichas estáticas, {len(capturas)} estratos.")


if __name__ == "__main__":
    main()
