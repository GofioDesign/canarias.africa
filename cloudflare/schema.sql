-- Base de datos D1 de canarias.africa. Aplicar con:
--   wrangler d1 execute canarias-africa --remote --file=schema.sql

-- Todos los nodos de la red (obra, agente, entidad, fuente…). Los tipos nuevos no cambian el esquema.
CREATE TABLE IF NOT EXISTS nodos (
  codigo        TEXT PRIMARY KEY,               -- CA-TF-ARI-0001, AG-…, nunca cambia
  tipo          TEXT NOT NULL,
  titulo        TEXT NOT NULL,
  descripcion   TEXT,
  municipio     TEXT,
  lat           REAL,                           -- coordenadas reales: nunca se publican sin pasar por coordenadasPublicas()
  lon           REAL,
  fase          TEXT,
  sensibilidad  TEXT NOT NULL DEFAULT 'reservada' CHECK (sensibilidad IN ('abierta','difusa','reservada')),
  publicado     INTEGER NOT NULL DEFAULT 0,
  datos         TEXT                            -- JSON con propiedades propias de cada tipo
);
CREATE INDEX IF NOT EXISTS nodos_tipo ON nodos(tipo, publicado);

CREATE TABLE IF NOT EXISTS relaciones (
  id       INTEGER PRIMARY KEY,
  origen   TEXT NOT NULL REFERENCES nodos(codigo),
  destino  TEXT NOT NULL REFERENCES nodos(codigo),
  tipo     TEXT NOT NULL,                       -- autoria, cura, financia, cita…
  certeza  TEXT,
  fecha    TEXT,
  fuente   TEXT
);
CREATE INDEX IF NOT EXISTS relaciones_origen ON relaciones(origen);
CREATE INDEX IF NOT EXISTS relaciones_destino ON relaciones(destino);

-- Códigos de los QR impresos. Rotar = desactivar el código y crear otro para la misma obra.
CREATE TABLE IF NOT EXISTS qr (
  codigo  TEXT PRIMARY KEY,
  obra    TEXT NOT NULL REFERENCES nodos(codigo),
  activo  INTEGER NOT NULL DEFAULT 1,
  creado  TEXT NOT NULL DEFAULT (datetime('now'))
);

-- Todo lo que envía el público (observación, contexto, obra nueva, ruta, inscripción) entra aquí
-- como pendiente. «formulario» dice de cuál se trata y «datos» guarda los campos tal como llegan.
CREATE TABLE IF NOT EXISTS envios (
  id          INTEGER PRIMARY KEY,
  formulario  TEXT NOT NULL,
  obra        TEXT,
  datos       TEXT NOT NULL,
  fotos       TEXT,                             -- claves en R2, JSON
  estado      TEXT NOT NULL DEFAULT 'pendiente' CHECK (estado IN ('pendiente','aprobada','rechazada')),
  creado      TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS envios_estado ON envios(formulario, estado);
CREATE INDEX IF NOT EXISTS envios_obra ON envios(obra, estado);
