<?php
// Etiquetas QR: pool de códigos, cupos, colocación con GPS y foto, verificaciones independientes y trazabilidad.
// Procedimiento: /mnt/project-files/sistema-qr/procedimiento-colaboradores-qr.md (borrador del 28-10-2026).

const RE_UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';  // obras
// Código de etiqueta: base32 de Crockford en mayúsculas (sin I, L, O, U, para que no se confundan al leerlo), p. ej. 7KQ2M9XD.
// Se empieza con 8 caracteres (40 bits, ~1 billón de códigos); se pueden emitir lotes más largos sin tocar nada más.
const ALFABETO_CODIGO = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
const RE_CODIGO = '[0-9A-Za-z-]{8,20}';  // la ruta acepta guiones y minúsculas; normalizar_codigo() hace el resto

function nuevo_codigo(int $largo = 8): string {
  $c = '';
  for ($i = 0; $i < $largo; $i++) $c .= ALFABETO_CODIGO[random_int(0, 31)];
  return $c;
}

// Lo que se teclea o se lee del glifo: en mayúsculas, sin espacios ni guiones, y con O→0, I/L→1 (regla de Crockford).
function normalizar_codigo(string $c): string {
  return strtr(preg_replace('/[\s-]+/', '', strtoupper($c)), ['O' => '0', 'I' => '1', 'L' => '1']);
}
const ESTADOS_EN_SITIO = ['colocado', 'confirmado', 'sin_verificar', 'desaparecida'];

