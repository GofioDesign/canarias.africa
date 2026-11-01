# Mapas de canarias.africa: OpenStreetMap en caché + nodos en uMap

Nota técnica del prototipo, 27 de octubre de 2026.

## 1. Cómo se reparte el trabajo

| Pieza | Dónde vive | Quién la edita |
|---|---|---|
| Fondo del mapa (teselas OSM) | Caché en el propio servidor de canarias.africa (`/teselas/osm/{z}/{x}/{y}.png`) | Nadie: se rellena sola con las visitas |
| Nodos geográficos (obras, QR, rutas) | Un mapa de uMap con una capa por tipo | El equipo, en uMap |
| Nodos no geográficos (agentes, entidades, fuentes, observaciones) y todas las relaciones | Base de datos de la web | La web, el equipo y las aportaciones validadas |

La unión entre uMap y la red se hace por la propiedad `id` de cada elemento de uMap, que es el UUID de la obra. Las coordenadas oficiales salen de uMap y todo lo demás de la red.

## 2. Caché de teselas en la propia web

Si el hosting permite nginx, basta con un proxy con caché:

```nginx
proxy_cache_path /var/cache/nginx/teselas levels=1:2 keys_zone=teselas:20m
                 max_size=5g inactive=60d use_temp_path=off;

server {
  # ...
  location /teselas/osm/ {
    proxy_pass https://tile.openstreetmap.org/;
    proxy_ssl_server_name on;
    proxy_set_header Host tile.openstreetmap.org;
    proxy_set_header User-Agent "canarias.africa tile cache (contacto: CORREO-DEL-PROYECTO)";
    proxy_cache teselas;
    proxy_cache_valid 200 30d;
    proxy_cache_use_stale error timeout updating http_500 http_502 http_503 http_504;
    proxy_cache_lock on;
    add_header X-Cache $upstream_cache_status;
    expires 30d;
  }
}
```

