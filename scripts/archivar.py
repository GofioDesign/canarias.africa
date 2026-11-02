"""Inscribe en archive.org cada cambio de canarias.africa y cada obra del catálogo, a medida que suceden.

Arqueología de la red: la web no se guarda solo como copia de seguridad. Cada publicación deja un
estrato en la Wayback Machine (Save Page Now) y cada versión de una obra deja una capa en su item de
Internet Archive. El rastro (qué se capturó, cuándo y por qué) queda en datos/archivo/registro.json,
y scripts/generar-fichas.py lo publica en /estratos/, en la ficha estática de cada obra
(/obra/<uuid>/) y en /datos/archivo.json.

Qué se captura en cada pasada:
  1. Las páginas y datos de web/ que han cambiado desde la última pasada (no web/vendor/).
  2. La página de cada commit nuevo en GitHub: el cambio mismo, no solo su resultado.
  3. Cada obra nueva o cambiada: su ficha estática y, si es una obra web (propiedad "url"), la obra
     misma, con captura de pantalla. Con claves S3, además, una capa nueva en su item de Internet Archive.
  4. Las observaciones aprobadas de cada obra (api.canarias.africa) cuando cambian.
  5. Un estrato semanal de la portada aunque no haya cambios.

Nunca se archiva una obra con "ficticio": true (datos de ejemplo) ni con "archivar": false, y las de escala
íntima (persona, familia, amistades; ver datos/escalas.json) solo con "archivar": true expreso.
El catálogo ya llega sin coordenadas sensibles (lo vigila scripts/comprobar-catalogo.py), y aquí
no se lee nada que no sea público.

Claves (secretos del repositorio IA_ACCESS_KEY e IA_SECRET_KEY, de https://archive.org/account/s3.php):
sin ellas se usa Save Page Now anónimo, más lento y limitado, y no se crean items.

Uso: python3 scripts/archivar.py [--simular] [--max N]
"""
import argparse
import datetime as dt
import hashlib
import json
import os
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

WEB = os.environ.get("CA_WEB", "https://canarias.africa").rstrip("/")
API = os.environ.get("CA_API", "https://api.canarias.africa").rstrip("/")
SPN = os.environ.get("IA_SPN_BASE", "https://web.archive.org").rstrip("/")
S3 = os.environ.get("IA_S3_BASE", "https://s3.us.archive.org").rstrip("/")
COLECCION = os.environ.get("IA_COLECCION", "opensource_media")
REPO = os.environ.get("GITHUB_REPOSITORY", "GofioDesign/canarias.africa")
ACCESO, SECRETO = os.environ.get("IA_ACCESS_KEY", ""), os.environ.get("IA_SECRET_KEY", "")
CON_CLAVES = bool(ACCESO and SECRETO)
UA = "canarias.africa archivo (+https://canarias.africa/estratos/)"

REGISTRO = Path("datos/archivo/registro.json")
CATALOGO = Path("web/datos/obras.geojson")
SOLO_REGISTRO = "datos/archivo/"  # los commits que solo tocan el registro no se archivan (evita bucles)
INTENTOS = 3
SEMANA = dt.timedelta(days=7)


def ahora():
    return dt.datetime.now(dt.timezone.utc).replace(microsecond=0)


def git(*args):
    return subprocess.run(["git", *args], capture_output=True, text=True, check=True).stdout.strip()


def huella(obj):
    return hashlib.sha256(json.dumps(obj, sort_keys=True, ensure_ascii=False).encode()).hexdigest()[:16]


ESPERA = float(os.environ.get("IA_ESPERA", "15"))  # segundos antes del primer reintento (las pruebas lo bajan)


