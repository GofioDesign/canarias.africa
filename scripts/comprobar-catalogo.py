"""Comprueba que el catálogo público no publica coordenadas que deberían estar ocultas.

El repositorio es público: las obras «difusa» solo pueden llevar el centro de su celda de 0,02°
y las «reservada» no pueden llevar coordenadas. Las coordenadas exactas de esas obras no se suben nunca.
Uso: python3 scripts/comprobar-catalogo.py [web/datos/obras.geojson]
"""
import json
import re
import sys
from pathlib import Path

PASO = 0.02
ruta = Path(sys.argv[1] if len(sys.argv) > 1 else "web/datos/obras.geojson")
if not ruta.exists():
    print(f"{ruta} no existe todavía: nada que comprobar.")
    sys.exit(0)

errores = []
features = json.loads(ruta.read_text(encoding="utf-8")).get("features", [])
ids = {str(f.get("properties", {}).get("id", "")).lower() for f in features}
ESCALAS = [x["id"] for x in json.loads(Path("datos/escalas.json").read_text(encoding="utf-8"))["escalas"]]
TIPO = re.compile(r"^[a-z][a-z0-9_]{1,40}$")
for f in features:
    p = f.get("properties", {})
    ident = p.get("id", "¿sin id?")
    if p.get("escala") is not None and p["escala"] not in ESCALAS:
        errores.append(f"{ident}: escala «{p['escala']}» no está en datos/escalas.json ({', '.join(ESCALAS)})")
    if p.get("espacio") not in (None, "lugar", "red", "sin_espacio"):
        errores.append(f"{ident}: espacio «{p['espacio']}» no es lugar, red ni sin_espacio")
    md = p.get("muestra_de")
    destinos = [("muestra_de", a) for a in (md if isinstance(md, list) else [md] if md else [])]
    for r in p.get("relaciones") or []:
        if not isinstance(r, dict) or not TIPO.match(str(r.get("tipo", ""))):
            errores.append(f"{ident}: relación sin tipo válido (minúsculas y guiones bajos): {r}")
        elif r.get("a"):
            destinos.append((r["tipo"], r["a"]))
    for tipo, a in destinos:
        if str(a).lower() not in ids:
            errores.append(f"{ident}: {tipo} apunta a {a}, que no está en el catálogo")
for f in features:
    p, g = f.get("properties", {}), f.get("geometry")
    sens, ident = p.get("sensibilidad"), p.get("id", "¿sin id?")
    if sens not in ("abierta", "difusa", "reservada"):
        errores.append(f"{ident}: falta la sensibilidad (abierta, difusa o reservada)")
    elif sens == "reservada" and g:
        errores.append(f"{ident}: es reservada y no puede llevar coordenadas")
    elif sens == "difusa" and g:
        for v in g.get("coordinates", []):
            celda = (v - PASO / 2) / PASO
            if abs(celda - round(celda)) > 1e-6:
                errores.append(f"{ident}: es difusa y {v} no es el centro de una celda de {PASO}°")
                break

if errores:
    print("El catálogo tiene errores (ubicaciones sensibles o relaciones rotas):\n- " + "\n- ".join(errores))
    sys.exit(1)
print(f"{ruta}: correcto.")
