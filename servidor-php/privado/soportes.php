<?php
// Soportes: lo que identifica una etiqueta QR. Suele ser un lugar físico (pared, fachada, roca, banco, mobiliario…),
// pero también puede ser una web u otro soporte digital: entonces lleva su URL y no necesita punto en el mapa.
// Un soporte puede tener varias obras a la vez, o unas sobre otras con el tiempo (un mural pintado encima de otro):
// es su estratigrafía. La relación obra–soporte lleva fechas «desde» y «hasta», así que una obra trasladada
// cambia de soporte sin perder su historia. La etiqueta identifica el soporte, no una obra.

const TIPOS_SOPORTE = ['' => 'Sin indicar', 'muro' => 'Muro o pared', 'fachada' => 'Fachada', 'roca' => 'Roca o risco',
  'suelo' => 'Suelo o pavimento', 'mobiliario' => 'Mobiliario urbano', 'edificio' => 'Edificio', 'paisaje' => 'Paisaje o terreno',
  'objeto' => 'Objeto o pieza exenta', 'web' => 'Web o soporte digital', 'ar' => 'Realidad aumentada (digital anclada a un lugar)', 'otro' => 'Otro'];
// Una obra de realidad aumentada es digital pero está en un sitio: su soporte lleva punto y URL, y su etiqueta se
// coloca y se verifica con el GPS como la de un muro. Solo los soportes sin punto (una web) quedan fuera del GPS.
const CAMPOS_SOPORTE = ['nombre', 'tipo', 'sensibilidad', 'lat', 'lon', 'isla', 'municipio', 'lugar', 'url', 'descripcion'];
const NIVEL_SENS = ['abierta' => 0, 'difusa' => 1, 'reservada' => 2];