def peticion(url, datos=None, metodo=None, cabeceras=None, tiempo=60, intentos=4):
    """archive.org corta conexiones cuando recibe muchas seguidas (Connection refused, 429, 5xx): se espera y se reintenta."""
    cab = {"User-Agent": UA, **(cabeceras or {})}
    if isinstance(datos, dict):
        datos = urllib.parse.urlencode(datos).encode()
        cab.setdefault("Content-Type", "application/x-www-form-urlencoded")
    for k in range(intentos):
        req = urllib.request.Request(url, data=datos, method=metodo, headers=cab)
        try:
            with urllib.request.urlopen(req, timeout=tiempo) as r:
                return r.status, dict(r.headers), r.read()
        except urllib.error.HTTPError as err:
            if err.code not in (429, 500, 502, 503, 504) or k + 1 == intentos:
                raise
        except (urllib.error.URLError, TimeoutError, ConnectionError):
            if k + 1 == intentos:
                raise
        time.sleep(ESPERA * 2 ** k)


def auth():
    return {"Authorization": f"LOW {ACCESO}:{SECRETO}"} if CON_CLAVES else {}


# ---------- Save Page Now ----------

def capturar(url, pantalla=False):
    """Pide a Save Page Now una captura de url. Devuelve (url en la Wayback, url de la captura de pantalla)."""
    if not CON_CLAVES:
        # Anónimo: la propia petición hace la captura y devuelve la ruta en Content-Location o en la redirección.
        st, cab, _ = peticion(f"{SPN}/save/{url}", tiempo=180)
        ruta = cab.get("Content-Location") or cab.get("Location") or ""
        if "/web/" not in ruta:
            raise RuntimeError(f"Save Page Now no devolvió la captura (HTTP {st})")
        return urllib.parse.urljoin(SPN, ruta), None

    opciones = {"url": url, "capture_all": "1", "skip_first_archive": "1"}
    if pantalla:
        opciones["capture_screenshot"] = "1"
    _, _, cuerpo = peticion(f"{SPN}/save", opciones, cabeceras={**auth(), "Accept": "application/json"})
    r = json.loads(cuerpo)
    if not r.get("job_id"):
        raise RuntimeError(r.get("message") or "Save Page Now rechazó la petición")
    for _ in range(40):  # hasta ~4 minutos por captura
        time.sleep(6)
        _, _, cuerpo = peticion(f"{SPN}/save/status/{r['job_id']}", cabeceras={**auth(), "Accept": "application/json"})
        e = json.loads(cuerpo)
        if e.get("status") == "success":
            ts, original = e["timestamp"], e.get("original_url", url)
            shot = f"{SPN}/web/{ts}/http://web.archive.org/screenshot/{original}" if pantalla else None
            return f"{SPN}/web/{ts}/{original}", shot
        if e.get("status") == "error":
            raise RuntimeError(e.get("message") or e.get("status_ext") or "error")
    raise RuntimeError("Save Page Now no terminó a tiempo")


# ---------- Items de Internet Archive (S3) ----------

def meta(valor):
    """Las cabeceras HTTP no admiten UTF-8: Internet Archive acepta uri(…) con el texto codificado."""
    return "uri(" + urllib.parse.quote(str(valor), safe="") + ")"


def subir_capa(obra, fecha):
    """Añade una capa (la versión actual de la obra) a su item canarias-africa-<uuid>. Devuelve el item y el fichero."""
    p = obra["properties"]
    item = "canarias-africa-" + p["id"].lower()
    nombre = f"capa-{fecha.strftime('%Y%m%dT%H%M%SZ')}.geojson"
    cab = {
        **auth(),
        "Content-Type": "application/geo+json",
        "x-amz-auto-make-bucket": "1",
        # Solo se aplican al crear el item; las capas siguientes se suman a él.
        "x-archive-meta-mediatype": "data",
        "x-archive-meta-collection": COLECCION,
        "x-archive-meta-title": meta(f"CANARIAS · {p.get('name') or p['id']}"),
        "x-archive-meta-creator": meta("CANARIAS — Catálogo Atlántico de Núnea Arqueología · Registro de Intervenciones Artísticas Socializado"),
        "x-archive-meta-description": meta(
            "Capas sucesivas de la ficha de esta obra en canarias.africa, inscritas a medida que cambian. "
            "«El arte no nos pertenece. Su memoria la construimos entre todos.»"),
        "x-archive-meta-source": f"{WEB}/obra/{p['id'].lower()}/",
        "x-archive-meta-language": "spa",
        "x-archive-meta01-subject": meta("canarias.africa"),
        "x-archive-meta02-subject": meta("arqueología de la red"),
        "x-archive-meta03-subject": meta("arte público"),
        "x-archive-meta04-subject": meta("Canarias"),
    }
    datos = json.dumps(obra, ensure_ascii=False, indent=2).encode()
    peticion(f"{S3}/{item}/{nombre}", datos, metodo="PUT", cabeceras=cab, tiempo=300)
    return item, nombre


