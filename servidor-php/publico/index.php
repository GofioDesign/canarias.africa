<?php
// Servidor de canarias.africa en un hosting compartido con PHP (api.canarias.africa).
// La web y las fichas viven en GitHub Pages; aquí solo está lo que necesita servidor: cuentas de colaboradores,
// etiquetas QR (pool, cupos, colocación, verificaciones), lista de correo y votos del lanzamiento, formularios, caché de teselas OSM, catálogo de obras, rutas y artistas (fuente de los datos que muestran la web y uMap) y moderación.
declare(strict_types=1);
require __DIR__ . '/../privado/lib.php';

$ruta = '/' . trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$metodo = $_SERVER['REQUEST_METHOD'];
// /datos/ es público y manda su propio Access-Control-Allow-Origin: *; el resto de la API solo acepta la web oficial.
if (!str_starts_with($ruta, '/datos/')) cors();
if ($metodo === 'OPTIONS') { http_response_code(204); exit; }
// Los envíos solo se aceptan desde la web oficial (o sin cabecera Origin, como en las pruebas con curl).
// La baja se hace desde una página de este servidor o desde el cliente de correo, y la autoriza su token.
if ($metodo === 'POST' && !str_starts_with($ruta, '/admin') && $ruta !== '/api/participa/baja' && ($_SERVER['HTTP_ORIGIN'] ?? config()['web']) !== config()['web']) {
  error_publico('Origen no permitido.', 403);
}

