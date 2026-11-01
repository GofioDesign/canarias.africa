<?php
// Catálogo de obras y rutas: la fuente de datos es este servidor (se edita en /admin/obras y /admin/rutas).
// Se publica en GeoJSON en /datos/obras.geojson, /datos/qr.geojson y /datos/rutas.geojson, que leen la web y uMap.
// uMap solo muestra: sus capas apuntan a esas URL como «datos remotos».

const FASES = ['gestacion', 'creacion', 'plenitud', 'desgaste', 'intervencion', 'transformacion', 'desaparicion', 'memoria'];
const SENSIBILIDADES = ['abierta', 'difusa', 'reservada'];
const CAMPOS_OBRA = ['titulo', 'sensibilidad', 'lat', 'lon', 'fase', 'isla', 'municipio', 'lugar', 'tipo', 'descripcion', 'autoria',
  'fecha_obra', 'fecha_precision', 'agentes', 'pagadores', 'relaciones', 'fuentes', 'foto', 'foto_credito', 'ficha', 'publicada'];
// Vocabularios de los nodos de una obra. Son los mismos en /admin y en el formulario público de la web.
const ROLES_AGENTE = ['autoria' => 'Autoría', 'coautoria' => 'Coautoría', 'ejecucion' => 'Ejecución', 'encargo' => 'Encargo',
  'restauracion' => 'Restauración', 'colaboracion' => 'Colaboración', 'cuidado' => 'Cuidado'];
const CERTEZAS = ['confirmada' => 'Confirmada', 'probable' => 'Probable', 'atribuida' => 'Atribuida', 'desconocida' => 'Desconocida'];
// Quién pagó: entidad pública o privada. Un particular se guarda, pero su nombre no se publica nunca.
const NATURALEZAS = ['publica' => 'Entidad pública', 'privada' => 'Entidad privada', 'particular' => 'Particular (su nombre no se publica)'];
const MODALIDADES = ['' => 'Sin indicar', 'encargo' => 'Encargo', 'subvencion' => 'Subvención', 'patrocinio' => 'Patrocinio',
  'donacion' => 'Donación', 'autofinanciada' => 'Autofinanciada'];
const MODOS_RELACION = ['continua' => 'continúa', 'responde' => 'responde a', 'superpone' => 'se superpone a', 'serie' => 'forma serie con'];
const PRECISIONES_FECHA = ['' => 'Sin indicar', 'exacta' => 'Exacta', 'aproximada' => 'Aproximada', 'decada' => 'Década', 'epoca' => 'Época'];
const PASO_DIFUSA = 0.02;
// Islas: una obra reservada (delicada) solo publica su isla, ni municipio ni punto. [lon mín, lon máx, lat mín, lat máx]
const ISLAS_CODIGO = ['CAN' => 'Canarias (sin isla concreta)', 'TF' => 'Tenerife', 'GC' => 'Gran Canaria', 'LZ' => 'Lanzarote', 'FV' => 'Fuerteventura', 'LP' => 'La Palma',
  'GO' => 'La Gomera', 'EH' => 'El Hierro', 'LG' => 'La Graciosa'];
const ISLA_CAJA = ['LG' => [-13.56, -13.46, 29.21, 29.30], 'EH' => [-18.17, -17.86, 27.62, 27.86], 'LP' => [-18.02, -17.70, 28.44, 28.87],
  'GO' => [-17.36, -17.08, 27.99, 28.23], 'TF' => [-16.95, -16.10, 27.98, 28.60], 'GC' => [-15.86, -15.35, 27.72, 28.19],
  'FV' => [-14.55, -13.80, 28.03, 28.78], 'LZ' => [-13.90, -13.40, 28.83, 29.25]];
// Obras con ubicación protegida (reservadas): se dibujan en un punto ficticio alrededor de un centro fijo, a hasta 5 km
// del centro de su isla o 50 km del centro del archipiélago. El desplazamiento sale del UUID, nunca de las coordenadas
// reales: es estable entre publicaciones y no da ninguna pista de dónde está la obra. [lat, lon, radio en km]
const CENTRO_PROTEGIDO = ['CAN' => [28.30, -15.80, 50], 'TF' => [28.27, -16.56, 5], 'GC' => [27.96, -15.59, 5], 'LZ' => [29.03, -13.65, 5],
  'FV' => [28.40, -14.03, 5], 'LP' => [28.68, -17.86, 5], 'GO' => [28.11, -17.22, 5], 'EH' => [27.75, -18.00, 5], 'LG' => [29.245, -13.505, 1.5]];