Condiciones de la política de uso de teselas de OSM (https://operations.osmfoundation.org/policies/tiles/):
- Hay que mostrar siempre la atribución «© OpenStreetMap». El prototipo ya la muestra.
- El User-Agent debe identificar el proyecto y llevar un contacto.
- **No se permite descargar en bloque** ni precargar zonas enteras desde tile.openstreetmap.org. La caché tiene que llenarse con las visitas reales.

Si más adelante se quiere un mapa 100 % propio (sin depender de los servidores de OSM ni para llenar la caché), la alternativa es generar las teselas de Canarias a partir del extracto de Geofabrik y servirlas como un único fichero PMTiles. Se puede dejar para una segunda fase.

Si el hosting es compartido y no hay nginx, se puede hacer lo mismo con un pequeño script PHP que guarde cada tesela en disco la primera vez que se pide.

## 3. Convenciones para uMap

**uMap solo muestra; no es fuente de datos (decisión del usuario, 1/11/2026).** Las obras, rutas y artistas se crean y editan en `https://api.canarias.africa/admin` (Catálogo · Rutas · Artistas), con su historial de versiones. El servidor genera cuatro GeoJSON y **GitHub Pages sirve una copia en `https://canarias.africa/datos/`**, que es lo que leen la web y uMap. Así el servidor solo recibe una petición por capa en cada publicación, no una por visita. La copia se renueva:

- al guardar en `/admin`, si `github_token` está en `config.php` (el servidor lanza la acción «Publicar la web»; como mucho una vez cada 3 minutos);
- con el botón «Publicar ahora» del catálogo;
- con el cron horario, si lo publicado ha cambiado (por ejemplo, el estado de una etiqueta QR);
- y, en todo caso, cada 6 horas.

La copia no se guarda en el repositorio: `scripts/catalogo-servidor.py` la mete directamente en el artefacto de Pages. Si falta, la web lee del servidor.

En uMap, cada capa usa **«Datos remotos»** con formato `geojson`, **sin** actualización dinámica y sin proxy:

| Capa | Geometría | URL de «Datos remotos» |
|---|---|---|
| Obras | Punto (las reservadas no salen en el mapa) | `https://canarias.africa/datos/obras.geojson` |
| Soportes (antes «QR») | Punto: el muro, la roca o el objeto que lleva la etiqueta, con sus obras de ahora y de antes. Las webs no salen | `https://canarias.africa/datos/soportes.geojson` (`qr.geojson` sigue como copia) |
| Rutas | Línea | `https://canarias.africa/datos/rutas.geojson` |
| Artistas | Punto (solo si muestran un espacio público suyo) | `https://canarias.africa/datos/artistas.geojson` |

- El `id` de una obra (UUID) no se cambia nunca, aunque la obra se mueva o desaparezca. Una obra desaparecida no se borra: se cambia su `fase` a `desaparicion` o `memoria`.
- El servidor ya oculta lo sensible: las difusas salen en el centro de su celda de 0,02°, las reservadas (obras delicadas) sin municipio, con su isla y un punto simbólico a hasta 5 km del centro de la isla (o 50 km del centro del archipiélago si no se indica isla), calculado a partir del UUID y no de la ubicación real, estable entre publicaciones y los códigos de las etiquetas QR nunca. El trazado dibujado de una ruta solo se publica si todas sus paradas son abiertas.
- Un perfil de artista solo se publica con su consentimiento anotado, y su punto solo puede ser un espacio público suyo (taller abierto, galería, tienda), nunca su domicilio. La tienda de artistas se alojará en canarias.africa (fase siguiente).

## 4. Arquitectura y sincronización (decisión del 27/10/2026)

La web y las fichas se sirven como estáticas desde GitHub Pages (`canarias.africa`, repo público). Todo lo dinámico vive en el hosting PHP, en `https://api.canarias.africa` (constante `API_BASE` en el HTML).

- **Teselas:** `https://api.canarias.africa/teselas/osm/{z}/{x}/{y}.png`. Es la caché del apartado 2, pero en el hosting PHP.
- **Catálogo de obras:** `https://canarias.africa/datos/obras.geojson`, copia del que genera el servidor en `https://api.canarias.africa/datos/obras.geojson` a partir de su base de datos (se edita en `/admin`). Las obras difusas llegan con el centro de su celda de 0,02° y las reservadas sin municipio, con `isla` y solo un punto simbólico alrededor del centro de la isla o del archipiélago (`precision`: `isla` o `canarias`). El repo es público y no guarda el catálogo: ni el HTML ni el repo llevan nunca coordenadas reales de obras sensibles.
- **Ficha:** la descripción y las relaciones salen del catálogo. Las observaciones aprobadas se piden a `GET https://api.canarias.africa/api/publico/obras/<código>`.
- **QR, rutas y artistas:** igual, en `https://canarias.africa/datos/{qr,rutas,artistas}.geojson` (copia) y `https://api.canarias.africa/datos/…` (origen). uMap muestra la copia como «Datos remotos» (apartado 3); la web lee la misma. `/datos/umap/…` sigue funcionando como nombre antiguo.
- **QR impresos (decisión del usuario, 28/10/2026):** cada QR tiene su propio UUID y apunta a `https://canarias.africa/<uuid-del-QR>`. La página 404 de GitHub Pages lo convierte en `#/q/<uuid>`, y la web pregunta a `GET https://api.canarias.africa/api/qr/<uuid>` a qué obra corresponde y si está colocado. Los códigos salen de un pool y se asignan en cupos a colaboradores (por ejemplo, 10). Si no confirman la colocación dentro del plazo, el código vuelve al pool. El UUID del QR es distinto del de la obra y no se publica.
- **Envíos:** se hacen en multipart a las colecciones de PocketBase en `https://api.canarias.africa/api/collections/<colección>/records` (observaciones, contexto, propuestas_obra, propuestas_ruta e inscripciones). Todos llevan `acepta_privacidad`. El servidor solo acepta peticiones (CORS) de `https://canarias.africa`.
- Si el catálogo no carga, como en la vista previa, la web funciona con los datos de ejemplo.

## 5. Mapa según la ubicación del usuario

- La vista «Archivo y mapa» pide la ubicación con un botón. No la pide al abrir la página.
- Si el navegador no la comparte, la persona puede elegir su municipio en una lista.
- El mapa se centra en esa zona y muestra las obras que hay a menos de 15 km.
- Si no hay ninguna, invita a colaborar con un mensaje («¿Conoces alguna obra de arte o expresión artística en tu región? Colabora») que lleva al formulario con «obra nueva» ya marcada.
- La ubicación solo se guarda en el navegador de esa persona y nunca se envía al servidor.

## 6. Limitación de la vista previa

El artifact de Claude no puede cargar imágenes de otros servidores ni pedir la ubicación. Por eso allí se ve un contorno simplificado de las islas en lugar de las teselas, y la ubicación se elige en la lista. Subido a canarias.africa, el mismo código carga el mapa de OSM y pide la ubicación con normalidad.
