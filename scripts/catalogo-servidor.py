"""Copia el catálogo público de api.canarias.africa a web/datos/ al publicar la web en GitHub Pages.

La fuente de los datos es el servidor (se editan en api.canarias.africa/admin). GitHub Pages sirve una copia
en canarias.africa/datos/*.geojson, que es lo que leen la web y uMap: así el servidor solo recibe una petición
por capa en cada publicación, no una por visita. La copia no se guarda en el repositorio: va directa al artefacto.
Uso: python3 scripts/catalogo-servidor.py [URL-BASE]   (por defecto https://api.canarias.africa)
"""
import json
import sys
import urllib.request
from pathlib import Path

BASE = (sys.argv[1] if len(sys.argv) > 1 else "https://api.canarias.africa").rstrip("/")
CAPAS = ["obras", "qr", "rutas", "artistas"]
destino = Path("web/datos")
destino.mkdir(parents=True, exist_ok=True)

fallos = 0
for capa in CAPAS:
    url = f"{BASE}/datos/{capa}.geojson"
    try:
        req = urllib.request.Request(url, headers={"User-Agent": "canarias.africa publicar-web (GitHub Actions)"})
        with urllib.request.urlopen(req, timeout=60) as r:
            datos = json.load(r)
        if datos.get("type") != "FeatureCollection" or not isinstance(datos.get("features"), list):
            raise ValueError("no es un FeatureCollection")
    except Exception as e:  # noqa: BLE001
        # Sin copia, la web lee esa capa directamente del servidor (respaldo en el HTML).
        print(f"::warning::{capa}: no se pudo copiar {url} ({e})")
        fallos += 1
        continue
    (destino / f"{capa}.geojson").write_text(json.dumps(datos, ensure_ascii=False, separators=(",", ":")), encoding="utf-8")
    print(f"{capa}: {len(datos['features'])} elementos")

if fallos == len(CAPAS):
    print("::warning::No se ha copiado ninguna capa: la web leerá del servidor.")
