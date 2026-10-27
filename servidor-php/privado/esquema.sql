-- Base de datos SQLite del servidor PHP. Solo guarda lo que no puede ir en GitHub:
-- códigos de QR (si fueran públicos, cualquiera podría observar sin estar en la obra) y envíos del público.
CREATE TABLE IF NOT EXISTS qr (
  codigo  TEXT PRIMARY KEY,
  obra    TEXT NOT NULL,             -- código público de la obra (CA-…)
  activo  INTEGER NOT NULL DEFAULT 1,
  creado  TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE TABLE IF NOT EXISTS envios (
  id          INTEGER PRIMARY KEY,
  formulario  TEXT NOT NULL,         -- observacion, contexto, obra_nueva, ruta, inscripcion
  obra        TEXT,
  datos       TEXT NOT NULL,         -- JSON con los campos tal como llegan
  fotos       TEXT NOT NULL DEFAULT '[]',
  estado      TEXT NOT NULL DEFAULT 'pendiente' CHECK (estado IN ('pendiente','aprobada','rechazada')),
  ip_hash     TEXT,                  -- solo para limitar abusos; se borra a los 30 días
  creado      TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS envios_estado ON envios(formulario, estado);
CREATE INDEX IF NOT EXISTS envios_obra ON envios(obra, estado);
