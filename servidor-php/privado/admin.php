<?php
// Moderación de envíos y gestión de QR: https://api.canarias.africa/admin
declare(strict_types=1);

session_set_cookie_params(['httponly' => true, 'secure' => true, 'samesite' => 'Strict', 'path' => '/admin']);
session_start();
header('X-Frame-Options: DENY');
header('X-Robots-Tag: noindex');

$sub = substr($ruta, strlen('/admin'));
$hash = config()['admin_hash'];

if ($sub === '/entrar' && $metodo === 'POST') {
  usleep(300000);
  if ($hash && password_verify((string) ($_POST['clave'] ?? ''), $hash)) {
    session_regenerate_id(true);
    $_SESSION['admin'] = true;
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
  }
  header('Location: /admin', true, 303);
  exit;
}
if (empty($_SESSION['admin'])) pagina('Entrar', $hash
  ? '<form method="post" action="/admin/entrar"><label>Contraseña <input type="password" name="clave" autofocus required></label> <button>Entrar</button></form>'
  : '<p>Falta poner <code>admin_hash</code> en <code>privado/config.php</code>.</p>');

if ($metodo === 'POST' && !hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) error_publico('Sesión caducada.', 403);
$db = db();

if ($sub === '/salir') { session_destroy(); header('Location: /admin', true, 303); exit; }

if ($sub === '/estado' && $metodo === 'POST') {
  $estado = (string) $_POST['estado'];
  if (in_array($estado, ['aprobada', 'rechazada', 'pendiente'], true)) {
    $db->prepare('UPDATE envios SET estado = ? WHERE id = ?')->execute([$estado, (int) $_POST['id']]);
  }
  header('Location: /admin?estado=' . urlencode($_POST['volver'] ?? 'pendiente'), true, 303);
  exit;
}

if (preg_match('#^/foto/(\d{4}-\d{2}/[0-9a-f]{24}\.jpg)$#', $sub, $m)) {
  header('Content-Type: image/jpeg');
  readfile(PRIVADO . '/fotos/' . $m[1]);
  exit;
}

if ($sub === '/qr/nuevo' && $metodo === 'POST') {
  $obra = trim((string) $_POST['obra']);
  if (preg_match('/^[\w-]{3,40}$/', $obra)) {
    // Rotar: el código anterior de la obra deja de valer y se crea uno nuevo para reimprimir.
    $db->prepare('UPDATE qr SET activo = 0 WHERE obra = ?')->execute([$obra]);
    $db->prepare('INSERT INTO qr (codigo, obra) VALUES (?, ?)')->execute([nuevo_codigo_qr(), $obra]);
  }
  header('Location: /admin/qr', true, 303);
  exit;
}

if ($sub === '/qr/baja' && $metodo === 'POST') {
  $db->prepare('UPDATE qr SET activo = 0 WHERE codigo = ?')->execute([(string) $_POST['codigo']]);
  header('Location: /admin/qr', true, 303);
  exit;
}

if ($sub === '/copia') {
  $f = PRIVADO . '/copias/canarias-' . date('Y-m-d') . '.sqlite';
  @mkdir(dirname($f), 0700, true);
  @unlink($f);
  $db->exec('VACUUM INTO ' . $db->quote($f));
  header('Content-Type: application/octet-stream');
  header('Content-Disposition: attachment; filename="' . basename($f) . '"');
  readfile($f);
  exit;
}

$csrf = $_SESSION['csrf'];
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);

if ($sub === '/qr') {
  $base = 'https://' . $_SERVER['HTTP_HOST'] . '/q/';
  $filas = $db->query('SELECT * FROM qr ORDER BY activo DESC, obra, creado DESC')->fetchAll();
  $html = "<form method=post action=/admin/qr/nuevo><input type=hidden name=csrf value=$csrf>
    <label>Código de la obra <input name=obra placeholder=CA-TF-ARI-0001 required></label> <button>Crear o rotar QR</button></form>
    <p class=meta>Al rotar, el QR anterior de esa obra deja de aceptar observaciones. Hay que imprimir y colocar el nuevo.</p><table>
    <tr><th>Obra</th><th>Enlace del QR</th><th>Estado</th><th></th></tr>";
  foreach ($filas as $q) {
    $html .= "<tr><td>{$h($q['obra'])}</td><td><code>{$h($base . $q['codigo'])}</code></td><td>" . ($q['activo'] ? 'activo' : 'retirado') . "</td><td>"
      . ($q['activo'] ? "<form method=post action=/admin/qr/baja><input type=hidden name=csrf value=$csrf><input type=hidden name=codigo value={$h($q['codigo'])}><button>Retirar</button></form>" : '')
      . "</td></tr>";
  }
  pagina('Códigos QR', $html . '</table>');
}