# ---------- Qué hay que capturar ----------

def url_de(ruta):
    """web/foo/index.html → https://canarias.africa/foo/"""
    rel = ruta[len("web/"):]
    if rel.endswith("index.html"):
        rel = rel[: -len("index.html")]
    return f"{WEB}/{rel}"


def commits_nuevos(desde):
    cabeza = git("rev-parse", "HEAD")
    if desde:
        try:
            git("merge-base", "--is-ancestor", desde, cabeza)
            lista = git("rev-list", "--reverse", f"{desde}..{cabeza}").split()
        except subprocess.CalledProcessError:
            lista = [cabeza]  # historia reescrita o commit desconocido: solo el último
    else:
        lista = [cabeza]
    utiles = []
    for c in lista:
        ficheros = git("show", "--name-only", "--format=", c).split()
        if ficheros and all(f.startswith(SOLO_REGISTRO) for f in ficheros):
            continue
        utiles.append(c)
    return cabeza, utiles


def rutas_cambiadas(desde, cabeza):
    if desde:
        try:
            return git("diff", "--name-only", desde, cabeza).split()
        except subprocess.CalledProcessError:
            pass
    return git("ls-files", "web").split()  # primera pasada: toda la web


def obras_publicas():
    if not CATALOGO.exists():
        return []
    fs = json.loads(CATALOGO.read_text(encoding="utf-8")).get("features", [])
    esc = Path("datos/escalas.json")
    intimas = {x["id"] for x in json.loads(esc.read_text(encoding="utf-8"))["escalas"] if x.get("intima")} if esc.exists() else set()

    def archivable(p):
        if p.get("ficticio") or p.get("archivar") is False:
            return False
        # Escalas íntimas (persona, familia, amistades): solo con consentimiento expreso.
        return p.get("archivar") is True or p.get("escala") not in intimas

    return [f for f in fs if f.get("properties", {}).get("id") and archivable(f["properties"])]


