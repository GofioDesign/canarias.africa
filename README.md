# CANARIAS.AFRICA

CANARIAS — Catálogo Atlántico de Núnea Arqueología · Registro de Intervenciones Artísticas Socializado. «El arte no nos pertenece. Su memoria la construimos entre todos». *Núnea* es una palabra nueva, eco de «nunáneo» (lo que no está en un solo lugar) y de «nueva», que nombra cualquier expresión artística y cultural que deja huella en el territorio. Un proyecto de Gofio Design.

**Estado:** página de lanzamiento (declaración, propuestas votables y lista de correo). El prototipo de la web, con datos ficticios, está en `/prototipo/`.

## Estructura

```
web/          La web (GitHub Pages): página de lanzamiento en index.html, prototipo en prototipo/, convocatorias.json y sellos
propuestas/   Propuestas del colectivo, una por archivo .md; se votan en la web y en su issue de GitHub (ver propuestas/README.md)
servidor-php/ api.canarias.africa en el hosting PHP: catálogo (obras, rutas, artistas), formularios, QR, moderación, caché de teselas
servidor/     Plan B para un VPS propio (nginx + PocketBase), anterior al sistema de etiquetas del 28-10-2026
scripts/      Comprobación del catálogo (ubicaciones sensibles) y de las propuestas, que genera web/propuestas.json
datos/        GeoJSON de ejemplo para importar en uMap
docs/         Infraestructura, mapas (OSM y uMap) y formato de convocatorias.json
```

`index.html` y `CNAME` en la raíz son la página provisional antigua. Se pueden borrar en cuanto GitHub Pages publique desde la acción `publicar-web.yml`.

## Verlo en local

```bash
python3 scripts/propuestas.py   # genera web/propuestas.json (sin GITHUB_TOKEN no toca GitHub)
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