$nombres = ['observacion' => 'Observación (QR)', 'contexto' => 'Contexto', 'obra_nueva' => 'Obra nueva', 'ruta' => 'Ruta propuesta', 'inscripcion' => 'Inscripción a salida'];
$estado = in_array($_GET['estado'] ?? '', ['aprobada', 'rechazada'], true) ? $_GET['estado'] : 'pendiente';
$st = $db->prepare('SELECT * FROM envios WHERE estado = ? ORDER BY creado DESC LIMIT 200');
$st->execute([$estado]);
$html = "<nav><a href='/admin'>Pendientes</a> · <a href='/admin?estado=aprobada'>Aprobados</a> · <a href='/admin?estado=rechazada'>Rechazados</a></nav>";
$envios = $st->fetchAll();
if (!$envios) $html .= '<p>No hay envíos en este estado.</p>';
foreach ($envios as $e) {
  $html .= "<article><strong>{$h($nombres[$e['formulario']] ?? $e['formulario'])}</strong> <span class=meta>{$h($e['obra'])} · {$h($e['creado'])}</span><dl>";
  foreach (json_decode($e['datos'], true) as $k => $v) $html .= "<dt>{$h($k)}</dt><dd>{$h($v)}</dd>";
  $html .= '</dl>';
  foreach (json_decode($e['fotos'], true) as $f) $html .= "<a href='/admin/foto/{$h($f)}'><img src='/admin/foto/{$h($f)}' alt='Foto enviada'></a>";
  foreach (['aprobada' => 'Aprobar', 'rechazada' => 'Rechazar', 'pendiente' => 'Devolver a pendientes'] as $a => $t) {
    if ($a !== $e['estado']) $html .= "<form method=post action=/admin/estado class=inline><input type=hidden name=csrf value=$csrf><input type=hidden name=id value={$e['id']}><input type=hidden name=estado value=$a><input type=hidden name=volver value=$estado><button class=$a>$t</button></form> ";
  }
  $html .= '</article>';
}
pagina('Moderación', $html);

function pagina(string $titulo, string $cuerpo): never {
  $menu = empty($_SESSION['admin']) ? '' : "<p class=meta><a href=/admin>Envíos</a> · <a href=/admin/qr>Códigos QR</a> · <a href=/admin/copia>Descargar copia de la base de datos</a> · <a href=/admin/salir>Salir</a></p>";
  header('Content-Type: text/html; charset=utf-8');
  echo <<<HTML
<!doctype html><html lang=es><head><meta charset=utf-8><meta name=viewport content="width=device-width, initial-scale=1"><meta name=robots content=noindex>
<title>$titulo · canarias.africa</title><style>
:root{--bg:#faf6ee;--fg:#16130f;--muted:#6b6257;--rule:#d9d0c1;--ok:#007a3d;--no:#c8102e}
@media (prefers-color-scheme:dark){:root{--bg:#12100d;--fg:#f4eee2;--muted:#a39a8c;--rule:#2e2a24;--ok:#2aa866;--no:#e5354f}}
body{margin:0;background:var(--bg);color:var(--fg);font:16px/1.5 system-ui,sans-serif}main{max-width:860px;margin:0 auto;padding:24px 16px}
a{color:inherit}.meta{color:var(--muted);font-size:14px}article{border-top:1px solid var(--rule);padding:16px 0}
dl{display:grid;grid-template-columns:max-content 1fr;gap:4px 12px}dt{color:var(--muted)}dd{margin:0;white-space:pre-wrap;overflow-wrap:anywhere}
img{max-width:220px;border-radius:4px;margin:4px 8px 0 0}.inline{display:inline}table{border-collapse:collapse;width:100%}td,th{border-top:1px solid var(--rule);padding:6px;text-align:left;font-size:14px}
button,input{font:inherit;padding:6px 10px;border:1px solid var(--rule);background:transparent;color:inherit;border-radius:4px}button{cursor:pointer}
.aprobada{border-color:var(--ok);color:var(--ok)}.rechazada{border-color:var(--no);color:var(--no)}
</style></head><body><main><h1>$titulo</h1>$menu$cuerpo</main></body></html>
HTML;
  exit;
}
