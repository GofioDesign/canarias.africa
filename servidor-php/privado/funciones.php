<?php
// Funciones y eventos (01-11-2026): las artes escénicas, la música y la tradición popular ocurren muchas veces.
// La obra es la pieza que se repite («Mararía», una isa, la romería de San Benito); cada función es una vez concreta
// en que ocurrió, con su fecha, su lugar (opcional) y sus intérpretes de ese día. Los eventos (festival, gira, ciclo,
// temporada, fiesta) agrupan funciones y pueden ir unos dentro de otros.
// Se publican en /datos/funciones.geojson, /datos/eventos.geojson y /datos/referencias.geojson (obras de fuera, sin lugar).

const TIPOS_EVENTO = ['' => 'Sin indicar', 'festival' => 'Festival', 'edicion' => 'Edición (de un festival o fiesta)', 'gira' => 'Gira',
  'ciclo' => 'Ciclo', 'temporada' => 'Temporada', 'fiesta' => 'Fiesta popular', 'encuentro' => 'Encuentro o muestra', 'otro' => 'Otro'];
const ESTADOS_FUNCION = ['anunciada' => 'Anunciada', 'celebrada' => 'Celebrada', 'cancelada' => 'Cancelada', 'desconocido' => 'No se sabe si se celebró'];
// Lo que queda de una función: el registro. Las grabaciones y partituras completas solo con permiso de quien tiene los derechos.
const TIPOS_REGISTRO = ['cartel' => 'Cartel', 'programa' => 'Programa de mano', 'entrada' => 'Entrada', 'foto' => 'Foto', 'video' => 'Vídeo',
  'grabacion' => 'Grabación sonora', 'partitura' => 'Partitura', 'libreto' => 'Libreto o guion', 'cronica' => 'Crónica o prensa', 'otro' => 'Otro'];
const CAMPOS_FUNCION = ['titulo', 'obras', 'evento', 'inicio', 'fin', 'fecha_precision', 'estado', 'soporte', 'isla', 'municipio', 'lugar',
  'interpretes', 'registros', 'descripcion', 'fuentes', 'publicada'];
const CAMPOS_EVENTO = ['nombre', 'tipo', 'padre', 'inicio', 'fin', 'escala', 'descripcion', 'web', 'publicada'];
const RE_FECHA_FUNCION = '/^\d{4}(-\d{2}(-\d{2}(T\d{2}:\d{2})?)?)?$/';

function escalas_ids(): array {
  static $ids = null;
  if ($ids === null) {
    $f = dirname(PRIVADO, 2) . '/datos/escalas.json';  // en el repositorio; en el hosting puede no estar
    $j = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
    $ids = $j ? array_column($j['escalas'] ?? [], 'nombre', 'id')
      : ['barrio' => 'Barrio', 'municipio' => 'Municipio', 'isla' => 'Isla', 'canarias' => 'Canarias', 'estado' => 'Estado', 'global' => 'Global'];
  }
  return $ids;
}

function evento(string $uuid): ?array {
  $st = db()->prepare('SELECT * FROM eventos WHERE uuid = ?');
  $st->execute([strtolower($uuid)]);
  return $st->fetch() ?: null;
}

function funcion(string $uuid): ?array {
  $st = db()->prepare('SELECT * FROM funciones WHERE uuid = ?');
  $st->execute([strtolower($uuid)]);
  return $st->fetch() ?: null;
}

function fecha_funcion(string $v, string $que): string {
  $v = trim($v);
  if ($v !== '' && !preg_match(RE_FECHA_FUNCION, $v)) throw new InvalidArgumentException("La fecha de $que tiene que ser AAAA, AAAA-MM, AAAA-MM-DD o AAAA-MM-DDTHH:MM.");
  return $v;
}

