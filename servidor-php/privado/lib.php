<?php
// Funciones compartidas del servidor PHP de canarias.africa.

const PRIVADO = __DIR__;
require __DIR__ . '/cuentas.php';
require __DIR__ . '/etiquetas.php';

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
  return $db;
}

function json_respuesta($datos, int $estado = 200): never {
  http_response_code($estado);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function error_publico(string $mensaje, int $estado = 400): never {
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

function avisar(string $asunto, string $texto): void {
  $c = config();
  if (!empty($c['avisar_por_correo']) && $c['contacto']) {
    @mail($c['contacto'], "=?UTF-8?B?" . base64_encode($asunto) . "?=", $texto, "Content-Type: text/plain; charset=utf-8");
  }
}
