# CANARIAS.AFRICA

Centro de Arte No Ubicado (Acciones, Relaciones, Investigaciones y Articulaciones Sociales). Museo distribuido de arte y memoria contemporánea. Un proyecto de Gofio Design.

**Estado:** prototipo con datos ficticios. Todavía no está publicado.

## Estructura

```
web/        La web: HTML único con rutas por hash, convocatorias.json y sellos de marca
servidor/   nginx (web, caché de teselas OSM, API), PocketBase (datos y formularios), scripts y temporizadores
datos/      GeoJSON de ejemplo para importar en uMap
docs/       Infraestructura, mapas (OSM y uMap) y formato de convocatorias.json
```

`index.html` y `CNAME` en la raíz son la página provisional antigua. Se retirarán cuando la web se sirva desde el servidor propio.

## Verlo en local

```bash
cd web && python3 -m http.server 8000
# http://localhost:8000
```

En local las teselas no cargan (no hay caché) y el mapa muestra el contorno de las islas.

## Documentación

- [Infraestructura y costes](docs/infraestructura.md)
- [Mapas: OpenStreetMap en caché y nodos en uMap](docs/mapas-osm-umap.md)
- [Formato de convocatorias.json](docs/convocatorias-formato.md)

## Licencia

Pendiente de definir.
