-- Base de datos SQLite del servidor PHP (api.canarias.africa). Solo guarda lo que no puede ir en GitHub:
-- etiquetas QR (su código no se publica), cuentas de colaboradores y todo lo que envía el público.
-- Las tablas de hechos (asignaciones, colocaciones, verificaciones, qr_eventos, eventos_merito) son de solo añadir;
-- qr_codigos.estado y colaboradores.meritos son resúmenes que se pueden recalcular desde ellas.

CREATE TABLE IF NOT EXISTS colaboradores (
  id          INTEGER PRIMARY KEY,
  alias       TEXT NOT NULL UNIQUE COLLATE NOCASE,
  correo      TEXT NOT NULL UNIQUE COLLATE NOCASE,
  verificado  INTEGER NOT NULL DEFAULT 0,
  custodia    INTEGER NOT NULL DEFAULT 0,      -- nivel Custodia por propuesta de la comunidad (además del que se gana por méritos)
  meritos     INTEGER NOT NULL DEFAULT 0,      -- suma de eventos_merito, la recalcula el servidor
  creado      TEXT NOT NULL DEFAULT (datetime('now'))
);

-- Enlaces de entrada por correo (un solo uso) y sesiones. Solo se guarda el hash del token.
CREATE TABLE IF NOT EXISTS tokens (
  hash         TEXT PRIMARY KEY,
  tipo         TEXT NOT NULL CHECK (tipo IN ('enlace','sesion')),
  colaborador  INTEGER NOT NULL REFERENCES colaboradores(id),
  vence        TEXT NOT NULL,
  usado        INTEGER NOT NULL DEFAULT 0
);

-- Catálogo de obras: la fuente es este servidor (se edita en /admin/obras) y se publica en /datos/obras.geojson.
-- lat/lon son siempre exactos aquí; la publicación los oculta según la sensibilidad (difusa → celda de 2 km, reservada → nada).
-- Las columnas que faltan en una base de datos antigua las añade migrar_catalogo().
CREATE TABLE IF NOT EXISTS obras (
  uuid          TEXT PRIMARY KEY,
  titulo        TEXT,
  sensibilidad  TEXT NOT NULL DEFAULT 'reservada'
);

