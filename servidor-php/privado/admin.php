<?php
// Moderación de envíos, pool de etiquetas QR, personas colaboradoras y obras propuestas: https://api.canarias.africa/admin
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

if ($sub === '/qr/lote' && $metodo === 'POST') {
  // Genera un lote de UUID libres para el pool. No apuntan a ninguna obra hasta que alguien los coloca.
  $n = max(1, min(500, (int) $_POST['cantidad']));
  $lote = date('Y-m-d-His');
  $db->beginTransaction();
  for ($i = 0; $i < $n; $i++) {
    $u = uuid4();
    $db->prepare('INSERT INTO qr_codigos (uuid, lote) VALUES (?, ?)')->execute([$u, $lote]);
    evento_qr($u, 'libre', null, ['lote' => $lote]);
  }
  $db->commit();
  header('Location: /admin/qr', true, 303);
  exit;
}

if ($sub === '/custodia' && $metodo === 'POST') {
  $db->prepare('UPDATE colaboradores SET custodia = ? WHERE id = ?')->execute([(int) $_POST['valor'] ? 1 : 0, (int) $_POST['id']]);
  header('Location: /admin/personas', true, 303);
  exit;
}

if ($sub === '/propuesta' && $metodo === 'POST') {
  if (in_array($_POST['estado'], ['aceptada', 'rechazada', 'pendiente'], true)) {
    $db->prepare('UPDATE obras_propuestas SET estado = ? WHERE uuid = ?')->execute([$_POST['estado'], (string) $_POST['uuid']]);
  }
  header('Location: /admin/obras', true, 303);
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
  $cuentas = $db->query('SELECT estado, COUNT(*) n FROM qr_codigos GROUP BY estado ORDER BY estado')->fetchAll(PDO::FETCH_KEY_PAIR);
  $html = "<form method=post action=/admin/qr/lote><input type=hidden name=csrf value=$csrf>
    <label>Generar <input type=number name=cantidad value=50 min=1 max=500 style='width:6em'> UUID libres</label> <button>Añadir al pool</button></form>
    <p>" . ($cuentas ? implode(' · ', array_map(fn($e, $n) => "{$h($e)}: $n", array_keys($cuentas), $cuentas)) : 'El pool está vacío.') . "</p><table>
    <tr><th>Etiqueta</th><th>Estado</th><th>Obra</th><th>Última verificación</th></tr>";
  foreach ($db->query("SELECT * FROM qr_codigos WHERE estado != 'libre' ORDER BY creado DESC LIMIT 300") as $q) {
    $html .= "<tr><td><code>{$h($q['uuid'])}</code></td><td>{$h($q['estado'])}</td><td><code>{$h($q['obra'])}</code></td><td>{$h($q['ultima_verificacion'])}</td></tr>";
  }
  pagina('Etiquetas QR', $html . '</table>');
}

if ($sub === '/personas') {
  $html = '<table><tr><th>Alias</th><th>Correo</th><th>Nivel</th><th>Méritos</th><th></th></tr>';
  foreach ($db->query('SELECT * FROM colaboradores ORDER BY meritos DESC, alias') as $c) {
    $html .= "<tr><td>{$h($c['alias'])}</td><td>{$h($c['correo'])}</td><td>{$h(nivel($c))}" . ($c['verificado'] ? '' : ' (sin verificar)') . "</td><td>{$c['meritos']}</td><td>
      <form method=post action=/admin/custodia><input type=hidden name=csrf value=$csrf><input type=hidden name=id value={$c['id']}><input type=hidden name=valor value=" . ($c['custodia'] ? 0 : 1) . "><button>" . ($c['custodia'] ? 'Quitar custodia' : 'Dar custodia') . "</button></form></td></tr>";
  }
  pagina('Personas colaboradoras', $html . '</table>');
}

if ($sub === '/obras') {
  $html = '<p class=meta>Obras nuevas propuestas al colocar una etiqueta. Al aceptarlas hay que añadirlas al catálogo de GitHub con este mismo UUID.</p>';
  foreach ($db->query("SELECT p.*, c.alias FROM obras_propuestas p JOIN colaboradores c ON c.id = p.colaborador ORDER BY p.estado = 'pendiente' DESC, p.creado DESC") as $p) {
    $html .= "<article><strong>{$h(json_decode($p['datos'], true)['titulo'] ?? 'Sin título')}</strong> <span class=meta>{$h($p['estado'])} · {$h($p['alias'])} · {$h($p['creado'])}</span><p><code>{$h($p['uuid'])}</code></p><dl>";
    foreach (json_decode($p['datos'], true) as $k => $v) $html .= "<dt>{$h($k)}</dt><dd>{$h($v)}</dd>";
    $html .= '</dl>';
    foreach (['aceptada' => 'Aceptar', 'rechazada' => 'Rechazar'] as $e => $t) {
      if ($e !== $p['estado']) $html .= "<form method=post action=/admin/propuesta class=inline><input type=hidden name=csrf value=$csrf><input type=hidden name=uuid value={$h($p['uuid'])}><input type=hidden name=estado value=$e><button class=" . ($e === 'aceptada' ? 'aprobada' : 'rechazada') . ">$t</button></form> ";
    }
    $html .= '</article>';
  }
  pagina('Obras propuestas', $html);
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
  $menu = empty($_SESSION['admin']) ? '' : "<p class=meta><a href=/admin>Envíos</a> · <a href=/admin/qr>Etiquetas QR</a> · <a href=/admin/personas>Personas</a> · <a href=/admin/obras>Obras propuestas</a> · <a href=/admin/copia>Descargar copia de la base de datos</a> · <a href=/admin/salir>Salir</a></p>";
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
