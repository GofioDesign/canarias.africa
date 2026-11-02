"""Copia el catálogo público de api.canarias.africa a web/datos/ al publicar la web en GitHub Pages.

La fuente de los datos es el servidor (se editan en api.canarias.africa/admin). GitHub Pages sirve una copia
en canarias.africa/datos/*.geojson, que es lo que leen la web y uMap: así el servidor solo recibe una petición
por capa en cada publicación, no una por visita. La copia no se guarda en el repositorio: va directa al artefacto.
Uso: python3 scripts/catalogo-servidor.py [URL-BASE]   (por defecto https://api.canarias.africa)
"""
import json
import sys
import urllib.error
import urllib.request
from pathlib import Path

BASE = (sys.argv[1] if len(sys.argv) > 1 else "https://api.canarias.africa").rstrip("/")
CAPAS = ["obras", "soportes", "rutas", "artistas", "funciones", "eventos", "referencias"]
# Capas que un servidor sin actualizar aún no tiene: si dan 404, no es un fallo.
NUEVAS = {"funciones", "eventos", "referencias"}
# Los soportes (lo que identifica cada etiqueta QR) se llamaban «qr»: se copia también con ese nombre para las capas
# de uMap que aún lo usen, y se pide con el nombre antiguo si el servidor no se ha actualizado.
ALIAS = {"soportes": "qr"}
destino = Path("web/datos")
destino.mkdir(parents=True, exist_ok=True)

fallos = 0
for capa in CAPAS:
    url = f"{BASE}/datos/{capa}.geojson"
    try:
        datos = None
        for nombre in [capa] + ([ALIAS[capa]] if capa in ALIAS else []):
            url = f"{BASE}/datos/{nombre}.geojson"
            req = urllib.request.Request(url, headers={"User-Agent": "canarias.africa publicar-web (GitHub Actions)"})
            try:
                with urllib.request.urlopen(req, timeout=60) as r:
                    datos = json.load(r)
                break
            except urllib.error.HTTPError as e:
                if e.code != 404 or nombre == ALIAS.get(capa, capa):
                    raise
        if datos.get("type") != "FeatureCollection" or not isinstance(datos.get("features"), list):
            raise ValueError("no es un FeatureCollection")
    except urllib.error.HTTPError as e:
        if e.code == 404 and capa in NUEVAS:
            print(f"{capa}: el servidor aún no tiene esta capa")
            continue
        print(f"::warning::{capa}: no se pudo copiar {url} ({e})")
        fallos += 1
        continue
    except Exception as e:  # noqa: BLE001
        # Sin copia, la web lee esa capa directamente del servidor (respaldo en el HTML).
        print(f"::warning::{capa}: no se pudo copiar {url} ({e})")
        fallos += 1
        continue
    texto = json.dumps(datos, ensure_ascii=False, separators=(",", ":"))
    (destino / f"{capa}.geojson").write_text(texto, encoding="utf-8")
    if capa in ALIAS:
        (destino / f"{ALIAS[capa]}.geojson").write_text(texto, encoding="utf-8")
    print(f"{capa}: {len(datos['features'])} elementos")

if fallos == len(CAPAS):
    print("::warning::No se ha copiado ninguna capa: la web leerá del servidor.")