function punto_protegido(string $uuid, string $isla): array {
  [$lat, $lon, $radio] = CENTRO_PROTEGIDO[$isla] ?? CENTRO_PROTEGIDO['CAN'];
  $h = hash('sha256', 'canarias-protegida:' . $uuid, true);
  $a = unpack('N2', $h);
  $angulo = $a[1] / 4294967295 * 2 * M_PI;
  $r = $radio * sqrt($a[2] / 4294967295);  // reparto uniforme dentro del círculo
  $dlat = $r * cos($angulo) / 111.32;
  $dlon = $r * sin($angulo) / (111.32 * cos(deg2rad($lat)));
  return [round($lon + $dlon, 4), round($lat + $dlat, 4)];
}

function isla_de(?float $lat, ?float $lon): string {
  if ($lat === null || $lon === null) return '';
  foreach (ISLA_CAJA as $k => [$x0, $x1, $y0, $y1]) if ($lon >= $x0 && $lon <= $x1 && $lat >= $y0 && $lat <= $y1) return $k;
  return '';
}  // cuadrícula fija de ~2 km para las obras difusas

// Añade las columnas nuevas a una base de datos creada con un esquema anterior.
function migrar_catalogo(PDO $db): void {
  $hay = array_column($db->query('PRAGMA table_info(obras)')->fetchAll(), 'name');
  $nuevas = ['lat' => 'REAL', 'lon' => 'REAL', 'fase' => "TEXT NOT NULL DEFAULT 'plenitud'", 'municipio' => "TEXT NOT NULL DEFAULT ''",
    'lugar' => "TEXT NOT NULL DEFAULT ''", 'tipo' => "TEXT NOT NULL DEFAULT ''", 'descripcion' => "TEXT NOT NULL DEFAULT ''",
    'autoria' => "TEXT NOT NULL DEFAULT ''", 'fecha_obra' => "TEXT NOT NULL DEFAULT ''", 'foto' => "TEXT NOT NULL DEFAULT ''",
    'foto_credito' => "TEXT NOT NULL DEFAULT ''", 'ficha' => "TEXT NOT NULL DEFAULT ''", 'publicada' => 'INTEGER NOT NULL DEFAULT 0',
    'version' => 'INTEGER NOT NULL DEFAULT 0', 'creado' => 'TEXT', 'actualizado' => 'TEXT', 'fecha_precision' => "TEXT NOT NULL DEFAULT ''",
    'agentes' => "TEXT NOT NULL DEFAULT '[]'", 'pagadores' => "TEXT NOT NULL DEFAULT '[]'", 'relaciones' => "TEXT NOT NULL DEFAULT '[]'",
    'fuentes' => "TEXT NOT NULL DEFAULT ''", 'isla' => "TEXT NOT NULL DEFAULT ''"];
  foreach ($nuevas as $col => $tipo) if (!in_array($col, $hay, true)) $db->exec("ALTER TABLE obras ADD COLUMN $col $tipo");
  $hay = array_column($db->query('PRAGMA table_info(rutas)')->fetchAll(), 'name');
  if (!in_array('notas', $hay, true)) $db->exec("ALTER TABLE rutas ADD COLUMN notas TEXT NOT NULL DEFAULT '{}'");
}

