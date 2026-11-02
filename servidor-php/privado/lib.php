<?php
// Funciones compartidas del servidor PHP de canarias.africa.

const PRIVADO = __DIR__;
require __DIR__ . '/cuentas.php';
require __DIR__ . '/etiquetas.php';
require __DIR__ . '/vinculos.php';
require __DIR__ . '/huellas.php';
require __DIR__ . '/versiones.php';
require __DIR__ . '/participa.php';
require __DIR__ . '/catalogo.php';
require __DIR__ . '/soportes.php';
require __DIR__ . '/funciones.php';

function config(): array {
  static $c = null;
  if ($c === null) {
    $f = PRIVADO . '/config.php';
    $c = require (is_file($f) ? $f : PRIVADO . '/config.ejemplo.php');
  }
  return $c;
}

function db(): PDO {
  static $db = null;
  if ($db) return $db;
  @mkdir(PRIVADO . '/datos', 0700, true);
  $db = new PDO('sqlite:' . PRIVADO . '/datos/canarias.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
  $db->exec('PRAGMA journal_mode = WAL; PRAGMA foreign_keys = ON;');
  $db->exec(file_get_contents(PRIVADO . '/esquema.sql'));
  migrar_catalogo($db);
  migrar_soportes($db);
  migrar_admin($db);
  return $db;
}

function json_respuesta($datos, int $estado = 200): never {
  http_response_code($estado);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function error_publico(string $mensaje, int $estado = 400): never {
  // Quien abre una dirección en el navegador ve la página de error del proyecto; la API (/api, /datos, /teselas),
  // los formularios y la app de campo, que piden por fetch, siguen recibiendo JSON.
  $ruta = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
  if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'text/html') && !preg_match('#^/(api|datos|teselas)(/|$)#', $ruta)) {
    $pagina = ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/' . (in_array($estado, [400, 401, 403, 404, 500], true) ? $estado : ($estado >= 500 ? 500 : 400)) . '.shtml';
    if (is_file($pagina)) {
      http_response_code($estado);
      header('Content-Type: text/html; charset=utf-8');
      header('Cache-Control: no-store');
      $html = file_get_contents($pagina);
      // En /admin se añade el motivo concreto («Sesión caducada.»…): quien lo ve cuida el archivo y le sirve.
      if (str_starts_with($ruta, '/admin')) $html = str_replace('</h1>', '</h1><p><strong>' . htmlspecialchars($mensaje) . '</strong></p>', $html);
      echo $html;
      exit;
    }
  }
  json_respuesta(['error' => $mensaje], $estado);
}

// Solo la web oficial puede llamar a la API desde el navegador.
function cors(): void {
  $web = config()['web'];
  if (($_SERVER['HTTP_ORIGIN'] ?? '') === $web) {
    header("Access-Control-Allow-Origin: $web");
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Max-Age: 86400');
  }
}

// Reduce la foto a JPEG de como mucho foto_lado_max px de lado, sin metadatos (quita la ubicación GPS).
function guardar_foto(string $tmp, string $tipo): string {
  $img = match ($tipo) {
    'image/jpeg' => @imagecreatefromjpeg($tmp),
    'image/png' => @imagecreatefrompng($tmp),
    'image/webp' => @imagecreatefromwebp($tmp),
    default => false,
  };
  if (!$img) error_publico('La foto debe ser JPEG, PNG o WebP.');
  if ($tipo === 'image/jpeg' && function_exists('exif_read_data')) {
    $giro = [3 => 180, 6 => -90, 8 => 90][@exif_read_data($tmp)['Orientation'] ?? 1] ?? 0;
    if ($giro) $img = imagerotate($img, $giro, 0);
  }
  $max = config()['foto_lado_max'];
  [$w, $h] = [imagesx($img), imagesy($img)];
  $k = min(1, $max / max($w, $h));
  if ($k < 1) $img = imagescale($img, (int) round($w * $k), (int) round($h * $k));
  $dir = PRIVADO . '/fotos/' . date('Y-m');
  @mkdir($dir, 0700, true);
  $nombre = date('Y-m') . '/' . bin2hex(random_bytes(12)) . '.jpg';
  imagejpeg($img, PRIVADO . '/fotos/' . $nombre, 82);
  return $nombre;
}

// Cabeceras de remitente: «remitente» envía (su dominio tiene el SPF del servidor) y las respuestas van a «contacto».
function cabeceras_remitente(string $nombre): string {
  $c = config();
  $de = $c['remitente'] ?? $c['contacto'];
  return 'From: =?UTF-8?B?' . base64_encode($nombre) . "?= <$de>\r\nReply-To: {$c['contacto']}";
}

// mail() con el sobre (Return-Path) del remitente, para que el SPF se compruebe contra su dominio.
// Devuelve si el servidor de correo lo aceptó (no si llegó).
function enviar_correo(string $para, string $asunto, string $texto, string $cabeceras): bool {
  $de = config()['remitente'] ?? config()['contacto'];
  return @mail($para, '=?UTF-8?B?' . base64_encode($asunto) . '?=', $texto, $cabeceras . "\r\nContent-Type: text/plain; charset=utf-8", '-f' . $de);
}

// Columnas que añade el panel a bases de datos antiguas: «archivado» (fecha) en las colas de revisión.
// Archivar saca un elemento de la lista sin borrarlo; se puede devolver.
function migrar_admin(PDO $db): void {
  foreach (['envios', 'participa_ideas', 'obras_propuestas'] as $t) {
    $hay = array_column($db->query("PRAGMA table_info($t)")->fetchAll(), 'name');
    if (!in_array('archivado', $hay, true)) $db->exec("ALTER TABLE $t ADD COLUMN archivado TEXT");
  }
}

// Registro de acciones delicadas de /admin (borrados, cambios de alias, correos a la lista, descargas de la copia).
function registrar_admin(string $accion, string $detalle = ''): void {
  db()->prepare('INSERT INTO admin_registro (accion, detalle, ip, agente) VALUES (?, ?, ?, ?)')
    ->execute([$accion, mb_substr($detalle, 0, 1000), $_SERVER['REMOTE_ADDR'] ?? '', mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 300)]);
}

function avisar(string $asunto, string $texto): void {
  $c = config();
  if (!empty($c['avisar_por_correo']) && $c['contacto']) {
    @mail($c['contacto'], "=?UTF-8?B?" . base64_encode($asunto) . "?=", $texto, "Content-Type: text/plain; charset=utf-8");
  }
}
