#!/usr/bin/env python3
"""Copia la capa «Obras» de uMap al catálogo público web/datos/obras.geojson.

  python3 scripts/umap-obras.py URL-DE-LA-CAPA

La capa de uMap es pública, así que solo puede contener obras de sensibilidad «abierta»:
las difusas y reservadas no se ponen en uMap. Por si acaso, este script descarta cualquier
elemento marcado como difuso o reservado, los que no tienen «id» y los de ejemplo
(«ficticio»). Si uMap no responde, deja el catálogo como estaba.
Solo usa la biblioteca estándar.
"""
import json, re, sys, urllib.request
from pathlib import Path

SALIDA = Path(__file__).resolve().parent.parent / "web" / "datos" / "obras.geojson"
UUID = re.compile(r"^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$", re.I)
CAMPOS = ["fase", "municipio", "descripcion", "tipo", "ficha"]

try:
    req = urllib.request.Request(sys.argv[1], headers={"User-Agent": "canarias.africa catalogo"})
    capa = json.load(urllib.request.urlopen(req, timeout=30))
    assert capa.get("type") == "FeatureCollection"
except Exception as e:  # noqa: BLE001
    print(f"::warning::No se pudo leer la capa Obras de uMap ({e}); el catálogo se queda como estaba.")
    sys.exit(0)

obras, avisos = [], []
for f in capa.get("features", []):
    p, g = f.get("properties") or {}, f.get("geometry")
    ident = str(p.get("id", "")).strip().lower()
    nombre = p.get("name") or "¿sin nombre?"
    if p.get("ficticio"):
        continue
    if not UUID.match(ident):
        avisos.append(f"«{nombre}»: falta un id válido (UUID), no se publica")
        continue
    if p.get("sensibilidad", "abierta") != "abierta":
        avisos.append(f"«{nombre}»: es {p['sensibilidad']} y no debería estar en uMap; no se publica")
        continue
    if not g or g.get("type") != "Point":
        avisos.append(f"«{nombre}»: no es un punto, no se publica")
        continue
    props = {"id": ident, "name": nombre, "sensibilidad": "abierta", "precision": "exacta"}
    if p.get("description") and not p.get("descripcion"):
        p["descripcion"] = p["description"]
    props.update({k: p[k] for k in CAMPOS if p.get(k)})
    obras.append({"type": "Feature", "geometry": {"type": "Point", "coordinates": g["coordinates"][:2]}, "properties": props})

for a in avisos:
    print(f"::warning::{a}")
SALIDA.parent.mkdir(parents=True, exist_ok=True)
SALIDA.write_text(json.dumps({"type": "FeatureCollection", "features": obras}, ensure_ascii=False, indent=1) + "\n", encoding="utf-8")
print(f"{SALIDA}: {len(obras)} obras desde uMap")
