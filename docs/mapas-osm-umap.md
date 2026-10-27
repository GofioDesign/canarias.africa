# Mapas de canarias.africa: OpenStreetMap en caché + nodos en uMap

Nota técnica del prototipo, 27 de octubre de 2026.

## 1. Cómo se reparte el trabajo

| Pieza | Dónde vive | Quién la edita |
|---|---|---|
| Fondo del mapa (teselas OSM) | Caché en el propio servidor de canarias.africa (`/teselas/osm/{z}/{x}/{y}.png`) | Nadie: se rellena sola con las visitas |
| Nodos geográficos (obras, QR, rutas) | Un mapa de uMap con una capa por tipo | El equipo, en uMap |
| Nodos no geográficos (agentes, entidades, fuentes, observaciones) y todas las relaciones | Base de datos de la web | La web, el equipo y las aportaciones validadas |

La unión entre uMap y la red se hace por la propiedad `id` de cada elemento de uMap (por ejemplo `CA-TF-ARI-0001`). Las coordenadas oficiales salen de uMap y todo lo demás de la red.

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

Crear un mapa en https://umap.openstreetmap.fr con estas capas:

| Capa | Geometría | Propiedades obligatorias |
|---|---|---|
| Obras | Punto | `id` (CA-isla-municipio-nnnn), `name`, `tipo=obra`, `fase` |
| QR | Punto | `id` (QR-nnnn), `obra` (id de la obra), `estado` |
| Rutas | Línea | `id` (RU-…), `name`, `paradas` (ids de obra separados por comas) |

- El `id` no se cambia nunca, aunque la obra se mueva o desaparezca.
- Una obra desaparecida no se borra de uMap: se cambia su `fase` a `desaparicion` o `memoria`.
- Para empezar, importa en uMap los ficheros de ejemplo ([umap-obras-ejemplo.geojson](umap-obras-ejemplo.geojson) y [umap-rutas-ejemplo.geojson](umap-rutas-ejemplo.geojson)), cada uno en su capa. Son datos ficticios: bórralos cuando empieces a meter obras reales.

## 4. Sincronización

En `canarias-africa.html`, el bloque `MAPA.umap` recoge el id del mapa y los de sus capas. Al rellenarlo, la web pide cada capa como GeoJSON a uMap y actualiza las coordenadas por `id`. En producción conviene que sea el servidor quien descargue las capas cada cierto tiempo (por ejemplo, cada hora) y las guarde, para que la web no dependa de que uMap responda en cada visita. Hay que comprobar la URL exacta de exportación de capas en la versión de uMap que se use.

## 5. Mapa según la ubicación del usuario

- La vista «Archivo y mapa» pide la ubicación con un botón. No la pide al abrir la página.
- Si el navegador no la comparte, la persona puede elegir su municipio en una lista.
- El mapa se centra en esa zona y muestra las obras que hay a menos de 15 km.
- Si no hay ninguna, invita a colaborar con un mensaje («¿Conoces alguna obra de arte o expresión artística en tu región? Colabora») que lleva al formulario con «obra nueva» ya marcada.
- La ubicación solo se guarda en el navegador de esa persona y nunca se envía al servidor.

## 6. Limitación de la vista previa

El artifact de Claude no puede cargar imágenes de otros servidores ni pedir la ubicación. Por eso allí se ve un contorno simplificado de las islas en lugar de las teselas, y la ubicación se elige en la lista. Subido a canarias.africa, el mismo código carga el mapa de OSM y pide la ubicación con normalidad.
