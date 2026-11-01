<?php
// Huellas de espectadores: cada visita a la ficha deja un trazo anónimo en la obra.
// Solo se traza con la sesión iniciada (decisión del 01-11-2026): sin cuenta no se registra nada.
// Presencia (decisión del 01-11-2026): en las obras con representación física la visita solo cuenta, como trazo o
// como calor, si el GPS confirma que se está junto a la obra. Las coordenadas se comparan y se olvidan: no se guardan.
// Las obras de internet o sin espacio cuentan una vez por persona y día, sin GPS.
//   POST /api/obras/<uuid>/huella           {lat, lon, precision, qr?} con sesión → {contada, motivo?}
//   POST /api/publico/obras/<uuid>/visita   {lat, lon, precision} sin sesión → {contada, motivo?} (solo mapa de calor)
//   GET  /api/publico/obras/<uuid>/huellas  {visitas, escaneos, verificaciones, observaciones, meses: {AAAA-MM: n}, calor_meses: {AAAA-MM: n}}
//        meses: huellas con sesión; calor_meses: visitas sin sesión, solo meses con CALOR_MINIMO o más
//   GET  /api/publico/calor                  {desde, obras: {<uuid>: n}} de los últimos 90 días; solo obras con 5 o más

const CALOR_MINIMO = 5;   // por debajo no se publica: con tan pocas visitas se podría adivinar quién fue
const CALOR_DIAS = 90;
const PRESENCIA_M = 150;        // a cuántos metros de la obra (o de su etiqueta) cuenta como «aquí»
const PRECISION_MAX_M = 250;     // un GPS menos preciso que esto no confirma nada

function metros(float $lat1, float $lon1, float $lat2, float $lon2): float {
  $r = M_PI / 180;
  $a = sin(($lat2 - $lat1) * $r / 2) ** 2 + cos($lat1 * $r) * cos($lat2 * $r) * sin(($lon2 - $lon1) * $r / 2) ** 2;
  return 6371000 * 2 * asin(min(1, sqrt($a)));
}

// ¿Tiene la obra representación física? No la tiene si su soporte actual es una web sin punto (una obra de internet).
// Una realidad aumentada es digital pero está anclada a un lugar: tiene punto y cuenta como física.
function obra_fisica(string $obra): bool {
  $s = soporte_de_obra($obra);
  return !($s && $s['lat'] === null && ($s['url'] ?? '') !== '');
}

