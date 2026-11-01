<?php
// Catálogo de obras y rutas: la fuente de datos es este servidor (se edita en /admin/obras y /admin/rutas).
// Se publica en GeoJSON en /datos/obras.geojson, /datos/qr.geojson y /datos/rutas.geojson, que leen la web y uMap.
// uMap solo muestra: sus capas apuntan a esas URL como «datos remotos».

const FASES = ['gestacion', 'creacion', 'plenitud', 'desgaste', 'intervencion', 'transformacion', 'desaparicion', 'memoria'];
const SENSIBILIDADES = ['abierta', 'difusa', 'reservada'];
const CAMPOS_OBRA = ['titulo', 'sensibilidad', 'lat', 'lon', 'fase', 'municipio', 'lugar', 'tipo', 'descripcion', 'autoria',
  'fecha_obra', 'foto', 'foto_credito', 'ficha', 'publicada'];
const PASO_DIFUSA = 0.02;  // cuadrícula fija de ~2 km para las obras difusas

// Añade las columnas nuevas a una base de datos creada con un esquema anterior.
function migrar_catalogo(PDO $db): void {
  $hay = array_column($db->query('PRAGMA table_info(obras)')->fetchAll(), 'name');
  $nuevas = ['lat' => 'REAL', 'lon' => 'REAL', 'fase' => "TEXT NOT NULL DEFAULT 'plenitud'", 'municipio' => "TEXT NOT NULL DEFAULT ''",
    'lugar' => "TEXT NOT NULL DEFAULT ''", 'tipo' => "TEXT NOT NULL DEFAULT ''", 'descripcion' => "TEXT NOT NULL DEFAULT ''",
    'autoria' => "TEXT NOT NULL DEFAULT ''", 'fecha_obra' => "TEXT NOT NULL DEFAULT ''", 'foto' => "TEXT NOT NULL DEFAULT ''",
    'foto_credito' => "TEXT NOT NULL DEFAULT ''", 'ficha' => "TEXT NOT NULL DEFAULT ''", 'publicada' => 'INTEGER NOT NULL DEFAULT 0',
    'version' => 'INTEGER NOT NULL DEFAULT 0', 'creado' => 'TEXT', 'actualizado' => 'TEXT'];
  foreach ($nuevas as $col => $tipo) if (!in_array($col, $hay, true)) $db->exec("ALTER TABLE obras ADD COLUMN $col $tipo");
}

function obra(string $uuid): ?array {
  $st = db()->prepare('SELECT * FROM obras WHERE uuid = ?');
  $st->execute([strtolower($uuid)]);
  return $st->fetch() ?: null;
}

// Crea o actualiza una obra y guarda la versión anterior en el historial. Devuelve el UUID.
function obra_guardar(array $d, ?string $uuid, string $autor = 'admin'): string {
  $db = db();
  $o = [];
  foreach (CAMPOS_OBRA as $k) $o[$k] = is_string($d[$k] ?? null) ? trim(mb_substr($d[$k], 0, 8000)) : ($d[$k] ?? '');
  if ($o['titulo'] === '') throw new InvalidArgumentException('Falta el título.');
  if (!in_array($o['sensibilidad'], SENSIBILIDADES, true)) throw new InvalidArgumentException('Sensibilidad no válida.');
  if (!in_array($o['fase'], FASES, true)) throw new InvalidArgumentException('Fase no válida.');
  foreach (['lat' => [27.4, 29.6], 'lon' => [-18.4, -13.2]] as $k => [$min, $max]) {
    $o[$k] = $o[$k] === '' ? null : (float) str_replace(',', '.', (string) $o[$k]);
    if ($o[$k] !== null && ($o[$k] < $min || $o[$k] > $max)) throw new InvalidArgumentException('Las coordenadas no caen en Canarias.');
  }
  if (($o['lat'] === null) !== ($o['lon'] === null)) throw new InvalidArgumentException('Faltan la latitud o la longitud.');
  if ($o['lat'] === null && $o['municipio'] === '') throw new InvalidArgumentException('Una obra sin punto necesita al menos el municipio.');
  foreach (['foto', 'ficha'] as $k) {
    if ($o[$k] !== '' && !preg_match('#^https://#', $o[$k]) && !($k === 'ficha' && !str_contains($o[$k], '://'))) {
      throw new InvalidArgumentException("El enlace de $k tiene que empezar por https://");
    }
  }
  $o['publicada'] = $o['publicada'] ? 1 : 0;

  $db->beginTransaction();
  if ($uuid && ($antes = obra($uuid))) {
    $db->prepare('INSERT INTO obras_versiones (obra, version, datos, autor) VALUES (?, ?, ?, ?)')
       ->execute([$antes['uuid'], $antes['version'], json_encode($antes, JSON_UNESCAPED_UNICODE), $autor]);
    $sets = implode(', ', array_map(fn($k) => "$k = :$k", CAMPOS_OBRA));
    $db->prepare("UPDATE obras SET $sets, version = version + 1, actualizado = datetime('now') WHERE uuid = :uuid")
       ->execute($o + ['uuid' => $antes['uuid']]);
    $uuid = $antes['uuid'];
  } else {
    $uuid = $uuid && preg_match('/^' . RE_UUID . '$/', strtolower($uuid)) ? strtolower($uuid) : uuid4();
    $cols = implode(', ', CAMPOS_OBRA);
    $vals = implode(', ', array_map(fn($k) => ":$k", CAMPOS_OBRA));
    $db->prepare("INSERT INTO obras (uuid, $cols, version, creado, actualizado) VALUES (:uuid, $vals, 1, datetime('now'), datetime('now'))")
       ->execute($o + ['uuid' => $uuid]);
  }
  $db->commit();
  return $uuid;
}

