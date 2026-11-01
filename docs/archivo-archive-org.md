# Estratos: archivo en archive.org

Arqueología de la red. Cada cambio de canarias.africa y cada obra que se registra se inscriben en archive.org a medida que suceden. No es una copia de seguridad: el rastro es parte del registro y se ve en la web, en `/estratos/` y en la sección «Estratos en archive.org» de cada ficha.

## Cómo funciona

1. Al publicarse la web (acción `publicar-web.yml`), la acción `archivar.yml` ejecuta `scripts/archivar.py`, que pide a **Save Page Now** (la Wayback Machine) que capture:
   - las páginas y datos de `web/` que han cambiado (salvo `web/vendor/`);
   - la página de cada commit nuevo en GitHub (el cambio mismo, no solo su resultado);
   - la ficha estática de cada obra nueva o cambiada, `canarias.africa/obra/<uuid>/`;
   - si la obra es una web (net art, propiedad `url`), **la obra misma**, con captura de pantalla;
   - las observaciones aprobadas de cada obra (`api.canarias.africa/api/publico/obras/<uuid>`) cuando cambian;
   - la portada una vez por semana aunque no cambie nada.
2. Con claves S3, cada versión de una obra se sube además como una **capa** (`capa-<fecha>.geojson`) a su item de Internet Archive `canarias-africa-<uuid>`: un yacimiento por obra, con todas sus versiones superpuestas.
3. El resultado se guarda en `datos/archivo/registro.json`, la acción lo sube al repositorio y vuelve a publicar la web. `scripts/generar-fichas.py` lo convierte en `/estratos/`, `/obra/<uuid>/` y `/datos/archivo.json`.
4. Una vez al día se repite: así entran las observaciones aprobadas en el hosting y se reintentan las capturas fallidas (3 intentos). Cada pasada hace como mucho 30 capturas; el resto queda pendiente para la siguiente.

## Lo que nunca se archiva

- Obras con `"ficticio": true` (datos de ejemplo).
- Obras con `"archivar": false`: la autoría puede pedir que su obra no se inscriba. Hay que decidirlo **antes** de publicarla, porque lo que entra en archive.org es prácticamente permanente.
- Nada que no sea ya público: el catálogo pasa antes por `scripts/comprobar-catalogo.py` (coordenadas difusas o reservadas), y los códigos de las etiquetas no salen nunca del hosting.

## Claves de archive.org

Funciona sin claves (Save Page Now anónimo: más lento, con menos capturas por día y sin items). Para todo lo demás:

1. Crear una cuenta en https://archive.org (mejor con un correo del proyecto).
2. Copiar las claves de https://archive.org/account/s3.php.
3. En GitHub: *Settings → Secrets and variables → Actions → New repository secret*: `IA_ACCESS_KEY` y `IA_SECRET_KEY`.

Los items se crean en la colección comunitaria `opensource_media` con `mediatype: data`. Se puede cambiar con la variable `IA_COLECCION`; si el proyecto llega a tener colección propia en archive.org, basta con poner su identificador.

## Probar sin llamar a archive.org

```bash
python3 scripts/archivar.py --simular   # lista lo que capturaría
python3 scripts/generar-fichas.py       # genera /obra/, /estratos/ y /datos/archivo.json en web/
```

## Fichas: identificador, versión, árbol y escalas

Cada ficha (`/obra/<uuid>/` y la ficha de la web) muestra:

- **Identificador** permanente (el UUID de la obra), **versión** y **fecha** de la última modificación.
- **Árbol de modificaciones**: cada commit que cambia la obra en `web/datos/obras.geojson` es una versión, con los campos que cambiaron (antes → ahora), el enlace al cambio en GitHub, los datos tal como eran y la ficha archivada en ese momento. Los cambios de ubicación se anotan sin valores.
- **Tiempo siempre, espacio no**: `"fecha"` (y `"fecha_fin"`) siempre; `"espacio"` puede ser `lugar`, `red` o `sin_espacio` (si falta, se deduce de la geometría, el municipio o la `url`).
- **Escala** (`"escala"`): `local`, `zonal`, `regional`, `nacional`, `global` o `glocal`.
- **Líneas temporales ligadas** (relación `muestra_de`, o el atajo `"muestra_de": "<uuid>"` o una lista): la línea temporal propia se liga a otras más generales, de cualquier escala y a la vez a varias. Un evento global puede dar lugar a x muestras locales. La ficha muestra el árbol hacia arriba (a qué se liga) y hacia abajo (qué ha generado).
- **Trayectorias** (`"autoria": ["Nombre", …]` o `[{"id", "nombre", "rol"}]`): la línea temporal de cada artista o colectivo también vincula espacios. Cada uno tiene su página `/agente/<id>/` con sus obras en orden, y cada ficha enlaza con la obra anterior y la siguiente de cada autoría.
- **Vínculos abiertos** (`"relaciones": [{"tipo", "a", "nota"}]`): responde a, inspirada en, cita, continúa, se superpone a, dialoga con… o cualquier tipo nuevo en minúsculas con guiones bajos, sin tocar el código. Cada vínculo aparece en las dos fichas, con su nombre en los dos sentidos.
- `comprobar-catalogo.py` rechaza escalas o espacios desconocidos, tipos mal escritos y vínculos a obras que no están en el catálogo.
