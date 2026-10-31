"""Genera, al publicar, las páginas estáticas que hacen visible el rastro en archive.org.

- web/obra/<uuid>/index.html: ficha estática de cada obra (la que captura la Wayback Machine), con sus
  estratos en archive.org y su item de Internet Archive. Es HTML sin JavaScript, para que la captura
  se lea igual dentro de veinte años; enlaza a la ficha completa de la web (/#obra-<uuid>).
- web/estratos/index.html: todas las capturas, de la más reciente a la más antigua.
- web/datos/archivo.json: el mismo rastro para la web (sección «Estratos en archive.org» de cada ficha).

Lee web/datos/obras.geojson (ya sin coordenadas sensibles) y datos/archivo/registro.json.
Las páginas se generan en cada publicación y no se suben al repositorio.
Uso: python3 scripts/generar-fichas.py
"""
import html
import json
from pathlib import Path

CATALOGO = Path("web/datos/obras.geojson")
REGISTRO = Path("datos/archivo/registro.json")
WEB = Path("web")

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
ol.estratos li{padding:4px 0 10px 14px;position:relative}
ol.estratos li::before{content:"";position:absolute;left:-6px;top:11px;width:10px;height:10px;border-radius:50%;background:var(--verde)}
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
    feats = json.loads(CATALOGO.read_text(encoding="utf-8")).get("features", []) if CATALOGO.exists() else []
    nombres = {}

    for f in feats:
        p = f.get("properties", {})
        if not p.get("id"):
            continue
        uuid = p["id"].lower()
        nombres[uuid] = p.get("name") or uuid
        info = obras_reg.get(uuid, {})
        filas = [("Municipio", p.get("municipio")), ("Fase", p.get("fase_nombre") or p.get("fase")),
                 ("Ubicación", {"abierta": "Pública", "difusa": "Zona aproximada (~2 km)", "reservada": "Reservada"}.get(p.get("sensibilidad")))]
        if p.get("url"):
            filas.append(("Obra web", f'<a href="{e(p["url"])}">{e(p["url"])}</a>'))
        dl = "".join(f"<dt>{k}</dt><dd>{v if k == 'Obra web' else e(v)}</dd>" for k, v in filas if v)
        if p.get("archivar") is False:
            rastro = '<p class="muted">Por decisión de su autoría, esta obra no se inscribe en archive.org.</p>'
        elif p.get("ficticio"):
            rastro = '<p class="muted">Dato de ejemplo: no se inscribe en archive.org.</p>'
        else:
            item = f'<p>Capas de la ficha en Internet Archive: <a href="{e(info["item"])}">{e(info["item"])}</a> ({len(info.get("capas", []))})</p>' if info.get("item") else ""
            rastro = item + lista([c for c in capturas if c.get("obra") == uuid])
        cuerpo = f"""<p class="eyebrow"><a href="/">CANARIAS</a> · Ficha {e(uuid)}</p>
<h1>{e(p.get('name') or uuid)}</h1>
<p>{e(p.get('descripcion') or p.get('description'))}</p>
<dl>{dl}</dl>
<p><a href="/#obra-{e(uuid)}">Ficha completa, observaciones y mapa</a></p>
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
    print(f"{len(nombres)} fichas estáticas, {len(capturas)} estratos.")


if __name__ == "__main__":
    main()