// Punto público de una obra según su sensibilidad: exacto, centro de su celda de 2 km o ninguno.
function punto_publico(array $o): ?array {
  if ($o['lat'] === null || $o['sensibilidad'] === 'reservada') return null;
  if ($o['sensibilidad'] === 'abierta') return [round((float) $o['lon'], 6), round((float) $o['lat'], 6)];
  $c = fn($v) => round(floor($v / PASO_DIFUSA) * PASO_DIFUSA + PASO_DIFUSA / 2, 4);
  return [$c((float) $o['lon']), $c((float) $o['lat'])];
}

function enviar_geojson(array $features): never {
  header('Access-Control-Allow-Origin: *');  // datos públicos: los lee la web, uMap y quien quiera reutilizarlos
  header('Content-Type: application/geo+json; charset=utf-8');
  header('Cache-Control: public, max-age=300');
  echo json_encode(['type' => 'FeatureCollection', 'features' => $features], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function catalogo_geojson(): never {
  $features = [];
  foreach (db()->query('SELECT * FROM obras WHERE publicada = 1 ORDER BY titulo') as $o) {
    $p = punto_publico($o);
    $props = ['id' => $o['uuid'], 'name' => $o['titulo'], 'sensibilidad' => $o['sensibilidad'],
      'precision' => $p === null ? 'municipio' : ($o['sensibilidad'] === 'abierta' ? 'exacta' : 'zona'),
      'version' => (int) $o['version'], 'actualizado' => $o['actualizado']];
    foreach (['fase', 'municipio', 'tipo', 'descripcion', 'autoria', 'fecha_obra', 'foto', 'foto_credito', 'ficha'] as $k) {
      if ($o[$k] !== '' && $o[$k] !== null) $props[$k] = $o[$k];
    }
    // El lugar concreto de una obra sensible no se publica.
    if ($o['lugar'] !== '' && $o['sensibilidad'] === 'abierta') $props['lugar'] = $o['lugar'];
    $features[] = ['type' => 'Feature', 'geometry' => $p ? ['type' => 'Point', 'coordinates' => $p] : null, 'properties' => $props];
  }
  enviar_geojson($features);
}

function rutas_geojson(): never {
  $obras = [];
  foreach (db()->query('SELECT * FROM obras WHERE publicada = 1') as $o) $obras[$o['uuid']] = $o;
  $features = [];
  foreach (db()->query('SELECT * FROM rutas WHERE publicada = 1 ORDER BY nombre') as $r) {
    // Solo paradas publicadas y con punto: una ruta no puede delatar dónde está una obra reservada.
    $paradas = array_values(array_filter(array_map('trim', explode(',', $r['paradas'])), fn($u) => isset($obras[$u]) && punto_publico($obras[$u])));
    if (count($paradas) < 2) continue;
    // El trazado dibujado solo se publica si todas las paradas son abiertas: si no, podría pasar por el punto exacto de una obra difusa.
    $traza = json_decode((string) $r['trazado'], true);
    $abierta = !array_filter($paradas, fn($u) => $obras[$u]['sensibilidad'] !== 'abierta');
    $coords = $abierta && ($traza['type'] ?? '') === 'LineString' ? $traza['coordinates'] : array_map(fn($u) => punto_publico($obras[$u]), $paradas);
    $features[] = ['type' => 'Feature', 'geometry' => ['type' => 'LineString', 'coordinates' => $coords],
      'properties' => ['id' => $r['id'], 'name' => $r['nombre'], 'description' => $r['descripcion'], 'curaduria' => $r['curaduria'], 'paradas' => implode(',', $paradas)]];
  }
  enviar_geojson($features);
}

// Fotos de obras subidas desde /admin: solo se sirven si alguna obra publicada las usa.
function foto_publica(string $nombre): never {
  $url = rtrim(config()['api'], '/') . '/datos/fotos/' . $nombre;
  $st = db()->prepare('SELECT 1 FROM obras WHERE publicada = 1 AND foto = ? UNION SELECT 1 FROM artistas WHERE publicada = 1 AND foto = ?');
  $st->execute([$url, $url]);
  if (!$st->fetchColumn() || !is_file(PRIVADO . '/fotos/' . $nombre)) error_publico('Foto no encontrada.', 404);
  header('Access-Control-Allow-Origin: *');
  header('Content-Type: image/jpeg');
  header('Cache-Control: public, max-age=86400');
  readfile(PRIVADO . '/fotos/' . $nombre);
  exit;
}

// ---------- Rutas ----------
function ruta_guardar(array $d, ?string $id): string {
  $nombre = trim((string) ($d['nombre'] ?? ''));
  if ($nombre === '') throw new InvalidArgumentException('Falta el nombre de la ruta.');
  $id = $id ?: 'RU-' . trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $nombre) ?: 'ruta')), '-');
  $paradas = implode(',', array_filter(array_map(fn($u) => strtolower(trim($u)), preg_split('/[\s,]+/', (string) ($d['paradas'] ?? ''))),
    fn($u) => preg_match('/^' . RE_UUID . '$/', $u)));
  $traza = trim((string) ($d['trazado'] ?? ''));
  if ($traza !== '' && (json_decode($traza, true)['type'] ?? '') !== 'LineString') throw new InvalidArgumentException('El trazado tiene que ser un LineString en GeoJSON (o quedarse vacío).');
  db()->prepare("INSERT INTO rutas (id, nombre, descripcion, curaduria, paradas, trazado, publicada, actualizado)
                 VALUES (?, ?, ?, ?, ?, ?, ?, datetime('now'))
                 ON CONFLICT(id) DO UPDATE SET nombre = excluded.nombre, descripcion = excluded.descripcion, curaduria = excluded.curaduria,
                 paradas = excluded.paradas, trazado = excluded.trazado, publicada = excluded.publicada, actualizado = excluded.actualizado")
      ->execute([$id, $nombre, trim((string) ($d['descripcion'] ?? '')), trim((string) ($d['curaduria'] ?? '')), $paradas, $traza, empty($d['publicada']) ? 0 : 1]);
  return $id;
}

