"""Comprueba que el catálogo público no publica coordenadas que deberían estar ocultas.

El repositorio es público: las obras «difusa» solo pueden llevar el centro de su celda de 0,02°
y las «reservada» solo un punto ficticio alrededor del centro de su isla o del archipiélago. Las coordenadas exactas de esas obras no se suben nunca.
Uso: python3 scripts/comprobar-catalogo.py [web/datos/obras.geojson]
"""
import json
import math
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
for f in json.loads(ruta.read_text(encoding="utf-8")).get("features", []):
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
    print("El catálogo publicaría ubicaciones sensibles:\n- " + "\n- ".join(errores))
    sys.exit(1)
print(f"{ruta}: correcto.")
