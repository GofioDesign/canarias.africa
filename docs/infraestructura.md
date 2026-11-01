# Infraestructura de canarias.africa

Decidido el 27 de octubre de 2026: empezar sin coste nuevo, con GitHub Pages y el hosting PHP que ya está contratado.

## Cómo se reparte

| Pieza | Dónde | Coste |
|---|---|---|
| Web: página de lanzamiento en la raíz, prototipo en `/prototipo/`, fichas de obras (catálogo público) | GitHub Pages, desde `web/` de este repositorio (`canarias.africa`) | 0 € |
| Propuestas del colectivo | `propuestas/*.md`; la acción las publica en `propuestas.json` y abre un issue de debate por propuesta (votos = reacciones) | 0 € |
| Lista de correo y votos de la web | Hosting PHP (SQLite), con doble confirmación y baja en cada correo | Ya pagado |
| Cuentas, etiquetas QR, formularios, observaciones aprobadas, moderación | Hosting PHP actual, en `api.canarias.africa` (`servidor-php/`) | Ya pagado |
| Caché de teselas OSM | Hosting PHP: `api.canarias.africa/teselas/osm/{z}/{x}/{y}.png` (máx. 300 MB) | Ya pagado |
| Catálogo (obras, QR, rutas, artistas) | Base de datos del hosting, editada en `api.canarias.africa/admin`; uMap solo lo muestra («Datos remotos») | 0 € |
| Correo saliente (avisos, enlaces y confirmaciones) | `mail()` del hosting con remitente `no-responder@api.canarias.africa` (SPF en Porkbun, sin MX); las respuestas van a `contacto`. Brevo si hace falta más | 0 € |
| Foro de artistas | Grupo privado (Signal o Telegram) hasta que haya presupuesto para Discourse | 0 € |
| Copias | Diarias de la base de datos en el hosting (se guardan 7) + descarga manual desde `/admin` | 0 € |

Nada pasa por el proxy de Cloudflare: las operadoras españolas bloquean IP de Cloudflare durante los partidos de LaLiga (sigue ocurriendo en 2026).

## Reglas que no se pueden saltar

- **El repositorio es público.** Las coordenadas exactas de obras «difusa» o «reservada» no se suben nunca a GitHub. En el catálogo, una obra difusa lleva el centro de su celda de 0,02° (`floor(v / 0.02) * 0.02 + 0.01`) y una reservada no lleva geometría. La acción de GitHub `scripts/comprobar-catalogo.py` bloquea la publicación si alguna lo incumple.
- **Los códigos de las etiquetas no van en GitHub ni se publican en la web.** Son 8 caracteres en base32 de Crockford (p. ej. `7KQ2M9XD`), ampliables más adelante. Viven solo en la base de datos del hosting. El UUID de la obra sí es público. Si el código de la etiqueta fuera público, cualquiera podría observar o verificar sin estar delante de la obra. El ciclo completo (pool, cupos, colocación con GPS, verificaciones, méritos) está en [procedimiento-colaboradores-qr.md](procedimiento-colaboradores-qr.md).
- Cada azulejo lleva un glifo propio con su código y un QR común a `https://canarias.africa/scan` (`web/scan/index.html` → `#scan`), donde la web lee el glifo con la cámara. El código también se puede teclear como `canarias.africa/<código>`: GitHub Pages sirve `web/404.html`, que lo pasa a `#/q/<código>`.
- Todo envío entra como pendiente, exige aceptar el aviso de privacidad y las fotos se reducen a 1600 px y pierden los metadatos (incluida la ubicación GPS).
- Solo `https://canarias.africa` puede enviar formularios (CORS y comprobación de origen), con un máximo de 20 envíos por hora y por IP.

## Rutas de api.canarias.africa

| Ruta | Qué hace |
|---|---|
| `POST /api/participa` | «Quiero participar»: correo, alias, isla, perfil, votos e ideas. Queda pendiente y envía un correo de confirmación (`#confirmar?t=…`, 7 días). Límite: 20 envíos por hora e IP, 3 correos al día por dirección |
| `POST /api/participa/confirmar` | Confirma el correo y aplica lo pendiente: en cada propuesta cuenta el último voto confirmado |
| `GET /api/participa/resultados` | `{participantes, ideas: {<id>: {prioritaria, interesante, no}}}`, solo de altas confirmadas |
| `GET`/`POST /api/participa/baja?t=…` | Baja de la lista: el GET muestra un botón y el POST la hace (también la baja en un clic de los clientes de correo) |
| `POST /api/cuenta/alta`, `/api/cuenta/entrar` | Alta con alias y correo, o entrada: envía un enlace por correo (sin contraseñas) |
| `POST /api/cuenta/sesion` | Canjea el enlace del correo (`#/entrar?t=…`) por un token de sesión de 90 días (`Authorization: Bearer`) |
| `GET /api/cuenta/yo` | Alias, nivel, méritos, cupo y etiquetas asignadas |
| `GET /api/qr/<código>` | Al leer la etiqueta: `{soporte, obras, obra, estado}` (la etiqueta identifica un soporte, que puede ser una web) |
| `POST /api/qr/asignar`, `GET /api/qr/mis-etiquetas` | Pedir etiquetas del pool hasta el cupo y descargarlas para imprimir |
| `POST /api/qr/<código>/ampliar`, `POST /api/qr/<código>/aviso` | Ampliar el plazo una vez; avisar de etiqueta dañada, desaparecida o retirada |
| `POST /api/collections/colocaciones/records` | Colocar: etiqueta, soporte (existente o nuevo), obra (o obra nueva), GPS con 25 m o mejor y foto |
| `POST /api/collections/verificaciones/records` | Verificar: GPS a 50 m o menos del punto; solo se guarda la distancia |
| `POST /api/collections/<colección>/records` | Formularios: `observaciones` (exige el código de la etiqueta), `contexto`, `propuestas_obra`, `propuestas_ruta`, `inscripciones` |
| `GET /api/publico/obras/<uuid>` | Observaciones aprobadas de esa obra |
| `GET /api/publico/qr/<uuid-obra>/historial` | Historial público de las etiquetas de una obra (sin códigos de etiqueta ni coordenadas de personas) |
| `GET /api/publico/etiquetas.geojson` | Etiquetas confirmadas para el mapa (difusas en su celda de 0,02°) |
| `GET /teselas/osm/{z}/{x}/{y}.png` | Teselas de OSM desde la caché |
| `GET /datos/{obras,qr,rutas,artistas}.geojson` | Catálogo público (lo leen la web y uMap); `/datos/umap/…` es el nombre antiguo |
| `GET /datos/fotos/AAAA-MM/<id>.jpg` | Fotos subidas desde `/admin`, solo si una obra o artista publicado las usa |
| `/admin` | Moderación, pool de etiquetas, personas (nivel Custodia), obras propuestas, lista y votos del lanzamiento (con CSV e ideas nuevas) y descarga de la base de datos (con contraseña) |
| `/admin/campo` | App «En campo» (instalable en el móvil): registra obras sin cobertura y las sube como borradores sin publicar cuando vuelve la red |

