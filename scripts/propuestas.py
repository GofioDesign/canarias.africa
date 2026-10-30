#!/usr/bin/env python3
"""Propuestas del colectivo: comprueba propuestas/*.md y genera web/propuestas.json.

  python3 scripts/propuestas.py --comprobar      solo valida (para las pull requests)
  python3 scripts/propuestas.py                  valida y escribe web/propuestas.json

Con GITHUB_TOKEN y GITHUB_REPOSITORY en el entorno (la acción de GitHub los da), además
abre un issue de debate con la etiqueta «propuesta» para cada propuesta que no lo tenga y
lee sus reacciones como votos: 🚀 prioritaria, 👍 interesante, 👎 no.
Solo usa la biblioteca estándar.
"""
import datetime, json, os, re, sys, urllib.request
from pathlib import Path

RAIZ = Path(__file__).resolve().parent.parent
CARPETA = RAIZ / "propuestas"
SALIDA = RAIZ / "web" / "propuestas.json"
ESTADOS = ["abierta", "aprobada", "en-marcha", "hecha", "archivada"]
OBLIGATORIOS = {"titulo": 80, "resumen": 280, "autoria": 80, "estado": 20, "fecha": 10}
ETIQUETA = "propuesta"
API = "https://api.github.com"


def leer(ruta):
    """Devuelve (ficha, cuerpo, errores) de un archivo de propuesta."""
    texto = ruta.read_text(encoding="utf-8").replace("\r\n", "\n")
    m = re.match(r"^---\n(.*?)\n---\n?(.*)$", texto, re.S)
    if not m:
        return None, "", ["falta la ficha entre dos líneas «---» al principio"]
    ficha, errores = {}, []
    for n, linea in enumerate(m.group(1).split("\n"), 2):
        if not linea.strip():
            continue
        k, sep, v = linea.partition(":")
        if not sep:
            errores.append(f"línea {n}: se esperaba «campo: valor»")
            continue
        ficha[k.strip()] = v.strip().strip('"').strip("'")
    for k, maximo in OBLIGATORIOS.items():
        if not ficha.get(k):
            errores.append(f"falta «{k}»")
        elif len(ficha[k]) > maximo:
            errores.append(f"«{k}» tiene más de {maximo} caracteres")
    if ficha.get("estado") and ficha["estado"] not in ESTADOS:
        errores.append(f"«estado» debe ser uno de: {', '.join(ESTADOS)}")
    if ficha.get("fecha"):
        try:
            datetime.date.fromisoformat(ficha["fecha"])
        except ValueError:
            errores.append("«fecha» debe ir como AAAA-MM-DD")
    cuerpo = m.group(2).strip()
    if len(cuerpo) > 12000:
        errores.append("el texto pasa de 12 000 caracteres")
    return ficha, cuerpo, errores


def cargar():
    propuestas, fallos = [], {}
    for ruta in sorted(CARPETA.glob("*.md")):
        if ruta.name.startswith("_") or ruta.name.upper() == "README.MD":
            continue
        pid = ruta.stem
        ficha, cuerpo, errores = leer(ruta)
        if not re.fullmatch(r"[a-z0-9]+(-[a-z0-9]+)*", pid) or len(pid) > 60:
            errores.append("el nombre del archivo debe ir en minúsculas, sin tildes, con guiones y terminar en .md")
        if errores:
            fallos[ruta.name] = errores
            continue
        propuestas.append({"id": pid, **{k: ficha[k] for k in OBLIGATORIOS}, "cuerpo": cuerpo})
    return propuestas, fallos


def gh(metodo, ruta, datos=None):
    req = urllib.request.Request(
        API + ruta, method=metodo,
        data=json.dumps(datos).encode() if datos is not None else None,
        headers={"Authorization": "Bearer " + os.environ["GITHUB_TOKEN"],
                 "Accept": "application/vnd.github+json", "X-GitHub-Api-Version": "2022-11-28",
                 "User-Agent": "canarias.africa-propuestas"})
    with urllib.request.urlopen(req, timeout=30) as r:
        return json.loads(r.read() or "null")


def debates(propuestas, repo):
    """Asocia cada propuesta a su issue de debate (lo crea si falta) y cuenta las reacciones."""
    issues, pagina = [], 1
    while True:
        lote = gh("GET", f"/repos/{repo}/issues?labels={ETIQUETA}&state=all&per_page=100&page={pagina}")
        issues += lote
        if len(lote) < 100:
            break
        pagina += 1
    por_id = {}
    for i in issues:
        m = re.search(r"<!-- propuesta:([a-z0-9-]+) -->", i.get("body") or "")
        if m and m.group(1) not in por_id:
            por_id[m.group(1)] = i
    for p in propuestas:
        i = por_id.get(p["id"])
        if i is None and p["estado"] != "archivada":
            enlace = f"https://github.com/{repo}/blob/main/propuestas/{p['id']}.md"
            i = gh("POST", f"/repos/{repo}/issues", {
                "title": f"Propuesta: {p['titulo']}", "labels": [ETIQUETA],
                "body": (f"{p['resumen']}\n\nTexto completo: {enlace}\nAutoría: {p['autoria']}\n\n"
                         "**Vota con una reacción en este mensaje:** 🚀 prioritaria · 👍 interesante · 👎 no me interesa.\n"
                         "Comenta abajo para debatirla. Los votos se suman a los de canarias.africa.\n\n"
                         f"<!-- propuesta:{p['id']} -->")})
        if i is None:
            continue
        r = i.get("reactions") or {}
        p["debate"] = {"numero": i["number"], "url": i["html_url"], "comentarios": i.get("comments", 0),
                       "prioritaria": r.get("rocket", 0), "interesante": r.get("+1", 0), "no": r.get("-1", 0)}


def main():
    propuestas, fallos = cargar()
    for nombre, errores in fallos.items():
        for e in errores:
            print(f"::error file=propuestas/{nombre}::{e}" if os.environ.get("GITHUB_ACTIONS") else f"{nombre}: {e}")
    if fallos:
        sys.exit(1)
    print(f"{len(propuestas)} propuestas correctas")
    if "--comprobar" in sys.argv:
        return
    repo = os.environ.get("GITHUB_REPOSITORY")
    if os.environ.get("GITHUB_TOKEN") and repo:
        debates(propuestas, repo)
    orden = {e: n for n, e in enumerate(ESTADOS)}
    propuestas.sort(key=lambda p: (orden[p["estado"]], p["fecha"], p["id"]))
    SALIDA.parent.mkdir(exist_ok=True)
    SALIDA.write_text(json.dumps({
        "generado": datetime.datetime.now(datetime.timezone.utc).isoformat(timespec="seconds"),
        "repositorio": repo or "GofioDesign/canarias.africa",
        "propuestas": propuestas}, ensure_ascii=False, indent=1), encoding="utf-8")
    print(f"escrito {SALIDA.relative_to(RAIZ)}")


if __name__ == "__main__":
    main()
