<?php
// Servidor de canarias.africa en un hosting compartido con PHP (api.canarias.africa).
// La web y las fichas viven en GitHub Pages; aquí solo está lo que necesita servidor:
// formularios, QR, observaciones aprobadas, caché de teselas OSM, copia de uMap y moderación.
declare(strict_types=1);
require __DIR__ . '/../privado/lib.php';

$ruta = '/' . trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$metodo = $_SERVER['REQUEST_METHOD'];
cors();
if ($metodo === 'OPTIONS') { http_response_code(204); exit; }

try {
  if ($metodo === 'GET' && preg_match('#^/teselas/osm/(\d{1,2})/(\d+)/(\d+)\.png$#', $ruta, $m)) teselas((int) $m[1], (int) $m[2], (int) $m[3]);
  if ($metodo === 'GET' && preg_match('#^/datos/umap/(qr|rutas)\.geojson$#', $ruta, $m)) capa_umap($m[1]);
  if ($metodo === 'GET' && preg_match('#^/q/([a-z0-9-]{4,40})$#', $ruta, $m)) qr_redirige($m[1]);
  if ($metodo === 'GET' && preg_match('#^/api/publico/obras/([\w-]+)$#', $ruta, $m)) observaciones_publicas($m[1]);
  if ($metodo === 'POST' && preg_match('#^/api/collections/(\w+)/records$#', $ruta, $m)) recibir_envio($m[1]);
  if ($ruta === '/admin' || str_starts_with($ruta, '/admin/')) { require PRIVADO . '/admin.php'; exit; }
  error_publico('Ruta desconocida.', 404);
} catch (Throwable $e) {
  error_log((string) $e);
  error_publico('Error interno.', 500);
}

// Caché de teselas: se llena con las visitas reales, nunca con descargas en bloque
// (https://operations.osmfoundation.org/policies/tiles/). El cron la recorta si pasa del límite.
function teselas(int $z, int $x, int $y): never {
  if ($z > 19 || $x >= 2 ** $z || $y >= 2 ** $z) error_publico('Tesela no válida.');
  $f = PRIVADO . "/cache/teselas/$z/$x/$y.png";
  if (!is_file($f) || filemtime($f) < time() - 30 * 86400) {
    $ch = curl_init("https://tile.openstreetmap.org/$z/$x/$y.png");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
      CURLOPT_USERAGENT => 'canarias.africa tile cache (contacto: ' . config()['contacto'] . ')']);
    $png = curl_exec($ch);
    $ok = curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200 && $png;
    if ($ok) {
      @mkdir(dirname($f), 0700, true);
      file_put_contents("$f.tmp", $png);
      rename("$f.tmp", $f);
    } elseif (!is_file($f)) {
      http_response_code(502);
      exit;
    }
  }
  header('Content-Type: image/png');
  header('Cache-Control: public, max-age=2592000');
  readfile($f);
  exit;
}

function capa_umap(string $nombre): never {
  $f = PRIVADO . "/umap/$nombre.geojson";
  header('Content-Type: application/geo+json');
  header('Cache-Control: no-cache');
  is_file($f) ? readfile($f) : print('{"type":"FeatureCollection","features":[]}');
  exit;
}

function qr_redirige(string $codigo): never {
  $qr = qr_activo($codigo);
  $web = config()['web'];
  header('Location: ' . ($qr ? "$web/#/observar/{$qr['obra']}?qr=" . rawurlencode($codigo) : "$web/#/qr-no-valido"), true, 302);
  exit;
}

// Observaciones aprobadas de una obra. El resto de la ficha (descripción, relaciones…) está en GitHub.
function observaciones_publicas(string $obra): never {
  $st = db()->prepare("SELECT creado, datos FROM envios WHERE formulario = 'observacion' AND obra = ? AND estado = 'aprobada' ORDER BY creado DESC");
  $st->execute([$obra]);
  $obs = array_map(function ($o) {
    $d = json_decode($o['datos'], true);
    return ['fecha' => $o['creado'], 'fase' => $d['fase'] ?? null, 'texto' => $d['texto'] ?? '', 'alias' => $d['alias'] ?? ''];
  }, $st->fetchAll());
  header('Cache-Control: public, max-age=300');
  json_respuesta(['type' => 'Feature', 'geometry' => null, 'properties' => ['id' => $obra, 'observaciones' => $obs]]);
}

// Formularios públicos. Todo entra como pendiente y exige aceptar el aviso de privacidad.
function recibir_envio(string $coleccion): never {
  $formularios = ['observaciones' => 'observacion', 'contexto' => 'contexto', 'propuestas_obra' => 'obra_nueva',
    'propuestas_ruta' => 'ruta', 'inscripciones' => 'inscripcion'];
  $formulario = $formularios[$coleccion] ?? error_publico('Formulario desconocido.', 404);
  if (($_SERVER['HTTP_ORIGIN'] ?? config()['web']) !== config()['web']) error_publico('Origen no permitido.', 403);
  if (!in_array($_POST['acepta_privacidad'] ?? '', ['true', 'on', '1'], true)) {
    error_publico('Hay que aceptar el aviso de privacidad para enviar el formulario.');
  }
  $db = db();
  $ip = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . date('Y-m-d'));
  $st = $db->prepare("SELECT COUNT(*) FROM envios WHERE ip_hash = ? AND creado > datetime('now', '-1 hour')");
  $st->execute([$ip]);
  if ($st->fetchColumn() >= config()['envios_por_hora_e_ip']) error_publico('Demasiados envíos seguidos. Prueba dentro de un rato.', 429);

  $obra = isset($_POST['obra']) ? substr((string) $_POST['obra'], 0, 40) : null;
  if ($formulario === 'observacion') {
    $qr = qr_activo((string) ($_POST['qr_codigo'] ?? ''));
    if (!$qr) error_publico('El código QR no es válido o ya no está activo.');
    if ($qr['obra'] !== $obra) error_publico('El código QR no corresponde a esta obra.');
  }
  $datos = [];
  foreach ($_POST as $k => $v) if (is_string($v)) $datos[substr((string) $k, 0, 60)] = mb_substr($v, 0, 5000);
  $fotos = [];
  foreach ($_FILES as $f) {
    if (is_array($f['error']) || $f['error'] === UPLOAD_ERR_NO_FILE) continue;
    if ($f['error'] !== UPLOAD_ERR_OK || $f['size'] > 15 * 1024 * 1024) error_publico('La foto no ha llegado bien o pasa de 15 MB.');
    $fotos[] = guardar_foto($f['tmp_name'], mime_content_type($f['tmp_name']) ?: '');
  }
  $db->prepare('INSERT INTO envios (formulario, obra, datos, fotos, ip_hash) VALUES (?, ?, ?, ?, ?)')
     ->execute([$formulario, $obra, json_encode($datos, JSON_UNESCAPED_UNICODE), json_encode($fotos), $ip]);
  avisar("canarias.africa: nuevo envío ($formulario)", "Hay un envío pendiente de revisar en " . 'https://' . ($_SERVER['HTTP_HOST'] ?? '') . "/admin\n");
  json_respuesta(['ok' => true, 'estado' => 'pendiente'], 201);
}