try {
  // Leaflet servido desde este mismo dominio (el mapa de /admin y la app sin conexión no dependen de otro sitio).
  // Y el generador de QR, que usa /admin para los PDF de azulejos.
  if ($metodo === 'GET' && preg_match('#^/vendor/(leaflet-1\.9\.4/leaflet\.(?:css|js)|qrcode-generator-1\.4\.4/qrcode\.js)$#', $ruta, $m)) {
    header('Content-Type: ' . (str_ends_with($m[1], '.css') ? 'text/css' : 'application/javascript') . '; charset=utf-8');
    header('Cache-Control: public, max-age=31536000, immutable');
    readfile(PRIVADO . '/vendor/' . $m[1]);
    exit;
  }
  if ($metodo === 'GET' && preg_match('#^/teselas/osm/(\d{1,2})/(\d+)/(\d+)\.png$#', $ruta, $m)) teselas((int) $m[1], (int) $m[2], (int) $m[3]);
  // Datos públicos en GeoJSON: los leen la web y las capas de uMap («datos remotos»). /datos/umap/ es el nombre antiguo.
  // qr.geojson es el nombre anterior de soportes.geojson: la etiqueta identifica un soporte, no una obra.
  if ($metodo === 'GET' && preg_match('#^/datos/(?:umap/)?(obras|soportes|qr|rutas|artistas|funciones|eventos|referencias)\.geojson$#', $ruta, $m)) {
    match ($m[1]) { 'obras' => catalogo_geojson(), 'soportes', 'qr' => qr_mapa(), 'rutas' => rutas_geojson(), 'artistas' => artistas_geojson(),
      'funciones' => funciones_geojson(), 'eventos' => eventos_geojson(), 'referencias' => referencias_geojson() };
  }
  if ($metodo === 'GET' && preg_match('#^/datos/fotos/(\d{4}-\d{2}/[0-9a-f]{24}\.jpg)$#', $ruta, $m)) foto_publica($m[1]);
  if ($metodo === 'GET' && preg_match('#^/api/publico/obras/([\w-]+)$#', $ruta, $m)) observaciones_publicas($m[1]);
  if ($metodo === 'GET' && preg_match('#^/api/publico/obras/([\w-]+)/vinculos$#', $ruta, $m)) vinculos_obra_publicos($m[1]);
  if ($metodo === 'POST' && preg_match('#^/api/obras/([\w-]+)/huella$#', $ruta, $m)) huella_visita($m[1]);
  if ($metodo === 'POST' && preg_match('#^/api/publico/obras/([\w-]+)/visita$#', $ruta, $m)) visita_anonima($m[1]);
  if ($metodo === 'GET' && $ruta === '/api/publico/calor') calor();
  if ($metodo === 'GET' && preg_match('#^/api/publico/obras/([\w-]+)/huellas$#', $ruta, $m)) huellas_publicas($m[1]);
  if ($metodo === 'GET' && preg_match('#^/api/publico/obras/([\w-]+)/versiones$#', $ruta, $m)) versiones_publicas($m[1]);
  if ($metodo === 'POST' && $ruta === '/api/vinculos') vinculo_crear();
  if ($metodo === 'GET' && $ruta === '/api/vinculos/mios') vinculos_mios();
  if ($metodo === 'GET' && preg_match('#^/api/vinculos/obra/([\w-]+)$#', $ruta, $m)) vinculos_obra_mios($m[1]);
  if ($metodo === 'POST' && preg_match('#^/api/vinculos/(\d+)/(aceptar|rechazar|retirar|publicar)$#', $ruta, $m)) vinculo_accion((int) $m[1], $m[2]);
  if ($metodo === 'GET' && preg_match('#^/api/publico/qr/(' . RE_UUID . ')/historial$#', $ruta, $m)) qr_historial($m[1]);
  if ($metodo === 'GET' && $ruta === '/api/publico/etiquetas.geojson') qr_mapa();
  if ($metodo === 'POST' && $ruta === '/api/qr/asignar') qr_asignar();
  if ($metodo === 'GET' && $ruta === '/api/qr/mis-etiquetas') qr_mis_etiquetas();
  // Después de las rutas fijas de /api/qr/ (mis-etiquetas también encaja en el patrón de un código).
  if ($metodo === 'GET' && preg_match('#^/api/qr/(' . RE_CODIGO . ')$#i', $ruta, $m)) qr_resolver($m[1]);
  if ($metodo === 'GET' && preg_match('#^/api/soporte/(' . RE_UUID . ')$#i', $ruta, $m)) soporte_resolver($m[1]);
  if ($metodo === 'POST' && preg_match('#^/api/qr/(' . RE_CODIGO . ')/ampliar$#i', $ruta, $m)) qr_ampliar($m[1]);
  if ($metodo === 'POST' && preg_match('#^/api/qr/(' . RE_CODIGO . ')/aviso$#i', $ruta, $m)) qr_aviso($m[1]);
  if ($metodo === 'POST' && $ruta === '/api/participa') participa_alta();
  if ($metodo === 'POST' && $ruta === '/api/participa/confirmar') participa_confirmar();
  if ($metodo === 'GET' && $ruta === '/api/participa/resultados') participa_resultados();
  if (in_array($metodo, ['GET', 'POST'], true) && $ruta === '/api/participa/baja') participa_baja($metodo);
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

// Observaciones aprobadas de una obra. El resto de la ficha está en /datos/obras.geojson.
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
    'propuestas_ruta' => 'ruta', 'inscripciones' => 'inscripcion', 'completar_obra' => 'completar'];
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
    // Vale cualquier obra que esté o haya estado en el soporte de la etiqueta.
    $en_soporte = $qr['soporte'] && in_array(strtolower((string) $obra), array_column(obras_de_soporte($qr['soporte']), 'obra'), true);
    if (!$en_soporte && $qr['obra'] !== strtolower((string) $obra)) error_publico('La etiqueta QR no corresponde a esta obra.');
  }
  if ($formulario === 'completar') {
    $st = $db->prepare('SELECT 1 FROM obras WHERE uuid = ? AND publicada = 1');
    $st->execute([strtolower((string) $obra)]);
    if (!$st->fetchColumn()) error_publico('Esa obra no está en el archivo.', 404);
    $obra = strtolower((string) $obra);
  }
  $datos = [];
  foreach ($_POST as $k => $v) if (is_string($v)) $datos[substr((string) $k, 0, 60)] = mb_substr($v, 0, 5000);
  $fotos = [];
  foreach ($_FILES as $f) {
    if (is_array($f['error']) || $f['error'] === UPLOAD_ERR_NO_FILE) continue;
    if ($f['error'] !== UPLOAD_ERR_OK || $f['size'] > 15 * 1024 * 1024) error_publico('La foto no ha llegado bien o pasa de 15 MB.');
    $fotos[] = guardar_foto($f['tmp_name'], mime_content_type($f['tmp_name']) ?: '');
  }
  // Con la cuenta iniciada, el envío queda a su nombre (y el alias es el de la cuenta).
  $c = colaborador_actual();
  if ($c) $datos['alias'] = $c['alias'];
  $db->prepare('INSERT INTO envios (formulario, obra, datos, fotos, ip_hash, colaborador) VALUES (?, ?, ?, ?, ?, ?)')
     ->execute([$formulario, $obra, json_encode($datos, JSON_UNESCAPED_UNICODE), json_encode($fotos), $ip, $c['id'] ?? null]);
  avisar("canarias.africa: nuevo envío ($formulario)", "Hay un envío pendiente de revisar en " . 'https://' . ($_SERVER['HTTP_HOST'] ?? '') . "/admin\n");
  json_respuesta(['ok' => true, 'estado' => 'pendiente'], 201);
}