function evento_guardar(array $d, ?string $uuid): string {
  $e = [];
  foreach (CAMPOS_EVENTO as $k) $e[$k] = trim(mb_substr((string) ($d[$k] ?? ''), 0, 8000));
  if ($e['nombre'] === '') throw new InvalidArgumentException('Falta el nombre del evento.');
  if (!isset(TIPOS_EVENTO[$e['tipo']])) $e['tipo'] = '';
  if (!isset(escalas_ids()[$e['escala']])) $e['escala'] = '';
  $e['inicio'] = fecha_funcion($e['inicio'], 'inicio');
  $e['fin'] = fecha_funcion($e['fin'], 'fin');
  if ($e['web'] !== '' && !str_starts_with($e['web'], 'https://')) throw new InvalidArgumentException('La web tiene que empezar por https://');
  $uuid = $uuid && evento($uuid) ? strtolower($uuid) : uuid4();
  $e['padre'] = strtolower($e['padre']);
  if ($e['padre'] === '' || !evento($e['padre'])) $e['padre'] = null;
  // Sin ciclos: un evento no puede estar dentro de sí mismo ni de uno de sus hijos.
  for ($p = $e['padre'], $n = 0; $p !== null && $n < 20; $n++) {
    if ($p === $uuid) throw new InvalidArgumentException('Un evento no puede estar dentro de sí mismo.');
    $p = evento($p)['padre'] ?? null;
  }
  $e['publicada'] = $e['publicada'] ? 1 : 0;
  $cols = implode(', ', CAMPOS_EVENTO);
  $vals = implode(', ', array_map(fn($k) => ":$k", CAMPOS_EVENTO));
  $upd = implode(', ', array_map(fn($k) => "$k = excluded.$k", CAMPOS_EVENTO));
  db()->prepare("INSERT INTO eventos (uuid, $cols) VALUES (:uuid, $vals) ON CONFLICT(uuid) DO UPDATE SET $upd, actualizado = datetime('now')")
     ->execute($e + ['uuid' => $uuid]);
  return $uuid;
}

function normalizar_registros($v): string {
  $filas = is_array($v) ? $v : json_decode(trim((string) $v) ?: '[]', true);
  if (!is_array($filas)) throw new InvalidArgumentException('La lista de registros no es válida.');
  $out = [];
  foreach (array_slice($filas, 0, 40) as $f) {
    if (!is_array($f)) continue;
    $t = fn($k) => trim(mb_substr((string) ($f[$k] ?? ''), 0, 500));
    if ($t('url') === '' && $t('nota') === '') continue;
    if ($t('url') !== '' && !str_starts_with($t('url'), 'https://')) throw new InvalidArgumentException('El enlace de un registro tiene que empezar por https://');
    $out[] = ['tipo' => isset(TIPOS_REGISTRO[$t('tipo')]) ? $t('tipo') : 'otro', 'url' => $t('url'), 'credito' => $t('credito'), 'nota' => $t('nota')];
  }
  return json_encode($out, JSON_UNESCAPED_UNICODE);
}

function funcion_guardar(array $d, ?string $uuid): string {
  $f = [];
  foreach (CAMPOS_FUNCION as $k) $f[$k] = is_string($d[$k] ?? null) ? trim(mb_substr($d[$k], 0, 8000)) : ($d[$k] ?? '');
  // Obras: lista de uuid (del formulario llega como JSON de filas {obra} o como texto separado por comas).
  $filas = is_array($f['obras']) ? $f['obras'] : (json_decode((string) $f['obras'], true) ?? preg_split('/[\s,]+/', (string) $f['obras']));
  $obras = [];
  foreach ((array) $filas as $x) {
    $u = strtolower(trim((string) (is_array($x) ? ($x['obra'] ?? '') : $x)));
    if (preg_match('/^' . RE_UUID . '$/', $u) && obra($u) && !in_array($u, $obras, true)) $obras[] = $u;
  }
  if (!$obras) throw new InvalidArgumentException('Una función es de al menos una obra: elige cuál se hizo.');
  $f['obras'] = json_encode($obras);
  $f['inicio'] = fecha_funcion((string) $f['inicio'], 'inicio');
  $f['fin'] = fecha_funcion((string) $f['fin'], 'fin');
  if ($f['inicio'] === '') throw new InvalidArgumentException('Una función siempre tiene tiempo: pon al menos el año.');
  if (!isset(PRECISIONES_FECHA[$f['fecha_precision']])) $f['fecha_precision'] = '';
  if (!isset(ESTADOS_FUNCION[$f['estado']])) $f['estado'] = 'celebrada';
  $f['evento'] = strtolower((string) $f['evento']);
  if ($f['evento'] === '' || !evento($f['evento'])) $f['evento'] = null;
  $f['soporte'] = strtolower((string) $f['soporte']);
  if ($f['soporte'] === '' || !soporte($f['soporte'])) $f['soporte'] = null;
  if (!isset(ISLAS_CODIGO[$f['isla']])) $f['isla'] = '';
  // Con soporte, el lugar es el suyo; si no, el municipio o un lugar escrito (también fuera de Canarias), o ninguno.
  if ($f['soporte']) {
    $s = soporte($f['soporte']);
    foreach (['isla', 'municipio'] as $k) if ($f[$k] === '') $f[$k] = $s[$k];
  }
  $f['interpretes'] = normalizar_nodos('agentes', $d['interpretes'] ?? '[]');
  $f['registros'] = normalizar_registros($d['registros'] ?? '[]');
  $f['publicada'] = $f['publicada'] ? 1 : 0;
  $uuid = $uuid && funcion($uuid) ? strtolower($uuid) : uuid4();
  $cols = implode(', ', CAMPOS_FUNCION);
  $vals = implode(', ', array_map(fn($k) => ":$k", CAMPOS_FUNCION));
  $upd = implode(', ', array_map(fn($k) => "$k = excluded.$k", CAMPOS_FUNCION));
  db()->prepare("INSERT INTO funciones (uuid, $cols) VALUES (:uuid, $vals) ON CONFLICT(uuid) DO UPDATE SET $upd, actualizado = datetime('now')")
     ->execute($f + ['uuid' => $uuid]);
  return $uuid;
}