function migrar_soportes(PDO $db): void {
  $cols = array_column($db->query('PRAGMA table_info(soportes)')->fetchAll(), 'name');
  if (!in_array('url', $cols, true)) $db->exec("ALTER TABLE soportes ADD COLUMN url TEXT NOT NULL DEFAULT ''");
  $hay = array_column($db->query('PRAGMA table_info(qr_codigos)')->fetchAll(), 'name');
  if (!in_array('soporte', $hay, true)) $db->exec('ALTER TABLE qr_codigos ADD COLUMN soporte TEXT');
  $db->exec('CREATE INDEX IF NOT EXISTS qr_soporte ON qr_codigos(soporte)');
  // Etiquetas colocadas antes de existir los soportes: cada una pasa a ser un soporte con su obra dentro.
  $viejas = $db->query("SELECT q.codigo, q.obra, q.lat, q.lon FROM qr_codigos q WHERE q.soporte IS NULL AND q.obra IS NOT NULL AND q.lat IS NOT NULL")->fetchAll();
  foreach ($viejas as $q) {
    $st = $db->prepare("SELECT soporte FROM obra_soporte WHERE obra = ? AND hasta = '' LIMIT 1");
    $st->execute([$q['obra']]);
    $s = $st->fetchColumn();
    if (!$s) {
      $o = $db->prepare('SELECT * FROM obras WHERE uuid = ?');
      $o->execute([$q['obra']]);
      $o = $o->fetch() ?: ['titulo' => '', 'sensibilidad' => 'abierta', 'isla' => '', 'municipio' => '', 'lugar' => ''];
      $o += ['isla' => '', 'municipio' => '', 'lugar' => ''];
      $s = uuid4();
      $db->prepare('INSERT INTO soportes (uuid, nombre, sensibilidad, lat, lon, isla, municipio, lugar) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
         ->execute([$s, $o['titulo'] ? 'Soporte de «' . $o['titulo'] . '»' : 'Soporte', $o['sensibilidad'], $q['lat'], $q['lon'], $o['isla'], $o['municipio'], $o['lugar']]);
      $db->prepare('INSERT INTO obra_soporte (obra, soporte) VALUES (?, ?)')->execute([$q['obra'], $s]);
    }
    $db->prepare('UPDATE qr_codigos SET soporte = ? WHERE codigo = ?')->execute([$s, $q['codigo']]);
  }
}

function soporte(string $uuid): ?array {
  $st = db()->prepare('SELECT * FROM soportes WHERE uuid = ?');
  $st->execute([strtolower($uuid)]);
  return $st->fetch() ?: null;
}

// Obras de un soporte: las de ahora (sin «hasta») primero; después las anteriores, de la más reciente a la más antigua.
function obras_de_soporte(string $soporte, bool $solo_publicadas = false): array {
  $st = db()->prepare("SELECT os.obra, os.desde, os.hasta, os.nota, o.titulo, o.sensibilidad, o.publicada, o.fase
                       FROM obra_soporte os LEFT JOIN obras o ON o.uuid = os.obra WHERE os.soporte = ?" . ($solo_publicadas ? ' AND o.publicada = 1' : '') . "
                       ORDER BY os.hasta != '', os.hasta DESC, os.desde DESC, os.id DESC");
  $st->execute([$soporte]);
  return $st->fetchAll();
}

// Soporte actual de una obra (el más reciente sin «hasta»).
function soporte_de_obra(string $obra): ?array {
  $st = db()->prepare("SELECT s.* FROM obra_soporte os JOIN soportes s ON s.uuid = os.soporte WHERE os.obra = ? AND os.hasta = '' ORDER BY os.id DESC LIMIT 1");
  $st->execute([$obra]);
  return $st->fetch() ?: null;
}

// La protección efectiva de un soporte es la mayor entre la suya y la de cualquiera de sus obras:
// si una obra delicada está en esa pared, la pared tampoco se puede publicar con más detalle.
function sensibilidad_soporte(array $s): string {
  $n = NIVEL_SENS[$s['sensibilidad']] ?? 0;
  foreach (obras_de_soporte($s['uuid']) as $o) $n = max($n, NIVEL_SENS[$o['sensibilidad'] ?? 'abierta'] ?? 0);
  return array_search($n, NIVEL_SENS, true);
}

function enlazar_obra_soporte(string $obra, string $soporte, string $desde = ''): void {
  $st = db()->prepare("SELECT 1 FROM obra_soporte WHERE obra = ? AND soporte = ? AND hasta = ''");
  $st->execute([$obra, $soporte]);
  if (!$st->fetchColumn()) db()->prepare('INSERT INTO obra_soporte (obra, soporte, desde) VALUES (?, ?, ?)')->execute([$obra, $soporte, $desde]);
}

function soporte_crear(float $lat, float $lon, string $nombre, string $tipo, string $sensibilidad, array $de = []): string {
  $u = uuid4();
  db()->prepare('INSERT INTO soportes (uuid, nombre, tipo, sensibilidad, lat, lon, isla, municipio, lugar) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
      ->execute([$u, mb_substr($nombre, 0, 200), isset(TIPOS_SOPORTE[$tipo]) ? $tipo : '', in_array($sensibilidad, SENSIBILIDADES, true) ? $sensibilidad : 'abierta',
        $lat, $lon, isla_de($lat, $lon), $de['municipio'] ?? '', $de['lugar'] ?? '']);
  return $u;
}

// Crea o actualiza un soporte desde /admin. $d['obras'] es la lista (JSON) de obras con desde, hasta y nota.
function soporte_guardar(array $d, ?string $uuid): string {
  $s = [];
  foreach (CAMPOS_SOPORTE as $k) $s[$k] = trim(mb_substr((string) ($d[$k] ?? ''), 0, 4000));
  if ($s['nombre'] === '') throw new InvalidArgumentException('Falta el nombre del soporte (por ejemplo, «Muro norte del lavadero»).');
  if (!isset(TIPOS_SOPORTE[$s['tipo']])) $s['tipo'] = '';
  if (!in_array($s['sensibilidad'], SENSIBILIDADES, true)) throw new InvalidArgumentException('Sensibilidad no válida.');
  foreach (['lat' => [27.4, 29.6], 'lon' => [-18.4, -13.2]] as $k => [$min, $max]) {
    $s[$k] = $s[$k] === '' ? null : (float) str_replace(',', '.', $s[$k]);
    if ($s[$k] !== null && ($s[$k] < $min || $s[$k] > $max)) throw new InvalidArgumentException('Las coordenadas no caen en Canarias.');
  }
  if (($s['lat'] === null) !== ($s['lon'] === null)) throw new InvalidArgumentException('Faltan la latitud o la longitud.');
  if ($s['url'] !== '' && !preg_match('#^https?://[^\s]+$#i', $s['url'])) throw new InvalidArgumentException('La URL debe empezar por https://');
  if ($s['tipo'] === 'web' && $s['url'] === '') throw new InvalidArgumentException('Un soporte web necesita su URL.');
  if ($s['tipo'] === 'ar' && $s['lat'] === null) throw new InvalidArgumentException('Una obra de realidad aumentada está anclada a un lugar: marca su punto en el mapa.');
  if (!isset(ISLAS_CODIGO[$s['isla']])) $s['isla'] = $s['lat'] === null ? '' : isla_de($s['lat'], $s['lon']);
  $filas = json_decode((string) ($d['obras'] ?? '[]'), true);
  if (!is_array($filas)) throw new InvalidArgumentException('La lista de obras no es válida.');
  $obras = [];
  foreach ($filas as $f) {
    $u = strtolower(trim((string) ($f['obra'] ?? '')));
    if (!preg_match('/^' . RE_UUID . '$/', $u)) continue;
    $obras[] = [$u, trim(mb_substr((string) ($f['desde'] ?? ''), 0, 40)), trim(mb_substr((string) ($f['hasta'] ?? ''), 0, 40)), trim(mb_substr((string) ($f['nota'] ?? ''), 0, 500))];
  }
  $db = db();
  $db->beginTransaction();
  $uuid = $uuid && soporte($uuid) ? strtolower($uuid) : uuid4();
  $cols = implode(', ', CAMPOS_SOPORTE);
  $vals = implode(', ', array_map(fn($k) => ":$k", CAMPOS_SOPORTE));
  $upd = implode(', ', array_map(fn($k) => "$k = excluded.$k", CAMPOS_SOPORTE));
  $db->prepare("INSERT INTO soportes (uuid, $cols) VALUES (:uuid, $vals) ON CONFLICT(uuid) DO UPDATE SET $upd, actualizado = datetime('now')")
     ->execute($s + ['uuid' => $uuid]);
  $db->prepare('DELETE FROM obra_soporte WHERE soporte = ?')->execute([$uuid]);
  $ins = $db->prepare('INSERT INTO obra_soporte (obra, soporte, desde, hasta, nota) VALUES (?, ?, ?, ?, ?)');
  foreach ($obras as [$o, $desde, $hasta, $nota]) $ins->execute([$o, $uuid, $desde, $hasta, $nota]);
  $db->commit();
  return $uuid;
}

// Capa pública de soportes (la antigua capa QR): un punto por soporte con alguna obra publicada, con el estado de su
// etiqueta y la lista de obras. El punto se oculta según la protección efectiva; los reservados no salen.
// Un soporte sin punto (una web) sale en el punto de su etiqueta física más reciente, si tiene alguna colocada con GPS;
// si no, no va en el mapa (se ve en la ficha de su obra y al escanear su etiqueta).
function soportes_features(): array {
  $features = [];
  foreach (db()->query("SELECT s.* FROM soportes s ORDER BY s.nombre") as $s) {
    if ($s['lat'] === null) {
      $st = db()->prepare("SELECT lat, lon FROM qr_codigos WHERE soporte = ? AND lat IS NOT NULL AND estado IN ('colocado','confirmado','sin_verificar') ORDER BY creado DESC LIMIT 1");
      $st->execute([$s['uuid']]);
      if (!($g = $st->fetch())) continue;
      $s['lat'] = $g['lat']; $s['lon'] = $g['lon']; $s['lugar'] = '';
    }
    $obras = obras_de_soporte($s['uuid'], true);
    if (!$obras) continue;
    $sens = sensibilidad_soporte($s);
    if ($sens === 'reservada') continue;
    $p = punto_publico(['lat' => $s['lat'], 'lon' => $s['lon'], 'sensibilidad' => $sens]);
    $st = db()->prepare("SELECT estado, ultima_verificacion FROM qr_codigos WHERE soporte = ? AND estado IN ('colocado','confirmado','sin_verificar','desaparecida','danado') ORDER BY creado DESC LIMIT 1");
    $st->execute([$s['uuid']]);
    $q = $st->fetch() ?: null;
    $ahora = array_values(array_filter($obras, fn($o) => $o['hasta'] === ''));
    $antes = array_values(array_filter($obras, fn($o) => $o['hasta'] !== ''));
    $props = ['id' => $s['uuid'], 'name' => $s['nombre'], 'tipo' => $s['tipo'] === '' ? '' : (TIPOS_SOPORTE[$s['tipo']] ?? ''),
      'obras' => implode(',', array_column($ahora, 'obra')), 'obras_nombres' => implode(' · ', array_column($ahora, 'titulo')),
      'anteriores' => implode(' · ', array_map(fn($o) => $o['titulo'] . ($o['hasta'] ? " (hasta {$o['hasta']})" : ''), $antes)),
      'obra' => $ahora[0]['obra'] ?? $obras[0]['obra'],  // compatibilidad con la capa QR anterior
      'estado' => $q['estado'] ?? 'sin_etiqueta', 'ultima_verificacion' => fecha_iso($q['ultima_verificacion'] ?? null),
      'precision' => $sens === 'abierta' ? 'exacta' : 'zona', 'municipio' => $s['municipio']];
    if ($sens === 'abierta' && $s['lugar'] !== '') $props['lugar'] = $s['lugar'];
    if (($s['url'] ?? '') !== '') $props['url'] = $s['url'];
    $features[] = ['type' => 'Feature', 'geometry' => ['type' => 'Point', 'coordinates' => $p], 'properties' => array_filter($props, fn($v) => $v !== '' && $v !== null)];
  }
  return $features;
}
