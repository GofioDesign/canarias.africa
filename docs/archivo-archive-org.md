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
- Obras de escala íntima (persona, familia, amistades) sin `"archivar": true` expreso.
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
- **Escala** (`"escala"`): de la más íntima a la más amplia: `persona`, `familia`, `amistades`, `barrio`, `municipio` (por defecto), `mancomunidad`, `isla`, `provincia`, `canarias`, `macaronesia`, `estado`, `continente`, `global`. La lista vive en `datos/escalas.json`: añadir una escala es añadir una entrada. Las escalas íntimas (persona, familia, amistades) no se inscriben en archive.org salvo `"archivar": true` expreso, porque lo archivado es casi permanente.
- **Líneas temporales ligadas** (relación `muestra_de`, o el atajo `"muestra_de": "<uuid>"` o una lista): la línea temporal propia se liga a otras más generales, de cualquier escala y a la vez a varias. Un evento global puede dar lugar a x muestras locales. La ficha muestra el árbol hacia arriba (a qué se liga) y hacia abajo (qué ha generado).
- **Trayectorias** (`"autoria"` y `"colaboraciones"`: `["Nombre o alias", …]` o `[{"id", "nombre", "rol"}]`): la línea temporal de cada artista, colectivo o colaborador (registro, mapeo, foto, documentación, cuidado…) también vincula espacios. Quienes colaboran forman parte de la obra. Cada uno tiene su página `/agente/<id>/` con sus obras en orden, y cada ficha enlaza con la obra anterior y la siguiente de cada autoría.
- **Privacidad de las personas**: las líneas temporales y de lugares de las personas no son identificables en la red pública. Solo las ven quienes forman parte de ellas, salvo que quieran publicarlas (`"publica": true`) o que ambas sean personajes públicos. La autoría acreditada de una obra es pública salvo `"publica": false`; una colaboración, solo con `"publica": true`. Lo que no es público no entra en el catálogo (que es un repositorio público): solo su número, `"vinculos_privados"`. `comprobar-catalogo.py` lo vigila.
- **Glifo del mundo de vínculos**: cada ficha dibuja la obra en el centro, sus vínculos públicos en trazo continuo (enlazan a las otras fichas) y los privados en discontinuo y a poco contraste, anónimos: se ve que existen, no quiénes son ni adónde llevan. Cada persona, con su sesión iniciada, ve con nombre en la ficha los vínculos de los que forma parte (ver abajo).
- **Vínculos abiertos** (`"relaciones": [{"tipo", "a", "nota"}]`): responde a, inspirada en, cita, continúa, se superpone a, dialoga con… o cualquier tipo nuevo en minúsculas con guiones bajos, sin tocar el código. Cada vínculo aparece en las dos fichas, con su nombre en los dos sentidos.
- `comprobar-catalogo.py` rechaza escalas o espacios desconocidos, tipos mal escritos y vínculos a obras que no están en el catálogo.

## Vínculos de personas en api.canarias.africa

Tabla `vinculos` (`servidor-php/privado/esquema.sql`) y rutas en `servidor-php/privado/vinculos.php`. Todo con sesión (`Authorization: Bearer`), salvo la pública:

| Ruta | Qué hace |
|---|---|
| `POST /api/vinculos` | `{tipo, alias}` propone un vínculo con otra persona (familia, amistad, colaboracion…), que tiene que aceptarlo; `{tipo, obra, rol}` crea tu vínculo con una obra (colaboracion, participacion, recuerdo…) |
| `GET /api/vinculos/mios` | Tus vínculos, con nombre, y las propuestas que esperan tu respuesta |
| `GET /api/vinculos/obra/<uuid>` | Los vínculos de esa obra de los que formas parte, con nombre |
| `POST /api/vinculos/<id>/aceptar`, `/rechazar` | Responder a una propuesta (solo la persona invitada) |
| `POST /api/vinculos/<id>/retirar` | Cualquiera de las partes lo retira; deja de contar también en el número público |
| `POST /api/vinculos/<id>/publicar` | `{publico: true/false}`: tu permiso. Se publica solo cuando lo dan todas las partes |
| `GET /api/publico/obras/<uuid>/vinculos` | Lo único que ve el público: `{privados: n, publicos: [...]}` |

A quien no forma parte de un vínculo, el servidor le responde que no existe. Al publicar la web, `generar-fichas.py` pide esta ruta pública para cada obra: suma `privados` al glifo y añade a las trayectorias las colaboraciones publicadas.

## Huellas de espectadores

Quien mira, escanea, verifica u observa una obra también se convierte en trazo de ella. En el glifo aparecen como un polvo de puntos alrededor del mundo de vínculos (más puntos cuantas más huellas, en escala logarítmica).

- **Solo se traza con la sesión iniciada** (decisión del 01-11-2026). Sin cuenta no se registra nada, y así nadie puede inflar el contador.
- `POST /api/obras/<uuid>/huella` (con sesión): la web la envía al abrir una ficha, salvo que el navegador pida no ser rastreado (Do Not Track o Global Privacy Control). El escaneo de una etiqueta colocada con sesión suma otra huella.
- **Sin sesión no hay trazo, pero sí mapa de calor**: la visita se suma sin identificar a `POST /api/publico/obras/<uuid>/visita` (una por navegador, obra y día) y solo alimenta `GET /api/publico/calor`, el recuento agregado de los últimos 90 días, que la web dibuja como manchas de calor en el mapa. Solo aparecen obras con 5 visitas o más y nunca las reservadas; las difusas, en el centro de su celda.
- `GET /api/publico/obras/<uuid>/huellas`: `{visitas, escaneos, verificaciones, observaciones, meses, calor_meses}`. Los recuentos y `meses` son solo trazos con sesión; `calor_meses` es el calor por mes (visitas sin sesión), solo los meses con 5 o más.

### Calor y glifo, en el mapa y en el tiempo

- **En el mapa**: bajo cada obra, su mancha de calor (últimos 90 días); alrededor de su punto, su glifo. En la ficha, el glifo va grande; en los demás mapas, pequeño y solo desde el zoom 12, para no tapar la isla. Nunca en las reservadas.
- **En el tiempo**: cada ficha tiene una línea de tiempo (SVG sin JS, la dibuja `scripts/generar-fichas.py` y la reutiliza la web desde `historial.json`, campo `linea`). Arriba, el glifo en la fecha de la obra (y su duración si tiene `fecha_fin`) y, en verde, las obras ligadas en las suyas; sobre el eje, las versiones de la ficha; debajo, los estratos de archive.org; luego el calor por mes en naranja y el polvo de huellas con sesión.
- **Trayectorias**: la página de cada agente (`/agente/<id>/`) pone el glifo de cada obra en su fecha, con el calor y las huellas de todas ellas sumados por mes.
- Solo se guardan recuentos por obra, día y tipo. Para no contar dos veces la misma visita en el día se guarda un hash con una sal diaria que no se conserva, y el cron borra esos hashes cada día. No se guarda quién fue ni su IP.
