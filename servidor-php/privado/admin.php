<?php
// Moderación de envíos, catálogo (obras, rutas, artistas), pool de etiquetas QR, personas colaboradoras y obras propuestas: https://api.canarias.africa/admin
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
  // Genera un lote de códigos libres para el pool. No apuntan a ninguna obra hasta que alguien los coloca.
  $n = max(1, min(500, (int) $_POST['cantidad']));
  $largo = max(8, min(16, (int) ($_POST['largo'] ?? 8)));
  $lote = date('Y-m-d-His');
  $db->beginTransaction();
  for ($i = 0; $i < $n; $i++) {
    do { $u = nuevo_codigo($largo); } while (etiqueta($u));
    $db->prepare('INSERT INTO qr_codigos (codigo, lote) VALUES (?, ?)')->execute([$u, $lote]);
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

if ($sub === '/participa/idea' && $metodo === 'POST') {
  if (in_array($_POST['estado'], ['nueva', 'pasada', 'descartada'], true)) {
    $db->prepare('UPDATE participa_ideas SET estado = ? WHERE id = ?')->execute([$_POST['estado'], (int) $_POST['id']]);
  }
  header('Location: /admin/participa', true, 303);
  exit;
}

if ($sub === '/participa.csv') {
  // Lista de correo confirmada, para importarla en otra herramienta. Las bajas no salen.
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="participantes-' . date('Y-m-d') . '.csv"');
  $out = fopen('php://output', 'w');
  fwrite($out, "\xEF\xBB\xBF");
  fputcsv($out, ['correo', 'alias', 'isla', 'perfil', 'confirmado'], ',', '"', '');
  foreach ($db->query("SELECT correo, alias, isla, perfil, confirmado FROM participantes WHERE estado = 'confirmada' ORDER BY confirmado") as $f) {
    fputcsv($out, array_map(fn($v) => preg_match('/^[=+\-@]/', (string) $v) ? "'$v" : $v, $f), ',', '"', '');
  }
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
require PRIVADO . '/admin-catalogo.php';

if ($sub === '/qr') {
  $cuentas = $db->query('SELECT estado, COUNT(*) n FROM qr_codigos GROUP BY estado ORDER BY estado')->fetchAll(PDO::FETCH_KEY_PAIR);
  $html = "<form method=post action=/admin/qr/lote><input type=hidden name=csrf value=$csrf>
    <label>Generar <input type=number name=cantidad value=50 min=1 max=500 style='width:6em'> códigos libres de <input type=number name=largo value=8 min=8 max=16 style='width:4em'> caracteres</label> <button>Añadir al pool</button></form>
    <p>" . ($cuentas ? implode(' · ', array_map(fn($e, $n) => "{$h($e)}: $n", array_keys($cuentas), $cuentas)) : 'El pool está vacío.') . "</p><table>
    <tr><th>Etiqueta</th><th>Estado</th><th>Soporte</th><th>Última verificación</th></tr>";
  foreach ($db->query("SELECT * FROM qr_codigos WHERE estado != 'libre' ORDER BY creado DESC LIMIT 300") as $q) {
    $html .= "<tr><td><code>{$h($q['codigo'])}</code></td><td>{$h($q['estado'])}</td><td>" . ($q['soporte'] ? "<a href='/admin/soporte?id={$h($q['soporte'])}'>soporte</a>" : "<code>{$h($q['obra'])}</code>") . "</td><td>{$h($q['ultima_verificacion'])}</td></tr>";
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

if ($sub === '/participa') {
  $n = $db->query('SELECT estado, COUNT(*) n FROM participantes GROUP BY estado')->fetchAll(PDO::FETCH_KEY_PAIR);
  $html = '<p>' . (int) ($n['confirmada'] ?? 0) . ' confirmadas · ' . (int) ($n['pendiente'] ?? 0) . ' sin confirmar · ' . (int) ($n['baja'] ?? 0)
    . " de baja · <a href=/admin/participa.csv>Descargar la lista confirmada (CSV)</a></p><h2>Votos</h2><table><tr><th>Propuesta</th><th>Prioritaria</th><th>Interesante</th><th>No</th></tr>";
  foreach ($db->query("SELECT propuesta, SUM(voto = 'prioritaria') p, SUM(voto = 'interesante') i, SUM(voto = 'no') n FROM participa_votos v
      JOIN participantes x ON x.id = v.participante WHERE x.estado != 'pendiente' GROUP BY propuesta ORDER BY p DESC, i DESC") as $f) {
    $html .= "<tr><td><code>{$h($f['propuesta'])}</code></td><td>{$f['p']}</td><td>{$f['i']}</td><td>{$f['n']}</td></tr>";
  }
  $html .= '</table><h2>Ideas nuevas</h2><p class=meta>Para publicarlas, crea un archivo en <code>propuestas/</code> con la autoría que la persona quiera y marca la idea como pasada.</p>';
  foreach ($db->query("SELECT i.*, p.alias, p.correo FROM participa_ideas i JOIN participantes p ON p.id = i.participante ORDER BY i.estado = 'nueva' DESC, i.creado DESC LIMIT 300") as $i) {
    $html .= "<article><span class=meta>{$h($i['estado'])} · {$h($i['alias'] ?: 'sin alias')} · {$h($i['correo'])} · {$h($i['creado'])}</span><p style='white-space:pre-wrap'>{$h($i['texto'])}</p>";
    foreach (['pasada' => 'Pasada a propuestas', 'descartada' => 'Descartar', 'nueva' => 'Devolver a nuevas'] as $e => $t) {
      if ($e !== $i['estado']) $html .= "<form method=post action=/admin/participa/idea class=inline><input type=hidden name=csrf value=$csrf><input type=hidden name=id value={$i['id']}><input type=hidden name=estado value=$e><button>$t</button></form> ";
    }
    $html .= '</article>';
  }
  pagina('Lista y votos del lanzamiento', $html);
}

if ($sub === '/obras') {
  $html = '<p class=meta>Obras nuevas propuestas al colocar una etiqueta. «Crear en el catálogo» abre la ficha rellena con sus datos y el punto de la etiqueta; al guardarla, la propuesta queda aceptada.</p>';
  foreach ($db->query("SELECT p.*, c.alias FROM obras_propuestas p JOIN colaboradores c ON c.id = p.colaborador ORDER BY p.estado = 'pendiente' DESC, p.creado DESC") as $p) {
    $html .= "<article><strong>{$h(json_decode($p['datos'], true)['titulo'] ?? 'Sin título')}</strong> <span class=meta>{$h($p['estado'])} · {$h($p['alias'])} · {$h($p['creado'])}</span><p><code>{$h($p['uuid'])}</code></p><dl>";
    foreach (json_decode($p['datos'], true) as $k => $v) $html .= "<dt>{$h($k)}</dt><dd>{$h($v)}</dd>";
    $html .= '</dl>';
    if ($p['estado'] !== 'aceptada') $html .= "<a href='/admin/obra?desde_propuesta={$h($p['uuid'])}'>Crear en el catálogo</a> ";
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
  if ($e['formulario'] === 'obra_nueva' && $e['estado'] !== 'aprobada') $html .= "<p><a href='/admin/obra?desde_envio={$e['id']}'>Pasar al catálogo</a></p>";
  if ($e['formulario'] === 'ruta' && $e['estado'] !== 'aprobada') $html .= "<p><a href='/admin/ruta?desde_envio={$e['id']}'>Pasar a rutas</a></p>";
  foreach (['aprobada' => 'Aprobar', 'rechazada' => 'Rechazar', 'pendiente' => 'Devolver a pendientes'] as $a => $t) {
    if ($a !== $e['estado']) $html .= "<form method=post action=/admin/estado class=inline><input type=hidden name=csrf value=$csrf><input type=hidden name=id value={$e['id']}><input type=hidden name=estado value=$a><input type=hidden name=volver value=$estado><button class=$a>$t</button></form> ";
  }
  $html .= '</article>';
}
pagina('Moderación', $html);

function pagina(string $titulo, string $cuerpo): never {
  $menu = empty($_SESSION['admin']) ? '' : "<p class=meta><a href=/admin>Envíos</a> · <a href=/admin/qr>Etiquetas QR</a> · <a href=/admin/personas>Personas</a> · <a href=/admin/catalogo>Catálogo</a> · <a href=/admin/soportes>Soportes</a> · <a href=/admin/rutas>Rutas</a> · <a href=/admin/artistas>Artistas</a> · <a href=/admin/obras>Obras propuestas</a> · <a href=/admin/participa>Lista y votos</a> · <a href=/admin/copia>Descargar copia de la base de datos</a> · <a href=/admin/salir>Salir</a></p>";
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
button,input,select,textarea{font:inherit;padding:6px 10px;border:1px solid var(--rule);background:transparent;color:inherit;border-radius:4px}button{cursor:pointer}
.aprobada{border-color:var(--ok);color:var(--ok)}.rechazada{border-color:var(--no);color:var(--no)}
</style></head><body><main><h1>$titulo</h1>$menu$cuerpo</main></body></html>
HTML;
  exit;
}
