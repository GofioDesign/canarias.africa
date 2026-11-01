"""Comprueba que el catálogo público no publica coordenadas que deberían estar ocultas.

El repositorio es público: las obras «difusa» solo pueden llevar el centro de su celda de 0,02°
y las «reservada» solo un punto ficticio alrededor del centro de su isla o del archipiélago. Las coordenadas exactas de esas obras no se suben nunca.
Uso: python3 scripts/comprobar-catalogo.py [web/datos/obras.geojson]
"""
import json
import math
import re
import sys
from pathlib import Path

PASO = 0.02
# Centros de las obras con ubicación protegida: [lat, lon, radio en km]. Iguales que CENTRO_PROTEGIDO en servidor-php/privado/catalogo.php.
CENTROS = {"CAN": (28.30, -15.80, 50), "TF": (28.27, -16.56, 5), "GC": (27.96, -15.59, 5), "LZ": (29.03, -13.65, 5),
           "FV": (28.40, -14.03, 5), "LP": (28.68, -17.86, 5), "GO": (28.11, -17.22, 5), "EH": (27.75, -18.00, 5), "LG": (29.245, -13.505, 1.5)}
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
        if isinstance(r, dict) and r.get("obra"):  # formato del servidor: {obra, modo}
            destinos.append((r.get("modo") or "relacion", r["obra"]))
        elif not isinstance(r, dict) or not TIPO.match(str(r.get("tipo", ""))):
            errores.append(f"{ident}: relación sin tipo válido (minúsculas y guiones bajos): {r}")
        elif r.get("a"):
            destinos.append((r["tipo"], r["a"]))
    # Personas: solo entran en el catálogo público si son públicas (consentimiento o personaje público).
    for a in p.get("autoria") or []:
        if isinstance(a, dict) and a.get("publica") is False:
            errores.append(f"{ident}: autoría no pública ({a.get('nombre') or a.get('id')}): no puede ir en el catálogo; cuéntala en vinculos_privados")
    for a in p.get("colaboraciones") or []:
        if not isinstance(a, dict) or a.get("publica") is not True:
            errores.append(f"{ident}: colaboración sin \"publica\": true ({a if not isinstance(a, dict) else a.get('nombre')}): no puede ir en el catálogo; cuéntala en vinculos_privados")
    vp = p.get("vinculos_privados")
    if vp is not None and (not isinstance(vp, int) or vp < 0):
        errores.append(f"{ident}: vinculos_privados debe ser un número entero")
    for tipo, a in destinos:
        if str(a).lower() not in ids:
            errores.append(f"{ident}: {tipo} apunta a {a}, que no está en el catálogo")
for f in features:
    p, g = f.get("properties", {}), f.get("geometry")
    sens, ident = p.get("sensibilidad"), p.get("id", "¿sin id?")
    if sens not in ("abierta", "difusa", "reservada"):
        errores.append(f"{ident}: falta la sensibilidad (abierta, difusa o reservada)")
    elif sens == "reservada" and g:
        # Solo vale el punto ficticio alrededor del centro de su isla o del archipiélago (lo calcula el servidor).
        centro = CENTROS.get(p.get("isla") or "CAN")
        lon, lat = (g.get("coordinates") or [None, None])[:2]
        if not centro or lat is None or p.get("precision") not in ("isla", "canarias") or \
                math.hypot((lat - centro[0]) * 111.32, (lon - centro[1]) * 111.32 * math.cos(math.radians(centro[0]))) > centro[2] + 0.05:
            errores.append(f"{ident}: es reservada y solo puede llevar el punto ficticio de su isla o del archipiélago")
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