-- Cada vez que se edita una obra se guarda aquí cómo estaba: es su árbol de versiones.
CREATE TABLE IF NOT EXISTS obras_versiones (
  id       INTEGER PRIMARY KEY,
  obra     TEXT NOT NULL,
  version  INTEGER NOT NULL,
  datos    TEXT NOT NULL,
  autor    TEXT NOT NULL DEFAULT 'admin',
  fecha    TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS obras_versiones_obra ON obras_versiones(obra, version);

-- Rutas curatoriales: paradas = UUID de obras en orden, separados por comas. trazado = LineString GeoJSON opcional.
CREATE TABLE IF NOT EXISTS rutas (
  id           TEXT PRIMARY KEY,
  nombre       TEXT NOT NULL,
  descripcion  TEXT NOT NULL DEFAULT '',
  curaduria    TEXT NOT NULL DEFAULT '',
  paradas      TEXT NOT NULL DEFAULT '',
  trazado      TEXT NOT NULL DEFAULT '',
  publicada    INTEGER NOT NULL DEFAULT 0,
  actualizado  TEXT NOT NULL DEFAULT (datetime('now'))
);

-- Galería de artistas: solo con su consentimiento. El punto es opcional y solo para un espacio público suyo
-- (taller abierto, galería, tienda); nunca su domicilio. Sin punto, se publica solo el municipio.
CREATE TABLE IF NOT EXISTS artistas (
  id              TEXT PRIMARY KEY,
  nombre          TEXT NOT NULL,
  disciplina      TEXT NOT NULL DEFAULT '',
  municipio       TEXT NOT NULL DEFAULT '',
  bio             TEXT NOT NULL DEFAULT '',
  web             TEXT NOT NULL DEFAULT '',
  tienda          TEXT NOT NULL DEFAULT '',
  mecenazgo       TEXT NOT NULL DEFAULT '',
  redes           TEXT NOT NULL DEFAULT '',
  foto            TEXT NOT NULL DEFAULT '',
  foto_credito    TEXT NOT NULL DEFAULT '',
  espacio         TEXT NOT NULL DEFAULT '',
  lat             REAL,
  lon             REAL,
  obras           TEXT NOT NULL DEFAULT '',
  consentimiento  TEXT NOT NULL DEFAULT '',
  publicada       INTEGER NOT NULL DEFAULT 0,
  actualizado     TEXT NOT NULL DEFAULT (datetime('now'))
);

-- Obras nuevas propuestas al colocar una etiqueta, a la espera de entrar en el catálogo de GitHub.
CREATE TABLE IF NOT EXISTS obras_propuestas (
  uuid         TEXT PRIMARY KEY,
  colaborador  INTEGER NOT NULL REFERENCES colaboradores(id),
  datos        TEXT NOT NULL,
  estado       TEXT NOT NULL DEFAULT 'pendiente' CHECK (estado IN ('pendiente','aceptada','rechazada')),
  creado       TEXT NOT NULL DEFAULT (datetime('now'))
);

-- Soportes: lo que identifica una etiqueta QR (una pared, una roca, un banco… o una web). Un soporte puede tener
-- varias obras a la vez o unas encima de otras con el tiempo; obra_soporte guarda desde y hasta cuándo.
CREATE TABLE IF NOT EXISTS soportes (
  uuid         TEXT PRIMARY KEY,
  nombre       TEXT NOT NULL DEFAULT '',
  tipo         TEXT NOT NULL DEFAULT '',
  sensibilidad TEXT NOT NULL DEFAULT 'abierta',
  lat          REAL,                            -- opcional: una web no tiene punto
  lon          REAL,
  isla         TEXT NOT NULL DEFAULT '',
  municipio    TEXT NOT NULL DEFAULT '',
  lugar        TEXT NOT NULL DEFAULT '',
  url          TEXT NOT NULL DEFAULT '',
  descripcion  TEXT NOT NULL DEFAULT '',
  creado       TEXT NOT NULL DEFAULT (datetime('now')),
  actualizado  TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE TABLE IF NOT EXISTS obra_soporte (
  id       INTEGER PRIMARY KEY,
  obra     TEXT NOT NULL,
  soporte  TEXT NOT NULL REFERENCES soportes(uuid),
  desde    TEXT NOT NULL DEFAULT '',
  hasta    TEXT NOT NULL DEFAULT '',                -- vacía: la obra sigue en ese soporte
  nota     TEXT NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS obra_soporte_obra ON obra_soporte(obra);
CREATE INDEX IF NOT EXISTS obra_soporte_soporte ON obra_soporte(soporte);

CREATE TABLE IF NOT EXISTS qr_codigos (
  codigo      TEXT PRIMARY KEY,                -- base32 de Crockford, 8 caracteres al empezar (ampliable)
  lote        TEXT NOT NULL,
  estado      TEXT NOT NULL DEFAULT 'libre' CHECK (estado IN
                ('libre','asignado','caducado','colocado','confirmado','sin_verificar','desaparecida','danado','retirado','sustituido')),
  obra        TEXT,                            -- obra que se eligió al colocarla (compatibilidad); lo que cuenta es el soporte
  lat         REAL,                            -- punto exacto de la etiqueta: nunca se publica para obras difusas
  lon         REAL,
  precision   REAL,
  soporte     TEXT,                            -- soporte que identifica la etiqueta (ver soportes)
  sustituye   TEXT REFERENCES qr_codigos(codigo),
  ultima_verificacion TEXT,
  creado      TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS qr_estado ON qr_codigos(estado);
CREATE INDEX IF NOT EXISTS qr_obra ON qr_codigos(obra);

CREATE TABLE IF NOT EXISTS asignaciones (
  id           INTEGER PRIMARY KEY,
  qr           TEXT NOT NULL REFERENCES qr_codigos(codigo),
  colaborador  INTEGER NOT NULL REFERENCES colaboradores(id),
  fecha        TEXT NOT NULL DEFAULT (datetime('now')),
  vence        TEXT NOT NULL,
  ampliada     INTEGER NOT NULL DEFAULT 0,
  descargada   INTEGER NOT NULL DEFAULT 0,     -- si ya se descargaron las etiquetas para imprimir
  resultado    TEXT                            -- NULL mientras está abierta; colocado, caducado o devuelto
);
CREATE INDEX IF NOT EXISTS asignaciones_abiertas ON asignaciones(colaborador, resultado);

CREATE TABLE IF NOT EXISTS colocaciones (
  id           INTEGER PRIMARY KEY,
  qr           TEXT NOT NULL REFERENCES qr_codigos(codigo),
  obra         TEXT NOT NULL,
  colaborador  INTEGER NOT NULL REFERENCES colaboradores(id),
  lat          REAL NOT NULL,
  lon          REAL NOT NULL,
  precision    REAL NOT NULL,
  foto         TEXT NOT NULL,
  fecha        TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS verificaciones (
  id           INTEGER PRIMARY KEY,
  qr           TEXT NOT NULL REFERENCES qr_codigos(codigo),
  colaborador  INTEGER REFERENCES colaboradores(id),   -- NULL si es un visitante sin cuenta
  dispositivo  TEXT NOT NULL,                          -- identificador aleatorio que guarda el navegador
  distancia    REAL NOT NULL,                          -- metros al punto de la etiqueta; las coordenadas de la persona no se guardan
  precision    REAL NOT NULL,
  foto         TEXT,
  peso         REAL NOT NULL,
  valida       INTEGER NOT NULL,
  motivo       TEXT,
  fecha        TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS verificaciones_qr ON verificaciones(qr, fecha);

-- Historial público de cada etiqueta: un evento por cambio (asignada, colocada, verificada, confirmada, dañada…).
CREATE TABLE IF NOT EXISTS qr_eventos (
  id           INTEGER PRIMARY KEY,
  qr           TEXT NOT NULL REFERENCES qr_codigos(codigo),
  tipo         TEXT NOT NULL,
  colaborador  INTEGER REFERENCES colaboradores(id),
  datos        TEXT NOT NULL DEFAULT '{}',
  fecha        TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS qr_eventos_qr ON qr_eventos(qr, fecha);

CREATE TABLE IF NOT EXISTS eventos_merito (
  id           INTEGER PRIMARY KEY,
  colaborador  INTEGER NOT NULL REFERENCES colaboradores(id),
  tipo         TEXT NOT NULL,
  puntos       INTEGER NOT NULL,
  referencia   TEXT,
  fecha        TEXT NOT NULL DEFAULT (datetime('now')),
  UNIQUE (colaborador, tipo, referencia)
);

-- Formularios de texto (observación, contexto, obra nueva, ruta, inscripción).
CREATE TABLE IF NOT EXISTS envios (
  id          INTEGER PRIMARY KEY,
  formulario  TEXT NOT NULL,
  obra        TEXT,
  datos       TEXT NOT NULL,
  fotos       TEXT NOT NULL DEFAULT '[]',
  estado      TEXT NOT NULL DEFAULT 'pendiente' CHECK (estado IN ('pendiente','aprobada','rechazada')),
  ip_hash     TEXT,
  creado      TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS envios_estado ON envios(formulario, estado);
CREATE INDEX IF NOT EXISTS envios_obra ON envios(obra, estado);

-- Vínculos de personas (decisión del 01-11-2026): las líneas temporales y de lugares de las personas no son
-- identificables en la red pública. Solo las ven, con nombre, quienes forman parte de cada vínculo, salvo que
-- todas las partes decidan publicarlo. Al público solo llega su número, que la ficha dibuja en discontinuo.
--   persona ↔ persona: familia, amistad, colaboracion… (la otra persona tiene que aceptarlo)
--   persona ↔ obra: colaboracion (rol: registro, mapeo, foto, documentación, cuidado…), participacion, recuerdo…
CREATE TABLE IF NOT EXISTS vinculos (
  id           INTEGER PRIMARY KEY,
  tipo         TEXT NOT NULL,
  de           INTEGER NOT NULL REFERENCES colaboradores(id),   -- quien lo propone
  a            INTEGER REFERENCES colaboradores(id),            -- la otra persona (NULL si es un vínculo con una obra)
  obra         TEXT,                                            -- uuid de la obra (NULL si es entre personas)
  rol          TEXT,
  nota         TEXT,
  estado       TEXT NOT NULL DEFAULT 'propuesto' CHECK (estado IN ('propuesto','aceptado','rechazado','retirado')),
  publico_de   INTEGER NOT NULL DEFAULT 0,                      -- consentimiento de cada parte para publicarlo
  publico_a    INTEGER NOT NULL DEFAULT 0,
  creado       TEXT NOT NULL DEFAULT (datetime('now')),
  cambiado     TEXT NOT NULL DEFAULT (datetime('now')),
  CHECK ((a IS NULL) <> (obra IS NULL))
);
CREATE INDEX IF NOT EXISTS vinculos_obra ON vinculos(obra);
CREATE INDEX IF NOT EXISTS vinculos_de ON vinculos(de);
CREATE INDEX IF NOT EXISTS vinculos_a ON vinculos(a);

-- Huellas de espectadores (01-11-2026): quien mira, escanea, verifica u observa una obra también se convierte en
-- trazo de ella. Solo se traza con la sesión iniciada ('visita', 'escaneo'), y solo se guardan recuentos por obra, día
-- y tipo: ni quién, ni IP. Las visitas sin sesión ('anonima') no son trazo: solo alimentan el mapa de calor agregado.
-- huellas_vistas evita contar dos veces la misma visita en el día; guarda un hash con sal diaria y el cron la vacía.
CREATE TABLE IF NOT EXISTS huellas (
  obra   TEXT NOT NULL,
  dia    TEXT NOT NULL,
  tipo   TEXT NOT NULL CHECK (tipo IN ('visita','escaneo','anonima')),
  n      INTEGER NOT NULL DEFAULT 0,
  PRIMARY KEY (obra, dia, tipo)
);
CREATE TABLE IF NOT EXISTS huellas_vistas (
  hash   TEXT PRIMARY KEY,
  dia    TEXT NOT NULL
);

-- Lista de correo de la página de lanzamiento («Quiero participar») y votos de las propuestas.
-- Lo enviado espera en «pendiente» hasta que la persona abre el enlace de confirmación (solo se guarda su hash).
-- «baja» es el token de los enlaces para darse de baja: solo sirve para eso.
CREATE TABLE IF NOT EXISTS participantes (
  id               INTEGER PRIMARY KEY,
  correo           TEXT NOT NULL UNIQUE COLLATE NOCASE,
  alias            TEXT NOT NULL DEFAULT '',
  isla             TEXT NOT NULL DEFAULT '',
  perfil           TEXT NOT NULL DEFAULT '',
  estado           TEXT NOT NULL DEFAULT 'pendiente' CHECK (estado IN ('pendiente','confirmada','baja')),
  pendiente        TEXT,
  confirmar_hash   TEXT UNIQUE,
  confirmar_vence  TEXT,
  baja             TEXT NOT NULL UNIQUE,
  creado           TEXT NOT NULL DEFAULT (datetime('now')),
  confirmado       TEXT,
  actualizado      TEXT
);

CREATE TABLE IF NOT EXISTS participa_votos (
  participante  INTEGER NOT NULL REFERENCES participantes(id),
  propuesta     TEXT NOT NULL,
  voto          TEXT NOT NULL CHECK (voto IN ('prioritaria','interesante','no')),
  PRIMARY KEY (participante, propuesta)
);

CREATE TABLE IF NOT EXISTS participa_ideas (
  id            INTEGER PRIMARY KEY,
  participante  INTEGER NOT NULL REFERENCES participantes(id),
  texto         TEXT NOT NULL,
  estado        TEXT NOT NULL DEFAULT 'nueva' CHECK (estado IN ('nueva','pasada','descartada')),
  creado        TEXT NOT NULL DEFAULT (datetime('now'))
);

-- Registro de envíos del formulario para limitar por IP y por correo. El cron borra las filas de más de 30 días.
CREATE TABLE IF NOT EXISTS participa_envios (
  correo   TEXT NOT NULL,
  ip_hash  TEXT,
  creado   TEXT NOT NULL DEFAULT (datetime('now'))
);

-- Registro de acciones delicadas en /admin (por ahora, descargas de la copia de la base de datos).
CREATE TABLE IF NOT EXISTS admin_registro (
  id       INTEGER PRIMARY KEY,
  accion   TEXT NOT NULL,
  detalle  TEXT NOT NULL DEFAULT '',
  ip       TEXT NOT NULL DEFAULT '',
  agente   TEXT NOT NULL DEFAULT '',
  fecha    TEXT NOT NULL DEFAULT (datetime('now'))
);
