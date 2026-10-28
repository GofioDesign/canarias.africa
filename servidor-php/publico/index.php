<?php
// Servidor de canarias.africa en un hosting compartido con PHP (api.canarias.africa).
// La web y las fichas viven en GitHub Pages; aquí solo está lo que necesita servidor: cuentas de colaboradores,
// etiquetas QR (pool, cupos, colocación, verificaciones), formularios, caché de teselas OSM, copia de uMap y moderación.
declare(strict_types=1);
require __DIR__ . '/../privado/lib.php';

$ruta = '/' . trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$metodo = $_SERVER['REQUEST_METHOD'];
cors();
if ($metodo === 'OPTIONS') { http_response_code(204); exit; }
// Los envíos solo se aceptan desde la web oficial (o sin cabecera Origin, como en las pruebas con curl).
if ($metodo === 'POST' && !str_starts_with($ruta, '/admin') && ($_SERVER['HTTP_ORIGIN'] ?? config()['web']) !== config()['web']) {
  error_publico('Origen no permitido.', 403);
}

try {
  if ($metodo === 'GET' && preg_match('#^/teselas/osm/(\d{1,2})/(\d+)/(\d+)\.png$#', $ruta, $m)) teselas((int) $m[1], (int) $m[2], (int) $m[3]);
  if ($metodo === 'GET' && preg_match('#^/datos/umap/(qr|rutas)\.geojson$#', $ruta, $m)) capa_umap($m[1]);
  if ($metodo === 'GET' && preg_match('#^/api/publico/obras/([\w-]+)$#', $ruta, $m)) observaciones_publicas($m[1]);
  if ($metodo === 'GET' && preg_match('#^/api/publico/qr/(' . RE_UUID . ')/historial$#', $ruta, $m)) qr_historial($m[1]);
  if ($metodo === 'GET' && $ruta === '/api/publico/etiquetas.geojson') qr_mapa();
  if ($metodo === 'POST' && $ruta === '/api/qr/asignar') qr_asignar();
  if ($metodo === 'GET' && $ruta === '/api/qr/mis-etiquetas') qr_mis_etiquetas();
  // Después de las rutas fijas de /api/qr/ (mis-etiquetas también encaja en el patrón de un código).
  if ($metodo === 'GET' && preg_match('#^/api/qr/(' . RE_CODIGO . ')$#i', $ruta, $m)) qr_resolver($m[1]);
  if ($metodo === 'POST' && preg_match('#^/api/qr/(' . RE_CODIGO . ')/ampliar$#i', $ruta, $m)) qr_ampliar($m[1]);
  if ($metodo === 'POST' && preg_match('#^/api/qr/(' . RE_CODIGO . ')/aviso$#i', $ruta, $m)) qr_aviso($m[1]);
  if ($metodo === 'POST' && $ruta === '/api/cuenta/alta') cuenta_alta();
  if ($metodo === 'POST' && $ruta === '/api/cuenta/entrar') cuenta_entrar();
  if ($metodo === 'POST' && $ruta === '/api/cuenta/sesion') cuenta_sesion();
  if ($metodo === 'GET' && $ruta === '/api/cuenta/yo') json_respuesta(perfil((int) exigir_colaborador()['id']));
  if ($metodo === 'POST' && $ruta === '/api/collections/colocaciones/records') qr_colocar();
  if ($metodo === 'POST' && $ruta === '/api/collections/verificaciones/records') qr_verificar();
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
    // El estado de una obra solo se observa con su etiqueta delante: el UUID de la etiqueta no se publica.
    $qr = etiqueta((string) ($_POST['qr_codigo'] ?? ''));
    if (!$qr || !in_array($qr['estado'], ESTADOS_EN_SITIO, true)) error_publico('La etiqueta QR no es válida o no está colocada.');
    if ($qr['obra'] !== strtolower((string) $obra)) error_publico('La etiqueta QR no corresponde a esta obra.');
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