// Funciones de una obra, de la más reciente a la más antigua.
function funciones_de_obra(string $obra, bool $solo_publicadas = false): array {
  $st = db()->prepare("SELECT f.* FROM funciones f, json_each(f.obras) j WHERE j.value = ?" . ($solo_publicadas ? ' AND f.publicada = 1' : '') . ' ORDER BY f.inicio DESC');
  $st->execute([strtolower($obra)]);
  return $st->fetchAll();
}

// ---------- Publicación ----------

// Una función se publica si está marcada y alguna de sus obras está publicada. Su lugar se protege como el más delicado
// entre su soporte y sus obras: si una obra es reservada (un rito en un lugar sagrado), solo sale la isla.
function funciones_geojson(): never { enviar_geojson(funciones_geojson_features()); }

function funciones_geojson_features(): array {
  $obras = [];
  foreach (db()->query('SELECT uuid, titulo, sensibilidad, disciplina FROM obras WHERE publicada = 1') as $o) $obras[$o['uuid']] = $o;
  $eventos = [];
  foreach (db()->query('SELECT uuid, nombre FROM eventos WHERE publicada = 1') as $e) $eventos[$e['uuid']] = $e['nombre'];
  $features = [];
  foreach (db()->query('SELECT * FROM funciones WHERE publicada = 1 ORDER BY inicio DESC') as $f) {
    $suyas = array_values(array_filter(json_decode($f['obras'] ?: '[]', true) ?: [], fn($u) => isset($obras[$u])));
    if (!$suyas) continue;
    $nivel = max(array_map(fn($u) => NIVEL_SENS[$obras[$u]['sensibilidad']] ?? 0, $suyas));
    $s = $f['soporte'] ? soporte($f['soporte']) : null;
    if ($s) $nivel = max($nivel, NIVEL_SENS[sensibilidad_soporte($s)] ?? 0);
    $sens = array_search($nivel, NIVEL_SENS, true);
    $p = $s && $s['lat'] !== null ? punto_publico(['lat' => $s['lat'], 'lon' => $s['lon'], 'sensibilidad' => $sens]) : null;
    $int = array_values(array_map(fn($a) => ['nombre' => $a['nombre'], 'rol' => $a['rol'], 'certeza' => $a['certeza']],
      array_filter(json_decode($f['interpretes'] ?: '[]', true) ?: [], fn($a) => !empty($a['visible']))));
    $props = ['id' => $f['uuid'], 'name' => $f['titulo'] ?: $obras[$suyas[0]]['titulo'], 'obras' => $suyas,
      'obras_nombres' => implode(' · ', array_map(fn($u) => $obras[$u]['titulo'], $suyas)),
      'disciplina' => $obras[$suyas[0]]['disciplina'] ?? '', 'inicio' => $f['inicio'], 'fin' => $f['fin'], 'fecha_precision' => $f['fecha_precision'],
      'estado' => $f['estado'], 'isla' => $sens === 'reservada' ? '' : $f['isla'], 'municipio' => $sens === 'reservada' ? '' : $f['municipio'],
      'lugar' => $sens === 'abierta' ? ($f['lugar'] ?: ($s['nombre'] ?? '')) : '', 'descripcion' => $f['descripcion'],
      'precision' => $p ? ($sens === 'abierta' ? 'exacta' : 'zona') : ($f['municipio'] !== '' && $sens !== 'reservada' ? 'municipio' : ($f['lugar'] !== '' && $sens === 'abierta' ? 'lugar' : 'sin_espacio'))];
    if ($s && $sens !== 'reservada') $props['soporte'] = $s['uuid'];
    if ($s && ($s['url'] ?? '') !== '') $props['url'] = $s['url'];  // emisión o web
    if ($f['evento'] && isset($eventos[$f['evento']])) { $props['evento'] = $f['evento']; $props['evento_nombre'] = $eventos[$f['evento']]; }
    if ($int) {
      $props['interpretes'] = $int;
      $props['interpretes_texto'] = implode(', ', array_map(fn($a) => $a['nombre'] . ' (' . mb_strtolower(ROLES_AGENTE[$a['rol']] ?? $a['rol']) . ')', $int));
    }
    $reg = json_decode($f['registros'] ?: '[]', true) ?: [];
    if ($reg) $props['registros'] = $reg;
    $features[] = ['type' => 'Feature', 'geometry' => $p ? ['type' => 'Point', 'coordinates' => $p] : null,
      'properties' => array_filter($props, fn($v) => $v !== '' && $v !== null && $v !== [])];
  }
  return $features;
}