// Listas de nodos de una obra (personas y colectivos, quién pagó, relaciones con otras obras), guardadas en JSON.
// Admite el JSON que mandan los formularios y descarta las filas incompletas.
function normalizar_nodos(string $tipo, $v): string {
  $filas = is_array($v) ? $v : json_decode(trim((string) $v) ?: '[]', true);
  if (!is_array($filas)) throw new InvalidArgumentException("La lista de $tipo no es válida.");
  $out = [];
  foreach (array_slice($filas, 0, 40) as $f) {
    if (!is_array($f)) continue;
    $t = fn($k) => trim(mb_substr((string) ($f[$k] ?? ''), 0, 300));
    if ($tipo === 'agentes') {
      if ($t('nombre') === '') continue;
      $out[] = ['nombre' => $t('nombre'), 'rol' => isset(ROLES_AGENTE[$t('rol')]) ? $t('rol') : 'autoria',
        'certeza' => isset(CERTEZAS[$t('certeza')]) ? $t('certeza') : 'atribuida', 'visible' => !empty($f['visible'])];
    } elseif ($tipo === 'pagadores') {
      $n = $t('naturaleza');
      if (!isset(NATURALEZAS[$n]) || ($n !== 'particular' && $t('entidad') === '')) continue;
      $out[] = ['naturaleza' => $n, 'entidad' => $t('entidad'), 'modalidad' => isset(MODALIDADES[$t('modalidad')]) ? $t('modalidad') : ''];
    } elseif ($tipo === 'relaciones') {
      $u = strtolower($t('obra'));
      if (!preg_match('/^' . RE_UUID . '$/', $u) || !isset(MODOS_RELACION[$t('modo')])) continue;
      $out[] = ['modo' => $t('modo'), 'obra' => $u, 'nota' => $t('nota')];
    }
  }
  return json_encode($out, JSON_UNESCAPED_UNICODE);
}

