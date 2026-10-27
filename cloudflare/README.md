# canarias.africa en Cloudflare (plan gratuito)

Un solo Worker sirve la web (`../web`), la API, los QR, los formularios, la moderación, la caché de teselas de OSM y la copia horaria de uMap. Usa D1 (base de datos), R2 (fotos) y KV (capas de uMap). Coste: 0 € dentro de los límites gratuitos (100.000 peticiones al día, 5 GB en D1, 10 GB en R2).

Es un borrador sin desplegar. Hay que probarlo con `wrangler dev` antes de publicarlo.

## Puesta en marcha

1. Crea una cuenta en Cloudflare y añade el dominio `canarias.africa`. Cloudflare te dará dos servidores de nombres, que se cambian en el registrador.
2. Instala wrangler (`npm i -g wrangler`) e inicia sesión con `wrangler login`.
3. Crea los recursos y copia los identificadores en `wrangler.toml`:
   ```bash
   wrangler d1 create canarias-africa
   wrangler kv namespace create UMAP
   wrangler r2 bucket create canarias-fotos
   wrangler d1 execute canarias-africa --remote --file=schema.sql
   ```
4. Moderación: en Zero Trust → Access, crea una aplicación para `canarias.africa/admin` y `canarias.africa/api/admin`, permite solo tu correo y rellena `ACCESS_EQUIPO`, `ACCESS_AUD` y `ADMINS`.
5. Publica: `wrangler deploy`.
6. Correo: activa Email Routing (hola@ → tu Gmail) y verifica el dominio en Brevo para los envíos.

## Rutas

| Ruta | Qué hace |
|---|---|
| `GET /api/publico/obras` | Obras publicadas, con coordenadas ofuscadas (abierta / zona de 0,02° / solo municipio) |
| `GET /api/publico/obras/<código>` | Ficha: descripción, relaciones y observaciones aprobadas |
| `GET /q/<código>` | QR impreso: redirige a `#/observar/<obra>?qr=<código>` si el código está activo |
| `POST /api/collections/<colección>/records` | Formularios: `observaciones`, `contexto`, `propuestas_obra`, `propuestas_ruta`, `inscripciones` |
| `GET /teselas/osm/{z}/{x}/{y}.png` | Teselas de OSM en la caché de Cloudflare |
| `GET /datos/umap/{qr,rutas}.geojson` | Última copia de las capas de uMap |
| `/admin/` y `/api/admin/…` | Moderación, protegida con Cloudflare Access |

## Copias de seguridad

`wrangler d1 export canarias-africa --remote --output=copia.sql` saca la base de datos entera. D1 guarda además 30 días de historial que se pueden restaurar (Time Travel).
