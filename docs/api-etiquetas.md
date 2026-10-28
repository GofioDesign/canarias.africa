# API de etiquetas y cuentas (api.canarias.africa)

**Códigos de etiqueta (28-10-2026):** 8 caracteres en base32 de Crockford (`0123456789ABCDEFGHJKMNPQRSTVWXYZ`), p. ej. `7KQ2M9XD`. Se pueden emitir lotes más largos, de hasta 16 caracteres, sin cambiar nada más. El servidor normaliza: mayúsculas, sin espacios ni guiones, O→0, I/L→1. Las obras siguen con UUID. Donde abajo pone `<uuid>` de etiqueta, va el código; el `<uuid-obra>` sigue siendo UUID.

Servidor PHP + SQLite (no PocketBase). Rutas compatibles con lo que ya usa la web. Errores: `{error}` en español, listos para mostrar.

## Cuentas (sin contraseñas)
- `POST /api/cuenta/alta {alias, correo, acepta_privacidad}` y `POST /api/cuenta/entrar {correo}` → envían un enlace a `https://canarias.africa/#/entrar?t=TOKEN`.
- `POST /api/cuenta/sesion {t}` → `{token, alias, nivel, meritos, cupo, disponibles, etiquetas:[{qr, vence, descargada, estado}]}`. Guardar `token` y enviarlo como `Authorization: Bearer <token>` (90 días).
- `GET /api/cuenta/yo` (auth) → el mismo perfil.
- Cuerpos JSON.

## Etiquetas
- `GET /api/qr/<uuid>` → `{obra, estado}`. Estados: libre, asignado, caducado, colocado, confirmado, sin_verificar, desaparecida, danado, retirado, sustituido. Si no existe, 404 con `estado: "desconocido"`. La observación vale con colocado, confirmado, sin_verificar o desaparecida.
- `POST /api/qr/asignar {cantidad}` (auth) → perfil.
- `GET /api/qr/mis-etiquetas` (auth) → `[{codigo, url, vence}]`. Al pedirlas quedan marcadas como descargadas (opción a). La hoja de etiquetas la genera el front.
- `POST /api/qr/<uuid>/ampliar` (auth, una sola vez).
- `POST /api/qr/<uuid>/aviso {tipo: danado|desaparecida|retirado, texto}`.

## Colocar (auth, multipart)
`POST /api/collections/colocaciones/records`: `qr`, `obra` (UUID del catálogo) o, para obra nueva, `obra_nueva_titulo`, `obra_nueva_sensibilidad` y cualquier otro `obra_nueva_*`; `lat`, `lon`, `precision` (m, ≤ 25), `foto` (obligatoria) y `acepta_derechos=1`. Responde `{ok, obra, estado}`. Si es una obra nueva, el UUID lo genera el servidor.
Solo admite obras que estén en `/datos/obras.geojson` (el cron lo copia cada hora). Las obras reservadas no admiten etiqueta.

## Verificar (cuenta opcional, multipart)
`POST /api/collections/verificaciones/records`: `qr`, `lat`, `lon`, `precision`, `dispositivo` (id aleatorio de 16 a 64 caracteres `[A-Za-z0-9_-]` guardado en localStorage) y `foto` opcional.
Responde `{ok, valida, motivo: null|fuera_del_radio|gps_impreciso, distancia_m, estado}`.
- 409 si ya se verificó este mes desde esa cuenta o dispositivo.
- 403 si quien verifica es quien la colocó.

## Públicos
- `GET /api/publico/qr/<uuid-obra>/historial` → `{obra, etiquetas:[{etiqueta, estado, ultima_verificacion, confianza, eventos:[{tipo, fecha, alias?, distancia_m?, motivo?}]}]}`. No incluye el código de la etiqueta ni coordenadas de personas.
- `GET /api/publico/etiquetas.geojson` → etiquetas confirmadas o sin verificar, para el mapa. Las de obras difusas van en el centro de su celda.
- `GET /api/publico/obras/<uuid>` → observaciones aprobadas.

## Observación
La observación sigue enviando `obra` y `qr_codigo` (el código de la etiqueta).

## GitHub Pages
- `canarias.africa/<código>` (de 8 a 16 caracteres, admite guiones y minúsculas) → `404.html` → `/#/q/<CÓDIGO>`.
- `canarias.africa/scan` (el QR común de los azulejos) → `scan/index.html` → `/#scan`.