// Entidades que ya pagaron otras obras, por naturaleza: son las sugerencias del campo «Entidad». Una nueva se escribe sin más.
function entidades_conocidas(bool $solo_publicadas = true): array {
  $r = ['publica' => [], 'privada' => []];
  foreach (db()->query('SELECT pagadores FROM obras' . ($solo_publicadas ? ' WHERE publicada = 1' : '')) as $o) {
    foreach (json_decode($o['pagadores'] ?: '[]', true) ?: [] as $p) {
      if (isset($r[$p['naturaleza'] ?? '']) && ($p['entidad'] ?? '') !== '') $r[$p['naturaleza']][$p['entidad']] = true;
    }
  }
  return array_map(function ($l) { $l = array_keys($l); sort($l, SORT_LOCALE_STRING); return $l; }, $r);
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
  if (!isset(ISLAS_CODIGO[$o['isla']])) $o['isla'] = isla_de($o['lat'], $o['lon']);
  // Una reservada sin isla ni punto se dibuja alrededor del centro del archipiélago.
  if ($o['sensibilidad'] === 'reservada' && $o['isla'] === '') $o['isla'] = 'CAN';
  foreach (['foto', 'ficha'] as $k) {
    if ($o[$k] !== '' && !preg_match('#^https://#', $o[$k]) && !($k === 'ficha' && !str_contains($o[$k], '://'))) {
      throw new InvalidArgumentException("El enlace de $k tiene que empezar por https://");
    }
  }
  $o['publicada'] = $o['publicada'] ? 1 : 0;
  foreach (['agentes', 'pagadores', 'relaciones'] as $k) $o[$k] = normalizar_nodos($k, $d[$k] ?? '[]');
  if (!isset(PRECISIONES_FECHA[$o['fecha_precision']])) $o['fecha_precision'] = '';

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

function catalogo_geojson(): never { enviar_geojson(catalogo_geojson_features()); }

function catalogo_geojson_features(): array {
  $features = [];
  $obras = db()->query('SELECT * FROM obras WHERE publicada = 1 ORDER BY titulo')->fetchAll();
  $publicadas = array_flip(array_column($obras, 'uuid'));
  foreach ($obras as $o) {
    $p = punto_publico($o);
    $props = ['id' => $o['uuid'], 'name' => $o['titulo'], 'sensibilidad' => $o['sensibilidad'],
      'precision' => $o['sensibilidad'] === 'reservada' ? (($o['isla'] ?: 'CAN') === 'CAN' ? 'canarias' : 'isla') : ($p === null ? 'municipio' : ($o['sensibilidad'] === 'abierta' ? 'exacta' : 'zona')),
      'version' => (int) $o['version'], 'actualizado' => $o['actualizado']];
    foreach (['fase', 'municipio', 'tipo', 'descripcion', 'autoria', 'fecha_obra', 'fecha_precision', 'foto', 'foto_credito', 'ficha'] as $k) {
      if ($o[$k] !== '' && $o[$k] !== null) $props[$k] = $o[$k];
    }
    // Personas: solo las marcadas como visibles (han aceptado figurar o son públicas). Particulares que pagan: sin nombre.
    // Relaciones: solo con obras publicadas. Las fuentes se quedan en /admin.
    $ag = array_values(array_map(fn($a) => ['nombre' => $a['nombre'], 'rol' => $a['rol'], 'certeza' => $a['certeza']],
      array_filter(json_decode($o['agentes'] ?: '[]', true) ?: [], fn($a) => !empty($a['visible']))));
    $pg = array_map(fn($p) => array_filter(['naturaleza' => $p['naturaleza'], 'entidad' => $p['naturaleza'] === 'particular' ? '' : $p['entidad'],
      'modalidad' => $p['modalidad']], fn($v) => $v !== ''), json_decode($o['pagadores'] ?: '[]', true) ?: []);
    $rl = array_values(array_filter(json_decode($o['relaciones'] ?: '[]', true) ?: [], fn($r) => isset($publicadas[$r['obra']])));
    if ($ag) $props['agentes'] = $ag;
    if ($pg) $props['pagadores'] = $pg;
    // Versiones en texto de las listas, para los campos de uMap (que no muestra listas).
    if ($ag && empty($props['autoria'])) $props['autoria'] = implode(', ', array_map(fn($a) => $a['nombre'] . ($a['rol'] !== 'autoria' ? ' (' . mb_strtolower(ROLES_AGENTE[$a['rol']]) . ')' : ''), $ag));
    if ($pg) $props['financiacion'] = implode(' · ', array_map(fn($p) => (['publica' => 'Pública', 'privada' => 'Privada', 'particular' => 'Particular'][$p['naturaleza']])
      . (!empty($p['entidad']) ? ': ' . $p['entidad'] : '') . (!empty($p['modalidad']) ? ' (' . mb_strtolower(MODALIDADES[$p['modalidad']]) . ')' : ''), $pg));
    if ($rl) $props['relaciones'] = $rl;
    if ($o['isla'] !== '') $props['isla'] = $o['isla'];
    // Obra delicada (reservada): ni municipio ni punto, solo la isla.
    if ($o['sensibilidad'] === 'reservada') unset($props['municipio']);
    // El lugar concreto de una obra sensible no se publica.
    if ($o['lugar'] !== '' && $o['sensibilidad'] === 'abierta') $props['lugar'] = $o['lugar'];
    if ($o['sensibilidad'] === 'reservada') $p = punto_protegido($o['uuid'], $o['isla'] ?: 'CAN');
    $features[] = ['type' => 'Feature', 'geometry' => $p ? ['type' => 'Point', 'coordinates' => $p] : null, 'properties' => $props];
  }
  return $features;
}

function rutas_geojson(): never { enviar_geojson(rutas_geojson_features()); }

function rutas_geojson_features(): array {
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
      'properties' => ['id' => $r['id'], 'name' => $r['nombre'], 'description' => $r['descripcion'], 'curaduria' => $r['curaduria'], 'paradas' => implode(',', $paradas),
        'notas' => (object) array_intersect_key(json_decode($r['notas'] ?: '{}', true) ?: [], array_flip($paradas))]];
  }
  return $features;
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
  $notas = json_decode((string) ($d['notas'] ?? '{}'), true);
  $notas = is_array($notas) ? array_filter(array_map(fn($n) => trim(mb_substr((string) $n, 0, 500)), $notas), fn($n, $u) => $n !== '' && str_contains(",$paradas,", ",$u,"), ARRAY_FILTER_USE_BOTH) : [];
  $traza = trim((string) ($d['trazado'] ?? ''));
  if ($traza !== '' && (json_decode($traza, true)['type'] ?? '') !== 'LineString') throw new InvalidArgumentException('El trazado tiene que ser un LineString en GeoJSON (o quedarse vacío).');
  db()->prepare("INSERT INTO rutas (id, nombre, descripcion, curaduria, paradas, notas, trazado, publicada, actualizado)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, datetime('now'))
                 ON CONFLICT(id) DO UPDATE SET nombre = excluded.nombre, descripcion = excluded.descripcion, curaduria = excluded.curaduria,
                 paradas = excluded.paradas, notas = excluded.notas, trazado = excluded.trazado, publicada = excluded.publicada, actualizado = excluded.actualizado")
      ->execute([$id, $nombre, trim((string) ($d['descripcion'] ?? '')), trim((string) ($d['curaduria'] ?? '')), $paradas,
        json_encode((object) $notas, JSON_UNESCAPED_UNICODE), $traza, empty($d['publicada']) ? 0 : 1]);
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

function artistas_geojson(): never { enviar_geojson(artistas_geojson_features()); }

function artistas_geojson_features(): array {
  $features = [];
  foreach (db()->query('SELECT * FROM artistas WHERE publicada = 1 ORDER BY nombre') as $a) {
    $props = ['id' => $a['id'], 'name' => $a['nombre']];
    // Nombres de los campos tal como están en la capa Artistas de uMap.
    $claves = ['disciplina' => 'disciplinas', 'municipio' => 'municipio', 'bio' => 'bio', 'web' => 'enlace_web', 'tienda' => 'enlace_tienda',
      'mecenazgo' => 'enlace_mecenazgo', 'redes' => 'enlace_redes', 'foto' => 'foto', 'foto_credito' => 'foto_credito', 'espacio' => 'espacio', 'obras' => 'obras'];
    foreach ($claves as $k => $clave) if ($a[$k] !== '') $props[$clave] = $a[$k];
    $geo = $a['lat'] !== null ? ['type' => 'Point', 'coordinates' => [round((float) $a['lon'], 6), round((float) $a['lat'], 6)]] : null;
    $features[] = ['type' => 'Feature', 'geometry' => $geo, 'properties' => $props];
  }
  return $features;
}

// ---------- Publicar en canarias.africa ----------
// La web y uMap leen la copia de /datos/*.geojson que sirve GitHub Pages. Cuando el catálogo publicado cambia
// (al guardar en /admin, o por el cron si cambian las etiquetas QR), se pide a GitHub que vuelva a publicar la web,
// que copia los datos nuevos. Hace falta 'github_token' en config.php; sin él, la copia se refresca cada 6 horas.
function huella_catalogo(): string {
  return hash('sha256', json_encode([catalogo_geojson_features(), qr_mapa_features(), rutas_geojson_features(), artistas_geojson_features()]));
}

// Devuelve un texto para mostrar en /admin. $forzar: botón «Publicar ahora» (publica aunque no haya cambios).
function publicar_web(bool $forzar = false): string {
  $c = config();
  $estado = PRIVADO . '/datos/publicacion.json';
  $e = is_file($estado) ? (json_decode((string) file_get_contents($estado), true) ?: []) : [];
  $huella = huella_catalogo();
  if (!$forzar && ($e['huella'] ?? '') === $huella) return 'No hay cambios en lo publicado.';
  if (empty($c['github_token'])) return 'canarias.africa tomará los datos nuevos en la próxima actualización automática (cada 6 horas). Para publicar al momento, pon github_token en config.php.';
  // Como mucho una publicación cada 3 minutos; lo que quede pendiente lo publica el cron en la siguiente hora.
  if (!$forzar && time() - ($e['hora'] ?? 0) < 180) return 'Guardado. Se publicará en canarias.africa en la próxima pasada del cron (como mucho, una hora).';
  $repo = $c['github_repo'] ?? 'GofioDesign/canarias.africa';
  $ch = curl_init("https://api.github.com/repos/$repo/actions/workflows/publicar-web.yml/dispatches");
  curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
    CURLOPT_POSTFIELDS => json_encode(['ref' => 'main']),
    CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json', 'Authorization: Bearer ' . $c['github_token'],
      'X-GitHub-Api-Version: 2022-11-28', 'User-Agent: canarias.africa-admin', 'Content-Type: application/json']]);
  curl_exec($ch);
  $codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  if ($codigo !== 204) return "GitHub no ha aceptado la publicación (código $codigo). Revisa github_token en config.php.";
  @mkdir(dirname($estado), 0700, true);
  file_put_contents($estado, json_encode(['huella' => $huella, 'hora' => time()]));
  return 'Publicando en canarias.africa: los datos nuevos estarán en unos 2 minutos.';
}