function uuid4(): string {
  $b = random_bytes(16);
  $b[6] = chr(ord($b[6]) & 0x0f | 0x40);
  $b[8] = chr(ord($b[8]) & 0x3f | 0x80);
  return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

function distancia_m(float $lat1, float $lon1, float $lat2, float $lon2): float {
  $r = 6371000;
  $dlat = deg2rad($lat2 - $lat1);
  $dlon = deg2rad($lon2 - $lon1);
  $a = sin($dlat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dlon / 2) ** 2;
  return 2 * $r * asin(min(1, sqrt($a)));
}

function etiqueta(string $codigo): ?array {
  $st = db()->prepare('SELECT * FROM qr_codigos WHERE codigo = ?');
  $st->execute([normalizar_codigo($codigo)]);
  return $st->fetch() ?: null;
}

function evento_qr(string $qr, string $tipo, ?int $colaborador = null, array $datos = []): void {
  db()->prepare('INSERT INTO qr_eventos (qr, tipo, colaborador, datos) VALUES (?, ?, ?, ?)')
      ->execute([$qr, $tipo, $colaborador, json_encode($datos, JSON_UNESCAPED_UNICODE)]);
}

function cambiar_estado(string $qr, string $estado, ?int $colaborador = null, array $datos = []): void {
  db()->prepare('UPDATE qr_codigos SET estado = ? WHERE codigo = ?')->execute([$estado, $qr]);
  evento_qr($qr, $estado, $colaborador, $datos);
}

function gps(): array {
  $lat = filter_var($_POST['lat'] ?? null, FILTER_VALIDATE_FLOAT);
  $lon = filter_var($_POST['lon'] ?? null, FILTER_VALIDATE_FLOAT);
  $pre = filter_var($_POST['precision'] ?? null, FILTER_VALIDATE_FLOAT);
  if ($lat === false || $lon === false || $pre === false || abs($lat) > 90 || abs($lon) > 180 || $pre <= 0) {
    error_publico('Falta la posición GPS (lat, lon y precision en metros).');
  }
  return [$lat, $lon, $pre];
}

function foto_subida(bool $obligatoria): ?string {
  $f = $_FILES['foto'] ?? null;
  if (!$f || is_array($f['error']) || $f['error'] === UPLOAD_ERR_NO_FILE) {
    if ($obligatoria) error_publico('Falta la foto de la etiqueta colocada.');
    return null;
  }
  if ($f['error'] !== UPLOAD_ERR_OK || $f['size'] > 15 * 1024 * 1024) error_publico('La foto no ha llegado bien o pasa de 15 MB.');
  return guardar_foto($f['tmp_name'], mime_content_type($f['tmp_name']) ?: '');
}

// GET /api/qr/<código> → {soporte, obras, obra, estado}. Es lo que pide la web al leer el glifo de una etiqueta.
// La etiqueta identifica un soporte (una pared, una roca… o una web), y en él puede haber varias obras, de ahora y de antes.
function qr_resolver(string $codigo): never {
  $q = etiqueta($codigo);
  if (!$q) json_respuesta(['obra' => null, 'soporte' => null, 'obras' => [], 'estado' => 'desconocido'], 404);
  $s = $q['soporte'] ? soporte($q['soporte']) : null;
  $obras = [];
  if ($s) foreach (obras_de_soporte($s['uuid'], true) as $o) {
    $obras[] = array_filter(['uuid' => $o['obra'], 'titulo' => $o['titulo'], 'actual' => $o['hasta'] === '', 'desde' => $o['desde'], 'hasta' => $o['hasta']], fn($v) => $v !== '');
  }
  $actual = array_values(array_filter($obras, fn($o) => $o['actual']));
  // gps: la etiqueta tiene punto y se verifica con el GPS (no lo tiene una que solo se muestra en pantalla).
  $r = ['estado' => $q['estado'], 'gps' => $q['lat'] !== null, 'obra' => $actual[0]['uuid'] ?? $obras[0]['uuid'] ?? $q['obra'], 'obras' => $obras, 'soporte' => null];
  if ($s) {
    $r['soporte'] = array_filter(['uuid' => $s['uuid'], 'nombre' => $s['nombre'], 'tipo' => $s['tipo'], 'url' => $s['url'] ?? '',
      'municipio' => sensibilidad_soporte($s) === 'reservada' ? '' : $s['municipio']], fn($v) => $v !== '');
  }
  json_respuesta($r);
}

// El QR del azulejo apunta a canarias.africa/<UUID DEL SOPORTE> (en mayúsculas, que ocupa menos en el QR).
// Devuelve lo mismo que qr_resolver para que la web lo pinte igual. Los soportes reservados no se resuelven.
function soporte_resolver(string $uuid): never {
  $s = soporte(strtolower($uuid));
  if (!$s || sensibilidad_soporte($s) === 'reservada') json_respuesta(['obra' => null, 'soporte' => null, 'obras' => [], 'estado' => 'desconocido'], 404);
  $obras = [];
  foreach (obras_de_soporte($s['uuid'], true) as $o) {
    $obras[] = array_filter(['uuid' => $o['obra'], 'titulo' => $o['titulo'], 'actual' => $o['hasta'] === '', 'desde' => $o['desde'], 'hasta' => $o['hasta']], fn($v) => $v !== '');
  }
  $actual = array_values(array_filter($obras, fn($o) => $o['actual']));
  $st = db()->prepare("SELECT estado FROM qr_codigos WHERE soporte = ? AND estado IN ('colocado','confirmado','sin_verificar','desaparecida','danado') ORDER BY creado DESC LIMIT 1");
  $st->execute([$s['uuid']]);
  header('Cache-Control: public, max-age=300');
  json_respuesta(['estado' => $st->fetchColumn() ?: 'sin_etiqueta', 'gps' => $s['lat'] !== null, 'obra' => $actual[0]['uuid'] ?? $obras[0]['uuid'] ?? null, 'obras' => $obras,
    'soporte' => array_filter(['uuid' => $s['uuid'], 'nombre' => $s['nombre'], 'tipo' => $s['tipo'], 'url' => $s['url'] ?? '', 'municipio' => $s['municipio']], fn($v) => $v !== '')]);
}

function qr_asignar(): never {
  $c = exigir_colaborador();
  $p = perfil((int) $c['id']);
  $n = min($p['disponibles'], max(1, (int) (cuerpo_json()['cantidad'] ?? $p['disponibles'])));
  if ($n < 1) error_publico('Ya tienes todo tu cupo asignado. Coloca alguna etiqueta para pedir más.', 409);
  $db = db();
  $db->beginTransaction();
  $libres = $db->query("SELECT codigo FROM qr_codigos WHERE estado = 'libre' ORDER BY creado, codigo LIMIT $n")->fetchAll(PDO::FETCH_COLUMN);
  if (!$libres) { $db->rollBack(); error_publico('No quedan etiquetas libres ahora mismo. Avisaremos cuando haya un lote nuevo.', 503); }
  $dias = (int) config()['qr']['dias_plazo'];
  foreach ($libres as $u) {
    $db->prepare("INSERT INTO asignaciones (qr, colaborador, vence) VALUES (?, ?, datetime('now', ?))")->execute([$u, $c['id'], "+$dias days"]);
    cambiar_estado($u, 'asignado', (int) $c['id']);
  }
  $db->commit();
  json_respuesta(perfil((int) $c['id']));
}

// GET /api/qr/mis-etiquetas: etiquetas asignadas para imprimir. Al pedirlas quedan marcadas como descargadas,
// así que si caducan ya no vuelven al pool (opción a): solo las puede reactivar la misma persona al colocarlas.
function qr_mis_etiquetas(): never {
  $c = exigir_colaborador();
  $db = db();
  $st = $db->prepare("SELECT a.id, a.qr, a.vence FROM asignaciones a JOIN qr_codigos q ON q.codigo = a.qr
                      WHERE a.colaborador = ? AND a.resultado IS NULL AND q.estado = 'asignado'");
  $st->execute([$c['id']]);
  $filas = $st->fetchAll();
  $db->prepare('UPDATE asignaciones SET descargada = 1 WHERE colaborador = ? AND resultado IS NULL')->execute([$c['id']]);
  json_respuesta(array_map(fn($a) => ['codigo' => $a['qr'], 'url' => config()['web'] . '/' . $a['qr'], 'vence' => $a['vence']], $filas));
}

// POST /api/qr/<código>/ampliar: una sola ampliación del plazo por asignación.
function qr_ampliar(string $codigo): never {
  $c = exigir_colaborador();
  $dias = (int) config()['qr']['dias_plazo'];
  $st = db()->prepare("UPDATE asignaciones SET vence = datetime(vence, ?), ampliada = 1
                       WHERE qr = ? AND colaborador = ? AND resultado IS NULL AND ampliada = 0");
  $st->execute(["+$dias days", normalizar_codigo($codigo), $c['id']]);
  if (!$st->rowCount()) error_publico('Esta etiqueta no es tuya o su plazo ya se amplió una vez.', 409);
  evento_qr(normalizar_codigo($codigo), 'plazo_ampliado', (int) $c['id']);
  json_respuesta(perfil((int) $c['id']));
}

// POST /api/collections/colocaciones/records (multipart): qr, lat, lon, precision, foto y
// · soporte (UUID de un soporte que ya existe, si la etiqueta va en él) o soporte_nombre/soporte_tipo para uno nuevo en este punto;
// · obra (UUID del catálogo) u obra_nueva_*: la obra que hay en el soporte. Con un soporte existente es opcional.
function qr_colocar(): never {
  $c = exigir_colaborador();
  $db = db();
  $q = etiqueta((string) ($_POST['qr'] ?? '')) ?? error_publico('Esa etiqueta no existe.', 404);
  $st = $db->prepare("SELECT * FROM asignaciones WHERE qr = ? AND colaborador = ? ORDER BY id DESC LIMIT 1");
  $st->execute([$q['codigo'], $c['id']]);
  $a = $st->fetch();
  $abierta = $a && $a['resultado'] === null && $q['estado'] === 'asignado';
  $reactivable = $a && $a['resultado'] === 'caducado' && $a['descargada'] && $q['estado'] === 'caducado';
  if (!$abierta && !$reactivable) error_publico('Esta etiqueta no está asignada a tu cuenta o ya está colocada.', 409);
  [$lat, $lon, $pre] = gps();
  $max = config()['qr']['precision_colocar_m'];
  if ($pre > $max) error_publico("La precisión del GPS es de " . round($pre) . " m y hace falta $max m o mejor. Espera unos segundos al aire libre y vuelve a intentarlo.");

  $soporte = null;
  if (!empty($_POST['soporte'])) {
    $soporte = soporte((string) $_POST['soporte']) ?? error_publico('Ese soporte no está en el archivo.', 404);
    // Un soporte sin punto (una web) puede tener etiquetas físicas en cualquier sitio (un cartel, un local): cada una
    // guarda su propio punto y se verifica con el GPS. Si el soporte tiene punto, hay que estar junto a él.
    if ($soporte['lat'] !== null && distancia_m((float) $soporte['lat'], (float) $soporte['lon'], $lat, $lon) > config()['qr']['radio_verificar_m'] + $pre) {
      error_publico('Estás demasiado lejos de ese soporte. Si es otro sitio, crea un soporte nuevo.');
    }
    if (sensibilidad_soporte($soporte) === 'reservada') error_publico('Los soportes con obras de ubicación reservada no llevan etiqueta.', 403);
  }

  $obra_uuid = null;
  $obra = null;
  if (!empty($_POST['obra'])) {
    $st = $db->prepare('SELECT * FROM obras WHERE uuid = ?');
    $st->execute([strtolower((string) $_POST['obra'])]);
    $obra = $st->fetch() ?: error_publico('Esa obra no está en el archivo.', 404);
    if ($obra['sensibilidad'] === 'reservada') error_publico('Las obras con ubicación reservada no llevan etiqueta.', 403);
    $obra_uuid = $obra['uuid'];
  } elseif (trim((string) ($_POST['obra_nueva_titulo'] ?? '')) !== '') {
    if (($_POST['obra_nueva_sensibilidad'] ?? '') === 'reservada') error_publico('Las obras con ubicación reservada no llevan etiqueta.', 403);
    $obra_uuid = uuid4();
    $datos = [];
    foreach ($_POST as $k => $v) if (is_string($v) && str_starts_with($k, 'obra_nueva_')) $datos[substr($k, 11)] = mb_substr($v, 0, 5000);
    $db->prepare('INSERT INTO obras_propuestas (uuid, colaborador, datos) VALUES (?, ?, ?)')
       ->execute([$obra_uuid, $c['id'], json_encode($datos, JSON_UNESCAPED_UNICODE)]);
  } elseif (!$soporte) {
    error_publico('Elige el soporte o la obra del archivo, o describe la obra nueva.');
  }
  if (!in_array((string) ($_POST['acepta_derechos'] ?? ''), ['1', 'true', 'on'], true)) error_publico('Hay que aceptar las condiciones de uso de la foto.');
  $foto = foto_subida(true);

  $db->beginTransaction();
  if (!$soporte) {
    $nombre = trim((string) ($_POST['soporte_nombre'] ?? '')) ?: ($obra ? 'Soporte de «' . $obra['titulo'] . '»' : 'Soporte de «' . trim((string) $_POST['obra_nueva_titulo']) . '»');
    $sens = $obra['sensibilidad'] ?? (in_array($_POST['obra_nueva_sensibilidad'] ?? '', SENSIBILIDADES, true) ? $_POST['obra_nueva_sensibilidad'] : 'abierta');
    $soporte = soporte(soporte_crear($lat, $lon, $nombre, (string) ($_POST['soporte_tipo'] ?? ''), $sens, $obra ?: []));
  }
  if ($obra_uuid) enlazar_obra_soporte($obra_uuid, $soporte['uuid']);
  // Si el soporte tenía una etiqueta dañada, retirada o desaparecida, esta la sustituye.
  $st = $db->prepare("SELECT codigo FROM qr_codigos WHERE (soporte = ? OR (soporte IS NULL AND obra = ?)) AND estado IN ('danado','retirado','desaparecida') AND codigo != ?");
  $st->execute([$soporte['uuid'], $obra_uuid ?? '', $q['codigo']]);
  $sustituida = $st->fetchColumn() ?: null;
  if ($sustituida) cambiar_estado($sustituida, 'sustituido', (int) $c['id'], ['por' => 'nueva etiqueta']);
  $db->prepare('INSERT INTO colocaciones (qr, obra, colaborador, lat, lon, precision, foto) VALUES (?, ?, ?, ?, ?, ?, ?)')
     ->execute([$q['codigo'], $obra_uuid ?? '', $c['id'], $lat, $lon, $pre, $foto]);
  $db->prepare('UPDATE qr_codigos SET obra = ?, soporte = ?, lat = ?, lon = ?, precision = ?, sustituye = ? WHERE codigo = ?')
     ->execute([$obra_uuid, $soporte['uuid'], $lat, $lon, $pre, $sustituida, $q['codigo']]);
  $db->prepare("UPDATE asignaciones SET resultado = 'colocado' WHERE id = ?")->execute([$a['id']]);
  cambiar_estado($q['codigo'], 'colocado', (int) $c['id'], ['precision_m' => round($pre)]);
  $db->commit();
  json_respuesta(['ok' => true, 'soporte' => $soporte['uuid'], 'obra' => $obra_uuid, 'estado' => 'colocado'], 201);
}

// /admin: da de alta una etiqueta libre en un soporte, para etiquetas que se colocaron sin la app o que solo se muestran
// en pantalla (dentro de la propia web). Una etiqueta impresa de una web se coloca con la app, con GPS, como las demás.
function qr_vincular_soporte(string $codigo, array $s): void {
  $q = etiqueta($codigo) ?? throw new InvalidArgumentException('Esa etiqueta no existe.');
  if ($q['estado'] !== 'libre') throw new InvalidArgumentException('Esa etiqueta no está libre (estado: ' . $q['estado'] . ').');
  $web = $s['lat'] === null;
  db()->prepare('UPDATE qr_codigos SET soporte = ?, obra = ?, lat = ?, lon = ?, precision = ? WHERE codigo = ?')
      ->execute([$s['uuid'], obras_de_soporte($s['uuid'])[0]['obra'] ?? null, $s['lat'], $s['lon'], $web ? null : 0, $q['codigo']]);
  cambiar_estado($q['codigo'], $web ? 'confirmado' : 'colocado', null, ['motivo' => 'alta en /admin']);
}

// POST /api/collections/verificaciones/records (multipart): qr, lat, lon, precision, dispositivo, foto (opcional).
// Solo se guarda la distancia al punto de la etiqueta, nunca la posición de quien escanea.
function qr_verificar(): never {
  $db = db();
  $cfg = config()['qr'];
  $c = colaborador_actual();
  if (!$c && !$cfg['visitantes_verifican']) error_publico('Para verificar etiquetas hay que entrar con una cuenta.', 401);
  $q = etiqueta((string) ($_POST['qr'] ?? '')) ?? error_publico('Esa etiqueta no existe.', 404);
  if (!in_array($q['estado'], ESTADOS_EN_SITIO, true)) error_publico('Esta etiqueta no está colocada.', 409);
  if ($q['lat'] === null) error_publico('Esta etiqueta es de un soporte sin punto (por ejemplo, una web): no se verifica con el GPS.', 409);
  $disp = (string) ($_POST['dispositivo'] ?? '');
  if (!preg_match('/^[\w-]{16,64}$/', $disp)) error_publico('Falta el identificador del dispositivo.');
  [$lat, $lon, $pre] = gps();

  $st = $db->prepare('SELECT colaborador FROM colocaciones WHERE qr = ? ORDER BY id DESC LIMIT 1');
  $st->execute([$q['codigo']]);
  $coloco = (int) $st->fetchColumn();
  if ($c && (int) $c['id'] === $coloco) error_publico('Quien coloca una etiqueta no puede verificarla.', 403);
  $st = $db->prepare("SELECT COUNT(*) FROM verificaciones WHERE qr = ? AND (dispositivo = ? OR (colaborador IS NOT NULL AND colaborador = ?))
                      AND strftime('%Y-%m', fecha) = strftime('%Y-%m', 'now')");
  $st->execute([$q['codigo'], $disp, $c['id'] ?? -1]);
  if ($st->fetchColumn()) error_publico('Ya has verificado esta etiqueta este mes. ¡Gracias!', 409);

  $dist = distancia_m($q['lat'], $q['lon'], $lat, $lon);
  $radio = $cfg['radio_verificar_m'];
  $motivo = $dist > $radio ? 'fuera_del_radio' : ($pre > $radio ? 'gps_impreciso' : null);
  $nivel = nivel($c);
  $foto = foto_subida(false);
  $db->beginTransaction();
  $db->prepare('INSERT INTO verificaciones (qr, colaborador, dispositivo, distancia, precision, foto, peso, valida, motivo) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
     ->execute([$q['codigo'], $c['id'] ?? null, $disp, round($dist, 1), $pre, $foto, NIVELES[$nivel], $motivo ? 0 : 1, $motivo]);
  $vid = (string) $db->lastInsertId();
  evento_qr($q['codigo'], $motivo ? 'verificacion_no_valida' : 'verificacion', $c['id'] ?? null, ['distancia_m' => round($dist), 'motivo' => $motivo]);

  $estado = $q['estado'];
  if (!$motivo) {
    $db->prepare("UPDATE qr_codigos SET ultima_verificacion = datetime('now') WHERE codigo = ?")->execute([$q['codigo']]);
    if (in_array($estado, ['sin_verificar', 'desaparecida'], true)) {
      cambiar_estado($q['codigo'], $estado = 'confirmado', $c['id'] ?? null, ['motivo' => 'nueva verificación']);
    } elseif ($estado === 'colocado') {
      // Verificaciones válidas independientes: cada cuenta o dispositivo cuenta una vez.
      $st = $db->prepare("SELECT COUNT(DISTINCT COALESCE('c' || colaborador, 'd' || dispositivo)),
                                 MAX(peso >= ?) FROM verificaciones WHERE qr = ? AND valida = 1");
      $st->execute([NIVELES['custodia'], $q['codigo']]);
      [$independientes, $hay_custodia] = $st->fetch(PDO::FETCH_NUM);
      if ($independientes >= $cfg['verificaciones_para_confirmar'] || $hay_custodia) {
        cambiar_estado($q['codigo'], $estado = 'confirmado');
        sumar_meritos($coloco, 'colocacion_confirmada', 5, $q['codigo']);
        $st = $db->prepare('SELECT id, colaborador FROM verificaciones WHERE qr = ? AND valida = 1 AND colaborador IS NOT NULL');
        $st->execute([$q['codigo']]);
        foreach ($st->fetchAll() as $v) sumar_meritos((int) $v['colaborador'], 'verificacion', 1, (string) $v['id']);
      }
    } elseif ($c) {
      sumar_meritos((int) $c['id'], 'verificacion', 1, $vid);
    }
  }
  $db->commit();
  json_respuesta(['ok' => true, 'valida' => !$motivo, 'motivo' => $motivo, 'distancia_m' => round($dist), 'estado' => $estado], 201);
}

// POST /api/qr/<código>/aviso {tipo: danado|desaparecida|retirado, texto}: cualquiera puede avisar.
// El aviso queda en el historial; el estado solo cambia si lo marca alguien de nivel Custodia.
function qr_aviso(string $codigo): never {
  $q = etiqueta($codigo) ?? error_publico('Esa etiqueta no existe.', 404);
  $d = cuerpo_json();
  $tipo = (string) ($d['tipo'] ?? '');
  if (!in_array($tipo, ['danado', 'desaparecida', 'retirado'], true)) error_publico('Tipo de aviso no válido.');
  $c = colaborador_actual();
  $texto = mb_substr((string) ($d['texto'] ?? ''), 0, 1000);
  if ($c && nivel($c) === 'custodia' && in_array($q['estado'], ESTADOS_EN_SITIO, true)) {
    cambiar_estado($q['codigo'], $tipo, (int) $c['id'], ['texto' => $texto]);
  } else {
    evento_qr($q['codigo'], "aviso_$tipo", $c['id'] ?? null, ['texto' => $texto]);
  }
  json_respuesta(['ok' => true], 201);
}

// Asignaciones vencidas: si las etiquetas no se descargaron vuelven al pool; si se descargaron quedan «caducadas»
// y solo las puede reactivar la misma persona al colocarlas (opción a del procedimiento). Resta 1 mérito.
function caducar_asignaciones(): void {
  $db = db();
  $vencidas = $db->query("SELECT a.* FROM asignaciones a JOIN qr_codigos q ON q.codigo = a.qr
                          WHERE a.resultado IS NULL AND q.estado = 'asignado' AND a.vence < datetime('now')")->fetchAll();
  foreach ($vencidas as $a) {
    $db->beginTransaction();
    $db->prepare("UPDATE asignaciones SET resultado = 'caducado' WHERE id = ?")->execute([$a['id']]);
    cambiar_estado($a['qr'], $a['descargada'] ? 'caducado' : 'libre', (int) $a['colaborador'], ['motivo' => 'plazo vencido']);
    sumar_meritos((int) $a['colaborador'], 'qr_caducado', -1, (string) $a['id']);
    $db->commit();
  }
}

// Sin verificaciones en 6 meses: «sin verificar». En 18 meses: «desaparecida». Una verificación nueva la devuelve a «confirmada».
function envejecer_etiquetas(): void {
  $cfg = config()['qr'];
  $db = db();
  foreach ([['confirmado', 'sin_verificar', $cfg['meses_sin_verificar']], ['sin_verificar', 'desaparecida', $cfg['meses_desaparecida']]] as [$de, $a, $meses]) {
    // Las etiquetas de soportes sin punto (webs) no se verifican con GPS: no envejecen.
    $st = $db->prepare("SELECT codigo FROM qr_codigos WHERE estado = ? AND lat IS NOT NULL AND COALESCE(ultima_verificacion, creado) < datetime('now', ?)");
    $st->execute([$de, "-$meses months"]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $u) cambiar_estado($u, $a, null, ['motivo' => "sin verificaciones en $meses meses"]);
  }
}

function confianza(string $qr): float {
  $st = db()->prepare("SELECT peso, julianday('now') - julianday(fecha) AS dias FROM verificaciones WHERE qr = ? AND valida = 1");
  $st->execute([$qr]);
  return round(array_sum(array_map(fn($v) => $v['peso'] * 0.5 ** ($v['dias'] / 365), $st->fetchAll())), 2);
}

// GET /api/publico/qr/<uuid-obra>/historial: historial público de las etiquetas de una obra (las de sus soportes).
// No incluye el código de las etiquetas, ni coordenadas de personas, ni correos.
function qr_historial(string $obra): never {
  $db = db();
  $st = $db->prepare("SELECT * FROM qr_codigos WHERE obra = ? OR soporte IN (SELECT soporte FROM obra_soporte WHERE obra = ?) ORDER BY creado");
  $st->execute([strtolower($obra), strtolower($obra)]);
  $etiquetas = [];
  foreach ($st->fetchAll() as $i => $q) {
    $ev = $db->prepare("SELECT e.tipo, e.fecha, e.datos, c.alias FROM qr_eventos e LEFT JOIN colaboradores c ON c.id = e.colaborador
                        WHERE e.qr = ? AND e.tipo NOT IN ('asignado','plazo_ampliado','caducado','libre') ORDER BY e.fecha, e.id");
    $ev->execute([$q['codigo']]);
    $etiquetas[] = [
      'etiqueta' => $i + 1,
      'estado' => $q['estado'],
      'ultima_verificacion' => $q['ultima_verificacion'],
      'confianza' => confianza($q['codigo']),
      'eventos' => array_map(function ($e) {
        $d = json_decode($e['datos'], true);
        $quien = $e['alias'] ?? (str_starts_with($e['tipo'], 'verificacion') || str_starts_with($e['tipo'], 'aviso_') ? 'visitante' : null);
        return ['tipo' => $e['tipo'], 'fecha' => $e['fecha']] + ($quien ? ['alias' => $quien] : [])
          + (isset($d['distancia_m']) ? ['distancia_m' => $d['distancia_m']] : []) + (isset($d['motivo']) && $d['motivo'] ? ['motivo' => $d['motivo']] : []);
      }, $ev->fetchAll()),
    ];
  }
  header('Cache-Control: public, max-age=120');
  json_respuesta(['obra' => strtolower($obra), 'etiquetas' => $etiquetas]);
}

// GET /api/publico/etiquetas.geojson y /datos/soportes.geojson (antes qr.geojson): un punto por soporte con etiqueta
// u obras publicadas, ya ocultado según la protección. Ver soportes_features() en soportes.php.
function qr_mapa(): never { enviar_geojson(soportes_features()); }

function qr_mapa_features(): array { return soportes_features(); }
