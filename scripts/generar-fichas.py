"""Genera, al publicar, las páginas estáticas que hacen visible el rastro en archive.org.

- web/obra/<uuid>/index.html: ficha estática de cada obra (la que captura la Wayback Machine), con sus
  estratos en archive.org y su item de Internet Archive. Es HTML sin JavaScript, para que la captura
  se lea igual dentro de veinte años; enlaza a la ficha completa de la web (/#obra-<uuid>).
- web/estratos/index.html: todas las capturas, de la más reciente a la más antigua.
- web/datos/archivo.json: el mismo rastro para la web (sección «Estratos en archive.org» de cada ficha).
- web/datos/historial.json: identificador, versión, fecha y árbol de modificaciones de cada obra,
  sacados del historial de git de web/datos/obras.geojson (cada commit que cambia la obra es una versión),
  y su escala y genealogía: un evento global puede dar lugar a muestras locales (propiedad
  "muestra_de": <uuid del evento>), y cada ficha enlaza hacia arriba y hacia abajo.

Escalas ("escala"): local (sucede en un lugar de Canarias), glocal (local, en diálogo con algo global)
y global (evento o convocatoria que sucede en muchos sitios a la vez o en la red).

Lee web/datos/obras.geojson (ya sin coordenadas sensibles) y datos/archivo/registro.json.
Las páginas se generan en cada publicación y no se suben al repositorio.
Uso: python3 scripts/generar-fichas.py
"""
import html
import json
import os
import subprocess
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


ESCALAS = {"local": "Local", "glocal": "Glocal", "global": "Global"}


def genealogia(feats):
    """{uuid: {"escala", "muestra_de": [ascendencia, de la más cercana a la raíz], "muestras": [hijos]}}."""
    props = {f["properties"]["id"].lower(): f["properties"] for f in feats if f.get("properties", {}).get("id")}
    padre = {u: str(p["muestra_de"]).lower() for u, p in props.items() if p.get("muestra_de") and str(p["muestra_de"]).lower() in props}
    gen = {}
    for u, p in props.items():
        linaje, x = [], u
        while x in padre and padre[x] not in linaje and padre[x] != u:  # sin ciclos
            x = padre[x]
            linaje.append(x)
        gen[u] = {"escala": p.get("escala") or "local", "muestra_de": linaje,
                  "muestras": sorted((h for h, pa in padre.items() if pa == u), key=lambda h: props[h].get("fecha") or "")}
    return gen, props


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

    def nodo(u):
        q = props.get(u, {})
        extra = " · ".join(x for x in (ESCALAS.get(q.get("escala") or "local"), q.get("municipio"), (q.get("fecha") or "")[:10]) if x)
        return f'<a href="/obra/{e(u)}/">{e(q.get("name") or u)}</a> <span class="muted">({e(extra)})</span>'

    for f in feats:
        p = f.get("properties", {})
        if not p.get("id"):
            continue
        uuid = p["id"].lower()
        nombres[uuid] = p.get("name") or uuid
        info = obras_reg.get(uuid, {})
        filas = [("Municipio", p.get("municipio")), ("Fase", p.get("fase_nombre") or p.get("fase")),
                 ("Ubicación", {"abierta": "Pública", "difusa": "Zona aproximada (~2 km)", "reservada": "Reservada"}.get(p.get("sensibilidad")))]
        filas.insert(0, ("Escala", ESCALAS.get(gen[uuid]["escala"], gen[uuid]["escala"])))
        html_ok = {"Obra web", "Muestra de"}
        if p.get("url"):
            filas.append(("Obra web", f'<a href="{e(p["url"])}">{e(p["url"])}</a>'))
        if gen[uuid]["muestra_de"]:
            filas.append(("Muestra de", " ← ".join(nodo(x) for x in gen[uuid]["muestra_de"])))
        dl = "".join(f"<dt>{k}</dt><dd>{v if k in html_ok else e(v)}</dd>" for k, v in filas if v)
        hijos = gen[uuid]["muestras"]
        muestras = ""
        if hijos or gen[uuid]["escala"] == "global":
            muestras = (f'<h2 id="muestras">Muestras locales ({len(hijos)})</h2>'
                        '<p class="muted">Lo que este evento ha generado en cada lugar, con su propia línea temporal.</p>'
                        + ('<ol class="estratos">' + "".join(f"<li>{nodo(h)}</li>" for h in hijos) + "</ol>" if hijos
                           else '<p class="muted">Todavía ninguna.</p>'))
        if p.get("archivar") is False:
            rastro = '<p class="muted">Por decisión de su autoría, esta obra no se inscribe en archive.org.</p>'
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
{muestras}
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

    publico = {"actualizado": reg.get("actualizado"), "capturas": capturas,
               "obras": {u: {k: v for k, v in o.items() if k in ("item", "capas")} for u, o in obras_reg.items()}}
    (WEB / "datos").mkdir(parents=True, exist_ok=True)
    (WEB / "datos" / "archivo.json").write_text(json.dumps(publico, ensure_ascii=False) + "\n", encoding="utf-8")
    resumen = {u: {**({"version": hist[u][-1]["version"], "fecha": hist[u][-1]["fecha"], "versiones": hist[u]} if u in hist else {}),
                   **gen[u]} for u in nombres}
    (WEB / "datos" / "historial.json").write_text(json.dumps(resumen, ensure_ascii=False) + "\n", encoding="utf-8")
    print(f"{len(nombres)} fichas estáticas, {len(capturas)} estratos.")


if __name__ == "__main__":
    main()
