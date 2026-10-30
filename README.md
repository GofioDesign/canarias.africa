# CANARIAS.AFRICA

CANARIAS — Centro de Arte Nunáneo. Arqueologías de Relaciones e Intervenciones Artísticas Socializadas. «El arte no nos pertenece. Su memoria la construimos entre todos». Un proyecto de Gofio Design.

**Estado:** prototipo con datos ficticios. Todavía no está publicado.

## Estructura

```
web/          La web (GitHub Pages): HTML único con rutas por hash, convocatorias.json y sellos de marca
servidor-php/ api.canarias.africa en el hosting PHP: formularios, QR, moderación, caché de teselas, copia de uMap
servidor/     Plan B para un VPS propio (nginx + PocketBase), anterior al sistema de etiquetas del 28-10-2026
scripts/      Comprobación de que el catálogo no publica ubicaciones sensibles
datos/        GeoJSON de ejemplo para importar en uMap
docs/         Infraestructura, mapas (OSM y uMap) y formato de convocatorias.json
```

`index.html` y `CNAME` en la raíz son la página provisional antigua. Se pueden borrar en cuanto GitHub Pages publique desde la acción `publicar-web.yml`.

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