// '' si el GPS confirma la presencia; si no, el motivo. Se compara con los puntos exactos que guarda el servidor (la obra,
// su soporte actual y las etiquetas colocadas), nunca con los aproximados que se publican.
function presencia(string $obra, array $d): string {
  if (!isset($d['lat'], $d['lon']) || !is_numeric($d['lat']) || !is_numeric($d['lon'])) return 'sin_gps';
  [$lat, $lon] = [(float) $d['lat'], (float) $d['lon']];
  if (abs($lat) > 90 || abs($lon) > 180) return 'sin_gps';
  $prec = is_numeric($d['precision'] ?? null) ? max(0, (float) $d['precision']) : PRECISION_MAX_M + 1;
  if ($prec > PRECISION_MAX_M) return 'gps_impreciso';
  $db = db();
  $sitios = [];
  $st = $db->prepare('SELECT lat, lon FROM obras WHERE uuid = ? AND lat IS NOT NULL');
  $st->execute([$obra]);
  $sitios = array_merge($sitios, $st->fetchAll());
  if (($s = soporte_de_obra($obra)) && $s['lat'] !== null) $sitios[] = ['lat' => $s['lat'], 'lon' => $s['lon']];
  $st = $db->prepare("SELECT q.lat, q.lon FROM qr_codigos q LEFT JOIN obra_soporte os ON os.soporte = q.soporte AND os.hasta = ''
                      WHERE (q.obra = ? OR os.obra = ?) AND q.lat IS NOT NULL AND q.estado IN ('" . implode("','", ESTADOS_EN_SITIO) . "')");
  $st->execute([$obra, $obra]);
  $sitios = array_merge($sitios, $st->fetchAll());
  if (!$sitios) return 'sin_sitio';
  foreach ($sitios as $x) if (metros($lat, $lon, (float) $x['lat'], (float) $x['lon']) <= PRESENCIA_M + min($prec, 100)) return '';
  return 'lejos';
}

// Suma una huella. Devuelve '' si cuenta, o el motivo por el que no.
function sumar_huella(string $obra, string $tipo, array $gps = []): string {
  $yo = colaborador_actual();
  if (!$yo && $tipo !== 'anonima') return 'sin_sesion';  // sin sesión no hay huella
  $obra = strtolower($obra);
  $dia = gmdate('Y-m-d');
  $db = db();
  $st = $db->prepare('SELECT 1 FROM obras WHERE uuid = ?');
  $st->execute([$obra]);
  if (!$st->fetchColumn()) return 'no_existe';
  if (obra_fisica($obra) && ($motivo = presencia($obra, $gps)) !== '') return $motivo;
  // Una huella por persona, obra, tipo y día. La sal cambia cada día y no se guarda: el hash no dice quién fue.
  $sal = hash('sha256', $dia . (config()['admin_hash'] ?? '') . PRIVADO);
  $quien = $yo ? 'c' . $yo['id'] : 'a' . ($_SERVER['REMOTE_ADDR'] ?? '') . ($_SERVER['HTTP_USER_AGENT'] ?? '');
  $h = hash('sha256', $sal . $quien . $obra . $tipo);
  $st = $db->prepare('INSERT OR IGNORE INTO huellas_vistas (hash, dia) VALUES (?, ?)');
  $st->execute([$h, $dia]);
  if (!$st->rowCount()) return 'ya_hoy';
  $db->prepare('INSERT INTO huellas (obra, dia, tipo, n) VALUES (?, ?, ?, 1) ON CONFLICT(obra, dia, tipo) DO UPDATE SET n = n + 1')
     ->execute([$obra, $dia, $tipo]);
  return '';
}

function respuesta_huella(string $motivo): never {
  json_respuesta($motivo === '' ? ['contada' => true] : ['contada' => false, 'motivo' => $motivo]);
}

function huella_visita(string $obra): never {
  exigir_colaborador();
  $d = cuerpo_json();
  respuesta_huella(sumar_huella($obra, empty($d['qr']) ? 'visita' : 'escaneo', $d));
}

function visita_anonima(string $obra): never {
  if (colaborador_actual()) respuesta_huella('con_sesion');
  respuesta_huella(sumar_huella($obra, 'anonima', cuerpo_json()));
}

function calor(): never {
  $st = db()->prepare("SELECT obra, SUM(n) AS n FROM huellas WHERE tipo = 'anonima' AND dia >= date('now', ?) GROUP BY obra HAVING SUM(n) >= CAST(? AS INTEGER)");
  $st->execute(['-' . CALOR_DIAS . ' days', CALOR_MINIMO]);
  header('Cache-Control: public, max-age=3600');
  json_respuesta(['desde' => gmdate('Y-m-d', time() - CALOR_DIAS * 86400), 'obras' => array_map('intval', $st->fetchAll(PDO::FETCH_KEY_PAIR))]);
}

function huellas_publicas(string $obra): never {
  $obra = strtolower($obra);
  $db = db();
  $st = $db->prepare("SELECT tipo, SUM(n) FROM huellas WHERE obra = ? AND tipo <> 'anonima' GROUP BY tipo");
  $st->execute([$obra]);
  $t = $st->fetchAll(PDO::FETCH_KEY_PAIR);
  $st = $db->prepare("SELECT substr(dia, 1, 7), SUM(n) FROM huellas WHERE obra = ? AND tipo <> 'anonima' GROUP BY 1 ORDER BY 1");
  $st->execute([$obra]);
  $meses = array_map('intval', $st->fetchAll(PDO::FETCH_KEY_PAIR));
  // Calor en el tiempo: visitas sin sesión por mes, solo los meses que llegan al mínimo (no dice quién ni dónde).
  $st = $db->prepare("SELECT substr(dia, 1, 7) AS mes, SUM(n) AS n FROM huellas WHERE obra = ? AND tipo = 'anonima' GROUP BY 1 HAVING SUM(n) >= CAST(? AS INTEGER) ORDER BY 1");
  $st->execute([$obra, CALOR_MINIMO]);
  $calor_meses = array_map('intval', $st->fetchAll(PDO::FETCH_KEY_PAIR));
  $st = $db->prepare("SELECT COUNT(*) FROM verificaciones v JOIN qr_codigos q ON q.codigo = v.qr WHERE q.obra = ? AND v.valida = 1");
  $st->execute([$obra]);
  $verificaciones = (int) $st->fetchColumn();
  $st = $db->prepare("SELECT COUNT(*) FROM envios WHERE obra = ? AND formulario IN ('observacion','contexto') AND estado = 'aprobada'");
  $st->execute([$obra]);
  header('Cache-Control: public, max-age=600');
  json_respuesta(['obra' => $obra, 'visitas' => (int) ($t['visita'] ?? 0), 'escaneos' => (int) ($t['escaneo'] ?? 0),
                  'verificaciones' => $verificaciones, 'observaciones' => (int) $st->fetchColumn(), 'meses' => $meses ?: new stdClass,
                  'calor_meses' => $calor_meses ?: new stdClass]);
}