// ---------- Artistas ----------
const CAMPOS_ARTISTA = ['nombre', 'disciplina', 'municipio', 'bio', 'web', 'tienda', 'mecenazgo', 'redes', 'foto', 'foto_credito',
  'espacio', 'lat', 'lon', 'obras', 'consentimiento', 'publicada'];

function artista_guardar(array $d, ?string $id): string {
  $a = [];
  foreach (CAMPOS_ARTISTA as $k) $a[$k] = trim(mb_substr((string) ($d[$k] ?? ''), 0, 8000));
  if ($a['nombre'] === '') throw new InvalidArgumentException('Falta el nombre.');
  if ($a['publicada'] && $a['consentimiento'] === '') throw new InvalidArgumentException('Para publicar un perfil hace falta anotar su consentimiento (fecha y cómo lo dio).');
  foreach (['web', 'tienda', 'mecenazgo', 'foto'] as $k) {
    if ($a[$k] !== '' && !str_starts_with($a[$k], 'https://')) throw new InvalidArgumentException("El enlace de $k tiene que empezar por https://");
  }
  foreach (['lat' => [27.4, 29.6], 'lon' => [-18.4, -13.2]] as $k => [$min, $max]) {
    $a[$k] = $a[$k] === '' ? null : (float) str_replace(',', '.', $a[$k]);
    if ($a[$k] !== null && ($a[$k] < $min || $a[$k] > $max)) throw new InvalidArgumentException('Las coordenadas no caen en Canarias.');
  }
  if (($a['lat'] === null) !== ($a['lon'] === null)) throw new InvalidArgumentException('Faltan la latitud o la longitud.');
  if ($a['lat'] !== null && $a['espacio'] === '') throw new InvalidArgumentException('Un punto en el mapa solo para un espacio público suyo: escribe cuál (taller abierto, galería, tienda).');
  $a['publicada'] = $a['publicada'] ? 1 : 0;
  $id = $id ?: uuid4();
  $cols = implode(', ', CAMPOS_ARTISTA);
  $vals = implode(', ', array_map(fn($k) => ":$k", CAMPOS_ARTISTA));
  $upd = implode(', ', array_map(fn($k) => "$k = excluded.$k", CAMPOS_ARTISTA));
  db()->prepare("INSERT INTO artistas (id, $cols, actualizado) VALUES (:id, $vals, datetime('now'))
                 ON CONFLICT(id) DO UPDATE SET $upd, actualizado = datetime('now')")->execute($a + ['id' => $id]);
  return $id;
}

function artistas_geojson(): never {
  $features = [];
  foreach (db()->query('SELECT * FROM artistas WHERE publicada = 1 ORDER BY nombre') as $a) {
    $props = ['id' => $a['id'], 'name' => $a['nombre']];
    foreach (['disciplina', 'municipio', 'bio', 'web', 'tienda', 'mecenazgo', 'redes', 'foto', 'foto_credito', 'espacio', 'obras'] as $k) {
      if ($a[$k] !== '') $props[$k] = $a[$k];
    }
    $geo = $a['lat'] !== null ? ['type' => 'Point', 'coordinates' => [round((float) $a['lon'], 6), round((float) $a['lat'], 6)]] : null;
    $features[] = ['type' => 'Feature', 'geometry' => $geo, 'properties' => $props];
  }
  enviar_geojson($features);
}