def observaciones(uuid):
    try:
        st, _, cuerpo = peticion(f"{API}/api/publico/obras/{uuid}", tiempo=20)
        return huella(json.loads(cuerpo)) if st == 200 else None
    except Exception:
        return None  # API caída o sin desplegar: se intentará en la siguiente pasada


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--simular", action="store_true", help="no llama a archive.org; muestra lo que haría")
    ap.add_argument("--max", type=int, default=int(os.environ.get("IA_MAX_CAPTURAS", 30)))
    a = ap.parse_args()

    reg = json.loads(REGISTRO.read_text(encoding="utf-8")) if REGISTRO.exists() else {}
    antes = huella({k: v for k, v in reg.items() if k not in ("ultimo_commit", "actualizado")})
    reg.setdefault("capturas", [])
    reg.setdefault("pendientes", [])
    reg.setdefault("obras", {})
    fecha = ahora()

    cabeza, commits = commits_nuevos(reg.get("ultimo_commit"))
    cambiadas = rutas_cambiadas(reg.get("ultimo_commit"), cabeza)

    nuevas = []  # {url, motivo, obra?, commit?, pantalla?}
    for f in cambiadas:
        if f.startswith("web/") and not f.startswith("web/vendor/") and Path(f).exists():
            nuevas.append({"url": url_de(f), "motivo": "cambio en la web", "commit": cabeza})
    for c in commits:
        nuevas.append({"url": f"https://github.com/{REPO}/commit/{c}", "motivo": "commit", "commit": c})

    capas = []
    for o in obras_publicas():
        p = o["properties"]
        uuid = p["id"].lower()
        estado = reg["obras"].setdefault(uuid, {})
        h = huella(o)
        if estado.get("huella") != h:
            nuevas.append({"url": f"{WEB}/obra/{uuid}/", "motivo": "obra nueva" if not estado else "obra cambiada", "obra": uuid})
            web = p.get("url") or p.get("soporte_url") or ""  # el servidor la publica como soporte_url
            if web.startswith(("http://", "https://")):
                nuevas.append({"url": web, "motivo": "obra web", "obra": uuid, "pantalla": True})
            capas.append(o)
            estado["huella"] = h
        hobs = observaciones(uuid)
        if hobs and estado.get("observaciones") != hobs:
            nuevas.append({"url": f"{API}/api/publico/obras/{uuid}", "motivo": "observaciones", "obra": uuid})
            estado["observaciones"] = hobs

    portada = [c for c in reg["capturas"] if c.get("url") == f"{WEB}/" and c.get("wayback")]
    if not portada or fecha - dt.datetime.fromisoformat(portada[-1]["fecha"]) > SEMANA:
        nuevas.append({"url": f"{WEB}/", "motivo": "estrato semanal"})

    cola, vistas = [], set()
    for t in reg["pendientes"] + nuevas:
        if t["url"] not in vistas:
            vistas.add(t["url"])
            cola.append(t)
    hechas, resto = cola[: a.max], cola[a.max:]

    print(f"{len(cola)} capturas en cola ({'con claves S3' if CON_CLAVES else 'Save Page Now anónimo'}), {len(capas)} capas de obra.")
    if a.simular:
        for t in hechas:
            print(f"  · {t['motivo']}: {t['url']}")
        for o in capas:
            print(f"  · capa: canarias-africa-{o['properties']['id'].lower()}" + ("" if CON_CLAVES else " (necesita claves)"))
        return

    reg["pendientes"] = []
    nuevas_ok = 0
    for i, t in enumerate(hechas):
        if i:
            time.sleep(10 if CON_CLAVES else 20)
        entrada = {k: v for k, v in t.items() if k in ("url", "motivo", "obra", "commit")}
        entrada["fecha"] = ahora().isoformat()
        try:
            wb, shot = capturar(t["url"], t.get("pantalla", False))
            entrada["wayback"] = wb
            if shot:
                entrada["pantalla"] = shot
            reg["capturas"].append(entrada)
            nuevas_ok += 1
            print(f"  ✓ {t['url']} → {wb}")
        except Exception as e:
            t["intentos"] = t.get("intentos", 0) + 1
            print(f"  ✗ {t['url']}: {e}", file=sys.stderr)
            if t["intentos"] < INTENTOS:
                reg["pendientes"].append(t)
            else:
                reg["capturas"].append({**entrada, "error": str(e)[:200]})
    reg["pendientes"] += resto

    if CON_CLAVES:
        for o in capas:
            uuid = o["properties"]["id"].lower()
            try:
                item, nombre = subir_capa(o, fecha)
                reg["obras"][uuid]["item"] = f"https://archive.org/details/{item}"
                reg["obras"][uuid].setdefault("capas", []).append({"fecha": fecha.isoformat(), "fichero": nombre})
                print(f"  ✓ capa {nombre} en {item}")
            except Exception as e:
                reg["obras"][uuid]["huella"] = None  # se reintenta en la siguiente pasada
                print(f"  ✗ capa de {uuid}: {e}", file=sys.stderr)

    if huella({k: v for k, v in reg.items() if k not in ("ultimo_commit", "actualizado")}) == antes:
        print("Nada nuevo que inscribir.")
        return  # no se toca el registro: así no hay commit ni nueva publicación
    reg["ultimo_commit"] = cabeza
    reg["actualizado"] = fecha.isoformat()
    REGISTRO.parent.mkdir(parents=True, exist_ok=True)
    REGISTRO.write_text(json.dumps(reg, ensure_ascii=False, indent=1) + "\n", encoding="utf-8")
    print(f"{nuevas_ok} capturas nuevas, {len(reg['pendientes'])} pendientes.")


if __name__ == "__main__":
    main()