// Eventos publicados, sin geometría: cuántas funciones publicadas tienen y en qué fechas.
function eventos_geojson(): never { enviar_geojson(eventos_geojson_features()); }

function eventos_geojson_features(): array {
  $publicadas = array_count_values(array_filter(array_map(fn($f) => $f['properties']['evento'] ?? null, funciones_geojson_features())));
  $features = [];
  $todos = db()->query('SELECT * FROM eventos WHERE publicada = 1 ORDER BY inicio DESC, nombre')->fetchAll();
  $ids = array_flip(array_column($todos, 'uuid'));
  foreach ($todos as $e) {
    $props = ['id' => $e['uuid'], 'name' => $e['nombre'], 'tipo' => $e['tipo'], 'tipo_nombre' => $e['tipo'] ? TIPOS_EVENTO[$e['tipo']] ?? '' : '',
      'padre' => isset($ids[$e['padre'] ?? '']) ? $e['padre'] : '', 'inicio' => $e['inicio'], 'fin' => $e['fin'], 'escala' => $e['escala'],
      'descripcion' => $e['descripcion'], 'web' => $e['web'], 'funciones' => $publicadas[$e['uuid']] ?? 0];
    $features[] = ['type' => 'Feature', 'geometry' => null, 'properties' => array_filter($props, fn($v) => $v !== '' && $v !== null)];
  }
  return $features;
}

// Obras de referencia publicadas (de fuera de Canarias, sin lugar): ficha mínima.
function referencias_geojson(): never { enviar_geojson(referencias_geojson_features()); }

function referencias_geojson_features(): array {
  $publicadas = array_flip(db()->query('SELECT uuid FROM obras WHERE publicada = 1')->fetchAll(PDO::FETCH_COLUMN));
  $features = [];
  foreach (db()->query("SELECT * FROM obras WHERE publicada = 1 AND ambito = 'referencia' ORDER BY titulo") as $o) {
    $ag = array_values(array_map(fn($a) => ['nombre' => $a['nombre'], 'rol' => $a['rol'], 'certeza' => $a['certeza']],
      array_filter(json_decode($o['agentes'] ?: '[]', true) ?: [], fn($a) => !empty($a['visible']))));
    $props = ['id' => $o['uuid'], 'name' => $o['titulo'], 'referencia' => true, 'version' => (int) $o['version'], 'actualizado' => fecha_iso($o['actualizado'])];
    foreach (['disciplina', 'tipo', 'descripcion', 'autoria', 'fecha_obra', 'fecha_precision', 'foto', 'foto_credito', 'ficha'] as $k) if ($o[$k] !== '') $props[$k] = $o[$k];
    if ($ag) {
      $props['agentes'] = $ag;
      if (empty($props['autoria'])) $props['autoria'] = implode(', ', array_map(fn($a) => $a['nombre'] . ($a['rol'] !== 'autoria' ? ' (' . mb_strtolower(ROLES_AGENTE[$a['rol']] ?? '') . ')' : ''), $ag));
    }
    $rl = array_values(array_filter(json_decode($o['relaciones'] ?: '[]', true) ?: [], fn($r) => isset($publicadas[$r['obra']])));
    if ($rl) $props['relaciones'] = $rl;
    $features[] = ['type' => 'Feature', 'geometry' => null, 'properties' => $props];
  }
  return $features;
}
