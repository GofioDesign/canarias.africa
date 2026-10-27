# Infraestructura de canarias.africa

Decidido el 27 de octubre de 2026: empezar sin coste nuevo, con GitHub Pages y el hosting PHP que ya está contratado.

## Cómo se reparte

| Pieza | Dónde | Coste |
|---|---|---|
| Web y fichas de obras (catálogo público) | GitHub Pages, desde `web/` de este repositorio (`canarias.africa`) | 0 € |
| Formularios, QR, observaciones aprobadas, moderación | Hosting PHP actual, en `api.canarias.africa` (`servidor-php/`) | Ya pagado |
| Caché de teselas OSM | Hosting PHP: `api.canarias.africa/teselas/osm/{z}/{x}/{y}.png` (máx. 300 MB) | Ya pagado |
| Capas de uMap (QR, rutas) | uMap público; el cron del hosting las copia cada hora a `api.canarias.africa/datos/umap/` | 0 € |
| Correo saliente (avisos) | `mail()` del hosting; Brevo si hace falta más | 0 € |
| Foro de artistas | Grupo privado (Signal o Telegram) hasta que haya presupuesto para Discourse | 0 € |
| Copias | Diarias de la base de datos en el hosting (se guardan 7) + descarga manual desde `/admin` | 0 € |

Nada pasa por el proxy de Cloudflare: las operadoras españolas bloquean IP de Cloudflare durante los partidos de LaLiga (sigue ocurriendo en 2026).

## Reglas que no se pueden saltar

- **El repositorio es público.** Las coordenadas exactas de obras «difusa» o «reservada» no se suben nunca a GitHub. En el catálogo, una obra difusa lleva el centro de su celda de 0,02° (`floor(v / 0.02) * 0.02 + 0.01`) y una reservada no lleva geometría. La acción de GitHub `scripts/comprobar-catalogo.py` bloquea la publicación si alguna lo incumple.
- **Los códigos de los QR no van en GitHub.** Viven solo en la base de datos del hosting y se crean, rotan y retiran desde `/admin/qr`. Si fueran públicos, cualquiera podría enviar observaciones sin estar delante de la obra.
- Todo envío entra como pendiente, exige aceptar el aviso de privacidad y las fotos se reducen a 1600 px y pierden los metadatos (incluida la ubicación GPS).
- Solo `https://canarias.africa` puede enviar formularios (CORS y comprobación de origen), con un máximo de 20 envíos por hora y por IP.

## Rutas de api.canarias.africa

| Ruta | Qué hace |
|---|---|
| `GET /q/<código>` | Lo que lleva impreso cada QR: redirige a `canarias.africa/#/observar/<obra>?qr=<código>` si está activo |
| `POST /api/collections/<colección>/records` | Formularios: `observaciones`, `contexto`, `propuestas_obra`, `propuestas_ruta`, `inscripciones` |
| `GET /api/publico/obras/<código>` | Observaciones aprobadas de esa obra |
| `GET /teselas/osm/{z}/{x}/{y}.png` | Teselas de OSM desde la caché |
| `GET /datos/umap/{qr,rutas}.geojson` | Última copia de las capas de uMap |
| `/admin` | Moderación, códigos QR y descarga de la base de datos (con contraseña) |

## Puesta en marcha

1. **GitHub Pages:** en *Settings → Pages*, poner *Source: GitHub Actions* y el dominio `canarias.africa` con *Enforce HTTPS*. La acción `.github/workflows/publicar-web.yml` publica `web/` cada vez que cambia `main`.
2. **DNS** (en Porkbun, que gestiona la zona DNS; el dominio está registrado en Regery, caduca el 2 de octubre de 2028 (renovado en octubre de 2026) y el .africa no tiene periodo de gracia, así que hay que activar la renovación automática en Regery):

   | Nombre | Tipo | Valor |
   |---|---|---|
   | `@` | A | 185.199.108.153, 185.199.109.153, 185.199.110.153, 185.199.111.153 (ya están, 27-10-2026) |
   | `@` | AAAA | 2606:50c0:8000::153, 2606:50c0:8001::153, 2606:50c0:8002::153, 2606:50c0:8003::153 (ya están) |
   | `www` | CNAME | `gofiodesign.github.io` |
   | `api` | A o CNAME | El que indique el hosting para el subdominio |
   | `_github-pages-challenge-GofioDesign` | TXT | El que dé GitHub al verificar el dominio (evita que otra cuenta lo use en Pages) |

3. **Hosting:** crear el subdominio `api.canarias.africa` con certificado SSL y con su carpeta pública apuntando a `servidor-php/publico/`. Subir `servidor-php/privado/` fuera de la carpeta pública si el hosting lo permite (si no, su `.htaccess` la bloquea). Copiar `privado/config.ejemplo.php` como `config.php` y rellenarlo. Programar cada hora: `php …/servidor-php/cron/tareas.php`.
4. Requisitos del hosting: PHP 8.1 o superior con PDO SQLite, GD y cURL, y Apache o LiteSpeed con `mod_rewrite`. No hace falta MySQL.

## Espacio (1000 MB)

Caché de teselas: hasta 300 MB, recortada por el cron. Fotos: unos 300 KB cada una tras reducirlas, así que caben unas 2.000 antes de tener que moverlas. Base de datos y copias: unos pocos MB.

## Más adelante

Si el proyecto crece (Discourse, mucho tráfico, más fotos): un VPS propio. La configuración para eso (nginx + PocketBase) está en `servidor/` como plan B.
