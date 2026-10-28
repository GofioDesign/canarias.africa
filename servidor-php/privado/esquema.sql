-- Base de datos SQLite del servidor PHP (api.canarias.africa). Solo guarda lo que no puede ir en GitHub:
-- etiquetas QR (su UUID no se publica), cuentas de colaboradores y todo lo que envía el público.
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

-- Catálogo público copiado de GitHub por el cron: el servidor necesita saber qué obras existen y su sensibilidad.
CREATE TABLE IF NOT EXISTS obras (
  uuid          TEXT PRIMARY KEY,
  titulo        TEXT,
  sensibilidad  TEXT NOT NULL DEFAULT 'reservada'
);

-- Obras nuevas propuestas al colocar una etiqueta, a la espera de entrar en el catálogo de GitHub.
CREATE TABLE IF NOT EXISTS obras_propuestas (
  uuid         TEXT PRIMARY KEY,
  colaborador  INTEGER NOT NULL REFERENCES colaboradores(id),
  datos        TEXT NOT NULL,
  estado       TEXT NOT NULL DEFAULT 'pendiente' CHECK (estado IN ('pendiente','aceptada','rechazada')),
  creado       TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS qr_codigos (
  uuid        TEXT PRIMARY KEY,
  lote        TEXT NOT NULL,
  estado      TEXT NOT NULL DEFAULT 'libre' CHECK (estado IN
                ('libre','asignado','caducado','colocado','confirmado','sin_verificar','desaparecida','danado','retirado','sustituido')),
  obra        TEXT,
  lat         REAL,                            -- punto exacto de la etiqueta: nunca se publica para obras difusas
  lon         REAL,
  precision   REAL,
  sustituye   TEXT REFERENCES qr_codigos(uuid),
  ultima_verificacion TEXT,
  creado      TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS qr_estado ON qr_codigos(estado);
CREATE INDEX IF NOT EXISTS qr_obra ON qr_codigos(obra);

CREATE TABLE IF NOT EXISTS asignaciones (
  id           INTEGER PRIMARY KEY,
  qr           TEXT NOT NULL REFERENCES qr_codigos(uuid),
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
  qr           TEXT NOT NULL REFERENCES qr_codigos(uuid),
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
  qr           TEXT NOT NULL REFERENCES qr_codigos(uuid),
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
  qr           TEXT NOT NULL REFERENCES qr_codigos(uuid),
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