## Puesta en marcha

1. **GitHub Pages:** en *Settings → Pages*, poner *Source: GitHub Actions* y el dominio `canarias.africa` con *Enforce HTTPS*. La acción `.github/workflows/publicar-web.yml` publica `web/` cada vez que cambia `main` y cada 6 horas (para refrescar los votos de GitHub); antes genera `propuestas.json` y abre los issues de debate de las propuestas nuevas con la etiqueta `propuesta`.
2. **DNS** (en Porkbun, que gestiona la zona DNS; el dominio está registrado en Regery, caduca el 2 de octubre de 2028 (renovado en octubre de 2026) y el .africa no tiene periodo de gracia, así que hay que activar la renovación automática en Regery):

   | Nombre | Tipo | Valor |
   |---|---|---|
   | `@` | A | 185.199.108.153, 185.199.109.153, 185.199.110.153, 185.199.111.153 (ya están, 27-10-2026) |
   | `@` | AAAA | 2606:50c0:8000::153, 2606:50c0:8001::153, 2606:50c0:8002::153, 2606:50c0:8003::153 (ya están) |
   | `www` | CNAME | `gofiodesign.github.io` |
   | `api` | A o CNAME | El que indique el hosting para el subdominio |
   | `api` | TXT | `v=spf1 a mx ip4:51.91.107.140 ~all` (SPF que da el panel del hosting: `a` = la IP de la web, 51.91.107.140 = su servidor de correo saliente; autoriza a enviar como `no-responder@api.canarias.africa`) |
   | `default._domainkey.api` (o el nombre que dé el panel) | TXT | La clave DKIM que genera DirectAdmin para `api.canarias.africa` (opcional, mejora la entrega) |
   | `_github-pages-challenge-GofioDesign` | TXT | El que dé GitHub al verificar el dominio (evita que otra cuenta lo use en Pages) |

3. **Hosting:** crear el subdominio `api.canarias.africa` con certificado SSL y con su carpeta pública apuntando a `servidor-php/publico/`. Subir `servidor-php/privado/` fuera de la carpeta pública si el hosting lo permite (si no, su `.htaccess` la bloquea). Copiar `privado/config.ejemplo.php` como `config.php` y rellenarlo. Programar cada hora: `php …/servidor-php/cron/tareas.php`.
4. Requisitos del hosting: PHP 8.1 o superior con PDO SQLite, GD y cURL, y Apache o LiteSpeed con `mod_rewrite`. No hace falta MySQL.

## App «En campo» (sin cobertura)

`api.canarias.africa/admin/campo` se puede instalar en el móvil («Añadir a la pantalla de inicio»). Funciona así:

1. Con conexión, se entra en `/admin` una vez: el móvil guarda la página para poder abrirla sin red.
2. En campo, cada obra (título, foto, punto GPS con su precisión, municipio, sensibilidad, datos desconocidos y una nota) se guarda **en el propio móvil**. La foto se reduce a 2000 px en el teléfono.
3. Al volver la red, la cola se sube sola: al abrir la página o, en Android, aunque esté cerrada. También se puede pulsar «Subir ahora».
4. Cada obra entra **sin publicar** y con la nota y la precisión del GPS en «Fuentes». Desde la lista se abre en `/admin/obra` para completarla con el mapa.

Si la sesión ha caducado, la cola espera: se vuelve a entrar y se sube. Cada obra lleva un identificador creado en el móvil, así que una subida repetida no crea duplicados. Lo que el servidor rechaza (por ejemplo, unas coordenadas fuera de Canarias) queda marcado «Hay que revisarla» con el motivo, para editarlo y volver a guardarlo.

## Espacio (1000 MB)

Caché de teselas: hasta 300 MB, recortada por el cron. Fotos: unos 300 KB cada una tras reducirlas, así que caben unas 2.000 antes de tener que moverlas. Base de datos y copias: unos pocos MB.

## Más adelante

Si el proyecto crece (Discourse, mucho tráfico, más fotos): un VPS propio. La configuración para eso (nginx + PocketBase) está en `servidor/` como plan B.
