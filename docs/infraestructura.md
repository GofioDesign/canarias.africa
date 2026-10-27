# Infraestructura de canarias.africa

Propuesta del 27 de octubre de 2026. Pensada para un proyecto sin financiación: un único servidor pequeño, programas libres y servicios gratuitos donde no hace falta pagar.

## Recomendación en una frase

Un VPS europeo de unos 5 €/mes (Hetzner CX22 o equivalente: 2 vCPU, 4 GB de RAM, 40 GB de disco) con Debian, nginx y PocketBase. El DNS va a Cloudflare (gratis), los mapas se editan en el uMap público de OSM Francia, el correo entrante se reenvía gratis a Gmail y el saliente sale por Brevo (300 correos al día gratis). El foro Discourse se monta en el mismo servidor en una segunda fase.

## Piezas

| Pieza | Elección | Coste | Por qué |
|---|---|---|---|
| Servidor | VPS Hetzner CX22 (Alemania o Finlandia), Debian | ≈ 5 €/mes (comprobar al contratar) | Datos en la UE (RGPD), aguanta web + API + caché de teselas + Discourse |
| DNS | Cloudflare, plan gratuito, solo DNS (nube gris) | 0 € | Gestión cómoda, reenvío de correo gratuito, sin cambiar de registrador |
| Dominio | canarias.africa, se queda en su registrador actual | Renovación anual del .africa | Solo se cambian los servidores de nombres |
| Web | HTML estático servido por nginx (`web/`) | 0 € | Es lo que ya es el prototipo |
| Teselas OSM | Caché de nginx en `/teselas/osm/`, máx. 5 GB | 0 € | Decidido; respeta la política de teselas de OSM |
| Nodos geográficos | uMap en umap.openstreetmap.fr, sincronizado cada hora a `/datos/umap/` | 0 € | Decidido; se puede autoalojar uMap más adelante |
| Base de datos y formularios | PocketBase (un binario, SQLite, panel de administración, subida de fotos) | 0 € | Muy ligero, una sola pieza que mantener; reglas en `servidor/pocketbase/pb_hooks` |
| Ofuscación de coordenadas | En PocketBase: `/api/publico/obras` publica abierta / zona de 0,02° / solo municipio | 0 € | Las coordenadas reales nunca salen del servidor |
| QR | `canarias.africa/q/<código>`; el código vive en la colección `qr` y se puede rotar | 0 € | La observación solo entra con un código activo |
| Foro de artistas | Discourse autoalojado en `foro.canarias.africa`, categoría privada (fase 2) | 0 € (mismo VPS; si va justo, subir a 8 GB ≈ +5 €/mes) | Propuesta del hilo de diseño |
| Correo entrante | Cloudflare Email Routing: hola@, foro@… → Gmail | 0 € | Sin buzones que mantener |
| Correo saliente | Brevo SMTP (formularios, Discourse, «enviar como» desde Gmail) | 0 € hasta 300/día | Ya tienes Brevo conectado |
| Copias de seguridad | restic cifrado, diario, a Cloudflare R2 o Backblaze B2 | 0 € (hasta 10 GB) | Guarda base de datos, fotos, configuración y copias de Discourse |
| Código | Este repositorio en GitHub | 0 € | |

**Coste total estimado:** unos 5 €/mes más la renovación del dominio. Unos 60 €/año.

## Alternativas que he descartado

- **Solo GitHub Pages (gratis):** sirve para la web estática, pero no permite la caché de teselas, los formularios ni la ofuscación en servidor. Se puede usar como fase 0 mientras se contrata el VPS.
- **Hosting compartido con PHP:** vale para web y formularios, pero no para Discourse y complica la caché de teselas.
- **Oracle Cloud gratuito:** gratis y potente, pero con riesgo de que cierren la cuenta sin aviso. No es buena base para un archivo que tiene que durar.
- **Supabase, Firebase y similares:** cómodos, pero los datos salen de tu servidor y los planes gratuitos cambian.

## Subdominios y registros DNS

| Nombre | Tipo | Valor |
|---|---|---|
| `canarias.africa` | A / AAAA | IP del VPS |
| `www` | CNAME | `canarias.africa` |
| `foro` | A / AAAA | IP del VPS (fase 2) |
| `@` | MX + TXT | Los que indique Cloudflare Email Routing |
| `@` | TXT (SPF) | `v=spf1 include:_spf.mx.cloudflare.net include:spf.brevo.com ~all` |
| `brevo._domainkey` y `_dmarc` | TXT / CNAME | Los que indique Brevo al verificar el dominio |

## Datos y RGPD

- Colecciones de PocketBase: `nodos` (todos los tipos, con `tipo`, `codigo`, `sensibilidad`, `lat`, `lon`, `municipio`, `publicado`), `relaciones` (`origen`, `destino`, `tipo`, `certeza`, `fecha`, `fuente`), `qr` (`codigo`, `obra`, `activo`), `observaciones`, `aportaciones` (contexto y obra nueva), `inscripciones` (salidas de mapeo).
- Toda aportación entra como `pendiente` y exige aceptar el aviso de privacidad.
- La lectura pública solo pasa por rutas que ya devuelven datos ofuscados. Las colecciones no tienen reglas de lectura pública.
- El panel de administración de PocketBase no se publica: se entra por túnel SSH.
- Falta redactar el aviso de privacidad y el registro de actividades de tratamiento (inscripciones con nombre y contacto). Responsable: Gofio Design hasta que exista la entidad.

## Fases

1. **Fase 0, sin coste:** prototipo en el repositorio; GitHub Pages opcional para enseñarlo.
2. **Fase 1, ≈ 5 €/mes:** VPS, DNS en Cloudflare, nginx con caché de teselas, PocketBase con formularios, sincronización de uMap, copias de seguridad, correo.
3. **Fase 2:** Discourse en `foro.canarias.africa`, con acceso solo por invitación.
4. **Más adelante:** uMap propio, teselas propias en PMTiles a partir de Geofabrik.

## Ficheros del repositorio

- `web/`: la web (prototipo actual, `convocatorias.json`, sellos de marca).
- `servidor/nginx/canarias.africa.conf`: sitio, HTTPS, caché de teselas, API y QR.
- `servidor/pocketbase/pb_hooks/`: ofuscación, QR y validación de formularios. Es un borrador sin probar.
- `servidor/scripts/`: sincronización de uMap y copias de seguridad, con sus temporizadores en `servidor/systemd/`.
- `servidor/servidor.env.ejemplo`: variables. El fichero real no se sube nunca.
- `datos/umap/`: GeoJSON de ejemplo para importar en uMap.
