<?php
// Panel de https://api.canarias.africa/admin, por categorías:
//   Revisar: envíos del público, obras propuestas, ideas y votos del lanzamiento.
//   Catálogo: obras, soportes, funciones, eventos, rutas y artistas (admin-catalogo.php).
//   En la calle: app «En campo», pool de etiquetas QR y azulejos.
//   Comunidad: personas colaboradoras, lista de participantes y correos a la lista (admin-comunidad.php).
//   Sistema: registro de acciones y copia de la base de datos.
// Lo que se borra pide escribir su nombre (zona_borrado) y queda en el registro; lo que se archiva sale de la lista y se puede devolver.
declare(strict_types=1);

require PRIVADO . '/admin-ui.php';
// La app «En campo» (PWA): sus archivos se sirven sin sesión porque no llevan datos y tienen que abrirse sin red.
require PRIVADO . '/campo.php';
$sub = substr($ruta, strlen('/admin'));
campo_estatico($sub);

session_set_cookie_params(['httponly' => true, 'secure' => true, 'samesite' => 'Strict', 'path' => '/admin']);
session_start();
header('X-Frame-Options: DENY');
header('X-Robots-Tag: noindex');

$hash = config()['admin_hash'];

if ($sub === '/entrar' && $metodo === 'POST') {
  usleep(300000);
  if ($hash && password_verify((string) ($_POST['clave'] ?? ''), $hash)) {
    session_regenerate_id(true);
    $_SESSION['admin'] = true;
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
  }
  header('Location: ' . (($_POST['volver'] ?? '') === 'campo' && !empty($_SESSION['admin']) ? '/admin/campo' : '/admin'), true, 303);
  exit;
}
if ($sub === '/campo/token') campo_token();
if (empty($_SESSION['admin']) && $sub === '/campo/subir') error_publico('La sesión ha caducado.', 403);
if (empty($_SESSION['admin'])) pagina('Entrar', $hash
  ? '<form method="post" action="/admin/entrar">' . (($_GET['volver'] ?? '') === 'campo' ? '<input type=hidden name=volver value=campo>' : '') . '<label class=campo><span>Contraseña</span><input type="password" name="clave" autofocus required></label><p><button class=primario>Entrar</button></p></form>'
  : '<p>Falta poner <code>admin_hash</code> en <code>privado/config.php</code>.</p>');

if ($metodo === 'POST' && !hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) error_publico('Sesión caducada.', 403);
$db = db();
$csrf = $_SESSION['csrf'];
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
$volver = function (string $url): never { header('Location: ' . $url, true, 303); exit; };
// Botón que envía un POST pequeño (cambiar estado, archivar…).
$boton = fn(string $accion, array $campos, string $texto, string $clase = '') => "<form method=post action=$accion class=inline><input type=hidden name=csrf value=$csrf>"
  . implode('', array_map(fn($k, $v) => "<input type=hidden name=$k value=\"{$h($v)}\">", array_keys($campos), $campos)) . '<button' . ($clase ? " class=$clase" : '') . ">$texto</button></form>";

// Salir solo por POST (con el token CSRF): un enlace o una imagen de otra web no pueden cerrar la sesión.
if ($sub === '/salir' && $metodo === 'POST') { session_destroy(); $volver('/admin'); }

// ---------------- Envíos del público ----------------
$nombre_envio = fn(array $e) => 'envio-' . $e['id'];
$envio = function (int $id) use ($db): array {
  $st = $db->prepare('SELECT * FROM envios WHERE id = ?');
  $st->execute([$id]);
  return $st->fetch() ?: error_publico('Ese envío no existe.', 404);
};

if ($sub === '/estado' && $metodo === 'POST') {
  $estado = (string) $_POST['estado'];
  if ($estado === 'aprobada') aprobar_envio((int) $_POST['id']);
  elseif (in_array($estado, ['rechazada', 'pendiente'], true)) $db->prepare('UPDATE envios SET estado = ? WHERE id = ?')->execute([$estado, (int) $_POST['id']]);
  $volver('/admin?estado=' . urlencode($_POST['volver'] ?? 'pendiente'));
}

if ($sub === '/envio/archivar' && $metodo === 'POST') {
  $e = $envio((int) $_POST['id']);
  $archivar = !empty($_POST['archivar']);
  $db->prepare('UPDATE envios SET archivado = ' . ($archivar ? "datetime('now')" : 'NULL') . ' WHERE id = ?')->execute([$e['id']]);
  aviso($archivar ? "Envío {$e['id']} archivado. Lo tienes en «Archivados»." : "Envío {$e['id']} devuelto a «" . ['pendiente' => 'Pendientes', 'aprobada' => 'Aprobados', 'rechazada' => 'Rechazados'][$e['estado']] . '».');
  $volver('/admin?estado=' . urlencode($_POST['volver'] ?? 'pendiente'));
}

if ($sub === '/envio/eliminar' && $metodo === 'POST') {
  $e = $envio((int) $_POST['id']);
  if (!nombre_confirmado($nombre_envio($e))) { aviso('No se ha borrado: el nombre escrito no coincide.', 'error'); $volver('/admin?estado=' . urlencode($_POST['volver'] ?? 'pendiente')); }
  // Las fotos de un envío aprobado pueden estar ya en una obra: solo se borran las de los no aprobados.
  if ($e['estado'] !== 'aprobada') foreach (json_decode($e['fotos'], true) ?: [] as $f) if (preg_match('#^\d{4}-\d{2}/[0-9a-f]{24}\.jpg$#', $f)) @unlink(PRIVADO . '/fotos/' . $f);
  $db->prepare('DELETE FROM envios WHERE id = ?')->execute([$e['id']]);
  registrar_admin('eliminar_envio', "{$e['id']} · {$e['formulario']} · {$e['estado']} · " . mb_substr((string) (json_decode($e['datos'], true)['titulo'] ?? ''), 0, 80));
  aviso("Envío {$e['id']} eliminado.");
  $volver('/admin?estado=' . urlencode($_POST['volver'] ?? 'pendiente'));
}

if (preg_match('#^/foto/(\d{4}-\d{2}/[0-9a-f]{24}\.jpg)$#', $sub, $m)) {
  header('Content-Type: image/jpeg');
  readfile(PRIVADO . '/fotos/' . $m[1]);
  exit;
}

// ---------------- Etiquetas QR ----------------
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
  aviso("$n códigos añadidos al pool (lote $lote).");
  $volver('/admin/qr');
}

// ---------------- Obras propuestas (al colocar una etiqueta) ----------------
$propuesta = function (string $uuid) use ($db): array {
  $st = $db->prepare('SELECT * FROM obras_propuestas WHERE uuid = ?');
  $st->execute([$uuid]);
  return $st->fetch() ?: error_publico('Esa propuesta no existe.', 404);
};
$nombre_propuesta = fn(array $p) => trim((string) (json_decode($p['datos'], true)['titulo'] ?? '')) ?: 'propuesta-' . substr($p['uuid'], 0, 8);

if ($sub === '/propuesta' && $metodo === 'POST') {
  if (in_array($_POST['estado'], ['aceptada', 'rechazada', 'pendiente'], true)) {
    $db->prepare('UPDATE obras_propuestas SET estado = ? WHERE uuid = ?')->execute([$_POST['estado'], (string) $_POST['uuid']]);
  }
  $volver('/admin/obras');
}

if ($sub === '/propuesta/archivar' && $metodo === 'POST') {
  $p = $propuesta((string) $_POST['uuid']);
  $archivar = !empty($_POST['archivar']);
  $db->prepare('UPDATE obras_propuestas SET archivado = ' . ($archivar ? "datetime('now')" : 'NULL') . ' WHERE uuid = ?')->execute([$p['uuid']]);
  aviso('«' . $nombre_propuesta($p) . ($archivar ? '» archivada.' : '» devuelta a la lista.'));
  $volver('/admin/obras' . ($archivar ? '' : '?ver=archivadas'));
}

if ($sub === '/propuesta/eliminar' && $metodo === 'POST') {
  $p = $propuesta((string) $_POST['uuid']);
  $nombre = $nombre_propuesta($p);
  if (!nombre_confirmado($nombre)) { aviso('No se ha borrado: el nombre escrito no coincide.', 'error'); $volver('/admin/obras'); }
  $db->prepare('DELETE FROM obras_propuestas WHERE uuid = ?')->execute([$p['uuid']]);
  registrar_admin('eliminar_obra_propuesta', "{$p['uuid']} · $nombre");
  aviso("Propuesta «{$nombre}» eliminada.");
  $volver('/admin/obras');
}

// ---------------- Ideas del lanzamiento ----------------
$idea = function (int $id) use ($db): array {
  $st = $db->prepare('SELECT * FROM participa_ideas WHERE id = ?');
  $st->execute([$id]);
  return $st->fetch() ?: error_publico('Esa idea no existe.', 404);
};

if ($sub === '/participa/idea' && $metodo === 'POST') {
  if (in_array($_POST['estado'], ['nueva', 'pasada', 'descartada'], true)) {
    $db->prepare('UPDATE participa_ideas SET estado = ? WHERE id = ?')->execute([$_POST['estado'], (int) $_POST['id']]);
  }
  $volver('/admin/participa?ver=' . urlencode($_POST['volver'] ?? 'nueva'));
}

if ($sub === '/participa/idea/archivar' && $metodo === 'POST') {
  $i = $idea((int) $_POST['id']);
  $archivar = !empty($_POST['archivar']);
  $db->prepare('UPDATE participa_ideas SET archivado = ' . ($archivar ? "datetime('now')" : 'NULL') . ' WHERE id = ?')->execute([$i['id']]);
  aviso($archivar ? "Idea {$i['id']} archivada." : "Idea {$i['id']} devuelta a la lista.");
  $volver('/admin/participa?ver=' . urlencode($_POST['volver'] ?? 'nueva'));
}

if ($sub === '/participa/idea/eliminar' && $metodo === 'POST') {
  $i = $idea((int) $_POST['id']);
  if (!nombre_confirmado("idea-{$i['id']}")) { aviso('No se ha borrado: el nombre escrito no coincide.', 'error'); $volver('/admin/participa?ver=' . urlencode($_POST['volver'] ?? 'nueva')); }
  $db->prepare('DELETE FROM participa_ideas WHERE id = ?')->execute([$i['id']]);
  registrar_admin('eliminar_idea', "{$i['id']} · " . mb_substr($i['texto'], 0, 120));
  aviso("Idea {$i['id']} eliminada.");
  $volver('/admin/participa?ver=' . urlencode($_POST['volver'] ?? 'nueva'));
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

// Copia de la base de datos: contiene los correos de las personas colaboradoras. Se pide confirmación, se descarga
// por POST y cada descarga queda registrada (fecha, IP y navegador).
if ($sub === '/copia' && $metodo === 'POST') {
  if (($_POST['confirmo'] ?? '') !== '1') { aviso('Marca la casilla para confirmar la descarga.', 'error'); $volver('/admin/copia'); }
  $f = PRIVADO . '/copias/canarias-' . date('Y-m-d') . '.sqlite';
  @mkdir(dirname($f), 0700, true);
  @unlink($f);
  $db->exec('VACUUM INTO ' . $db->quote($f));
  registrar_admin('descarga_copia', basename($f) . ' · ' . filesize($f) . ' bytes');
  header('Content-Type: application/octet-stream');
  header('Content-Disposition: attachment; filename="' . basename($f) . '"');
  header('Cache-Control: no-store');
  readfile($f);
  @unlink($f);
  exit;
}

if ($sub === '/salir') pagina('Salir', "<form method=post action=/admin/salir><input type=hidden name=csrf value=$csrf><button class=primario>Cerrar la sesión</button></form>");

if ($sub === '/copia') {
  $html = "<p>La copia es la base de datos completa: incluye <strong>los correos de las personas colaboradoras y de la lista</strong>, las coordenadas exactas de las obras protegidas y los nombres que no se publican. Guárdala cifrada y no la compartas.</p>
    <form method=post action=/admin/copia class=tarjeta><input type=hidden name=csrf value=$csrf>
    <p><label><input type=checkbox name=confirmo value=1> Entiendo que contiene datos personales y la guardaré de forma segura.</label></p>
    <p><button class=primario>Descargar la copia</button></p></form><h2>Últimas descargas</h2>";
  $filas = $db->query("SELECT * FROM admin_registro WHERE accion = 'descarga_copia' ORDER BY id DESC LIMIT 20")->fetchAll();
  $html .= $filas ? '<table><tr><th>Fecha (UTC)</th><th>IP</th><th>Navegador</th><th>Archivo</th></tr>' . implode('', array_map(fn($r) => "<tr><td>{$h($r['fecha'])}</td><td>{$h($r['ip'])}</td><td>{$h(mb_substr($r['agente'], 0, 80))}</td><td>{$h($r['detalle'])}</td></tr>", $filas)) . '</table>'
    : '<p class=meta>Todavía no se ha descargado ninguna copia.</p>';
  pagina('Copia de la base de datos', $html);
}

if ($sub === '/registro') {
  $nombres = ['descarga_copia' => 'Descarga de la copia', 'eliminar_soporte' => 'Soporte eliminado', 'eliminar_envio' => 'Envío eliminado', 'eliminar_idea' => 'Idea eliminada',
    'eliminar_obra_propuesta' => 'Obra propuesta eliminada', 'eliminar_participante' => 'Participante eliminado', 'alias_colaborador' => 'Alias cambiado (cuenta)',
    'alias_participante' => 'Alias cambiado (lista)', 'correo_lista' => 'Correo a la lista', 'campo_subida' => 'Obra subida desde En campo'];
  $html = '<p class=meta>Acciones delicadas hechas en el panel: borrados, cambios de alias, correos a la lista y descargas de la copia. Las 300 últimas.</p><table class=filtrable><tr><th>Fecha (UTC)</th><th>Acción</th><th>Detalle</th><th>IP</th></tr>';
  foreach ($db->query('SELECT * FROM admin_registro ORDER BY id DESC LIMIT 300') as $r) {
    $html .= "<tr><td>{$h($r['fecha'])}</td><td>{$h($nombres[$r['accion']] ?? $r['accion'])}</td><td>{$h($r['detalle'])}</td><td>{$h($r['ip'])}</td></tr>";
  }
  pagina('Registro de acciones', tabla_o_vacio($html, 'Todavía no hay nada en el registro.'));
}

require PRIVADO . '/admin-comunidad.php';
require PRIVADO . '/admin-catalogo.php';

if ($sub === '/qr') {
  $cuentas = $db->query('SELECT estado, COUNT(*) n FROM qr_codigos GROUP BY estado ORDER BY estado')->fetchAll(PDO::FETCH_KEY_PAIR);
  $html = "<form method=post action=/admin/qr/lote class=tarjeta><input type=hidden name=csrf value=$csrf>
    <label>Generar <input type=number name=cantidad value=50 min=1 max=500 style='width:6em'> códigos libres de <input type=number name=largo value=8 min=8 max=16 style='width:4.5em'> caracteres</label> <button class=primario>Añadir al pool</button></form>
    <p>" . ($cuentas ? implode(' · ', array_map(fn($e, $n) => "{$h($e)}: $n", array_keys($cuentas), $cuentas)) : 'El pool está vacío.') . "</p><table class=filtrable>
    <tr><th>Etiqueta</th><th>Estado</th><th>Soporte</th><th>Última verificación</th></tr>";
  foreach ($db->query("SELECT * FROM qr_codigos WHERE estado != 'libre' ORDER BY creado DESC LIMIT 300") as $q) {
    $html .= "<tr><td><code>{$h($q['codigo'])}</code></td><td>{$h($q['estado'])}</td><td>" . ($q['soporte'] ? "<a href='/admin/soporte?id={$h($q['soporte'])}'>soporte</a>" : "<code>{$h($q['obra'])}</code>") . "</td><td>{$h($q['ultima_verificacion'])}</td></tr>";
  }
  pagina('Etiquetas QR', tabla_o_vacio($html, 'Ninguna etiqueta asignada ni colocada todavía. Genera un lote de códigos libres arriba y repártelos a las personas colaboradoras.'));
}

if ($sub === '/participa') {
  $ver = in_array($_GET['ver'] ?? '', ['pasada', 'descartada', 'archivadas', 'votos'], true) ? $_GET['ver'] : 'nueva';
  $n = $db->query("SELECT CASE WHEN archivado IS NOT NULL THEN 'archivadas' ELSE estado END k, COUNT(*) n FROM participa_ideas GROUP BY k")->fetchAll(PDO::FETCH_KEY_PAIR);
  $html = '<p class=meta>Ideas que llegan con «Quiero participar» y votos de las propuestas. Para publicar una idea, crea un archivo en <code>propuestas/</code> del repositorio con la autoría que la persona quiera y márcala como pasada. Lo que no es una propuesta (pruebas, preguntas, spam) se puede archivar o eliminar.</p>'
    . pestanas(['nueva' => ['Nuevas', '/admin/participa', $n['nueva'] ?? 0], 'pasada' => ['Pasadas a propuestas', '/admin/participa?ver=pasada', $n['pasada'] ?? 0],
      'descartada' => ['Descartadas', '/admin/participa?ver=descartada', $n['descartada'] ?? 0], 'archivadas' => ['Archivadas', '/admin/participa?ver=archivadas', $n['archivadas'] ?? 0],
      'votos' => ['Votos', '/admin/participa?ver=votos', null]], $ver);
  if ($ver === 'votos') {
    $html .= '<table><tr><th>Propuesta</th><th>Prioritaria</th><th>Interesante</th><th>No</th></tr>';
    foreach ($db->query("SELECT propuesta, SUM(voto = 'prioritaria') p, SUM(voto = 'interesante') i, SUM(voto = 'no') n FROM participa_votos v
        JOIN participantes x ON x.id = v.participante WHERE x.estado != 'pendiente' GROUP BY propuesta ORDER BY p DESC, i DESC") as $f) {
      $html .= "<tr><td><code>{$h($f['propuesta'])}</code></td><td>{$f['p']}</td><td>{$f['i']}</td><td>{$f['n']}</td></tr>";
    }
    pagina('Ideas y votos', tabla_o_vacio($html, 'Todavía no hay votos confirmados.'));
  }
  $st = $db->prepare('SELECT i.*, p.alias, p.correo, p.id AS pid FROM participa_ideas i JOIN participantes p ON p.id = i.participante WHERE '
    . ($ver === 'archivadas' ? 'i.archivado IS NOT NULL ORDER BY i.archivado DESC' : 'i.archivado IS NULL AND i.estado = ? ORDER BY i.creado DESC') . ' LIMIT 300');
  $st->execute($ver === 'archivadas' ? [] : [$ver]);
  $ideas = $st->fetchAll();
  if (!$ideas) $html .= '<div class=vacio>No hay ideas aquí.</div>';
  foreach ($ideas as $i) {
    $html .= "<article><span class=meta><strong>idea-{$i['id']}</strong> · <span class=etiqueta>{$h($i['estado'])}</span> · <a href='/admin/participante?id={$i['pid']}'>{$h($i['alias'] ?: 'sin alias')}</a> · {$h($i['correo'])} · {$h($i['creado'])}</span><p style='white-space:pre-wrap'>{$h($i['texto'])}</p><div class=acciones>";
    if (!$i['archivado']) foreach (['pasada' => ['Pasada a propuestas', 'aprobada'], 'descartada' => ['Descartar', ''], 'nueva' => ['Devolver a nuevas', '']] as $e => [$t, $c]) {
      if ($e !== $i['estado']) $html .= $boton('/admin/participa/idea', ['id' => $i['id'], 'estado' => $e, 'volver' => $ver], $t, $c);
    }
    $html .= $boton('/admin/participa/idea/archivar', ['id' => $i['id'], 'archivar' => $i['archivado'] ? '' : '1', 'volver' => $ver], $i['archivado'] ? 'Desarchivar' : 'Archivar')
      . '</div>' . zona_borrado('/admin/participa/idea/eliminar', ['id' => $i['id'], 'volver' => $ver], "idea-{$i['id']}", 'esta idea', 'Para algo que no es una propuesta: pruebas, preguntas, spam. Los votos de la persona no se tocan.') . '</article>';
  }
  pagina('Ideas y votos', $html);
}

if ($sub === '/obras') {
  $archivadas = ($_GET['ver'] ?? '') === 'archivadas';
  $n = $db->query('SELECT SUM(archivado IS NULL), SUM(archivado IS NOT NULL) FROM obras_propuestas')->fetch(PDO::FETCH_NUM);
  $html = '<p class=meta>Obras nuevas propuestas al colocar una etiqueta. «Crear en el catálogo» abre la ficha rellena con sus datos y el punto de la etiqueta; al guardarla, la propuesta queda aceptada.</p>'
    . pestanas(['activas' => ['En la lista', '/admin/obras', (int) $n[0]], 'archivadas' => ['Archivadas', '/admin/obras?ver=archivadas', (int) $n[1]]], $archivadas ? 'archivadas' : 'activas');
  $filas = $db->query('SELECT p.*, c.alias FROM obras_propuestas p JOIN colaboradores c ON c.id = p.colaborador WHERE p.archivado IS ' . ($archivadas ? 'NOT NULL' : 'NULL') . " ORDER BY p.estado = 'pendiente' DESC, p.creado DESC")->fetchAll();
  if (!$filas) $html .= '<div class=vacio>' . ($archivadas ? 'No hay propuestas archivadas.' : 'No hay obras propuestas. Llegan cuando alguien coloca una etiqueta en una obra que aún no está en el catálogo.') . '</div>';
  foreach ($filas as $p) {
    $html .= "<article><strong>{$h($nombre_propuesta($p))}</strong> <span class=meta><span class=etiqueta>{$h($p['estado'])}</span> · {$h($p['alias'])} · {$h($p['creado'])}</span><p class=meta><code>{$h($p['uuid'])}</code></p><dl>";
    foreach (json_decode($p['datos'], true) as $k => $v) $html .= "<dt>{$h($k)}</dt><dd>{$h(is_scalar($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE))}</dd>";
    $html .= '</dl><div class=acciones>';
    if ($p['estado'] !== 'aceptada' && !$p['archivado']) $html .= "<a class=boton href='/admin/obra?desde_propuesta={$h($p['uuid'])}'>Crear en el catálogo</a>";
    if (!$p['archivado']) foreach (['aceptada' => 'Aceptar', 'rechazada' => 'Rechazar'] as $e => $t) {
      if ($e !== $p['estado']) $html .= $boton('/admin/propuesta', ['uuid' => $p['uuid'], 'estado' => $e], $t, $e === 'aceptada' ? '' : 'rechazada');
    }
    $html .= $boton('/admin/propuesta/archivar', ['uuid' => $p['uuid'], 'archivar' => $p['archivado'] ? '' : '1'], $p['archivado'] ? 'Desarchivar' : 'Archivar')
      . '</div>' . zona_borrado('/admin/propuesta/eliminar', ['uuid' => $p['uuid']], $nombre_propuesta($p), 'esta propuesta', 'La etiqueta colocada y su obra en el catálogo, si ya se creó, no se tocan.') . '</article>';
  }
  pagina('Obras propuestas', $html);
}

// ---------------- Portada: envíos ----------------
$nombres = ['observacion' => 'Observación (QR)', 'contexto' => 'Contexto', 'obra_nueva' => 'Obra nueva', 'ruta' => 'Ruta propuesta', 'inscripcion' => 'Inscripción a salida', 'completar' => 'Completar ficha (datos desconocidos)'];
$estado = in_array($_GET['estado'] ?? '', ['aprobada', 'rechazada', 'archivados'], true) ? $_GET['estado'] : 'pendiente';
$n = $db->query("SELECT CASE WHEN archivado IS NOT NULL THEN 'archivados' ELSE estado END k, COUNT(*) n FROM envios GROUP BY k")->fetchAll(PDO::FETCH_KEY_PAIR);
$st = $db->prepare('SELECT e.*, c.alias AS cuenta FROM envios e LEFT JOIN colaboradores c ON c.id = e.colaborador WHERE '
  . ($estado === 'archivados' ? 'e.archivado IS NOT NULL ORDER BY e.archivado DESC' : 'e.archivado IS NULL AND e.estado = ? ORDER BY e.creado DESC') . ' LIMIT 200');
$st->execute($estado === 'archivados' ? [] : [$estado]);
$html = pestanas(['pendiente' => ['Pendientes', '/admin', $n['pendiente'] ?? 0], 'aprobada' => ['Aprobados', '/admin?estado=aprobada', $n['aprobada'] ?? 0],
  'rechazada' => ['Rechazados', '/admin?estado=rechazada', $n['rechazada'] ?? 0], 'archivados' => ['Archivados', '/admin?estado=archivados', $n['archivados'] ?? 0]], $estado);
$envios = $st->fetchAll();
if (!$envios) $html .= '<div class=vacio>No hay envíos aquí. Llegan las propuestas de obras y rutas, las observaciones hechas con el QR, las aportaciones de contexto y los datos que completa el público.</div>';
foreach ($envios as $e) {
  $html .= "<article><strong>{$h($nombres[$e['formulario']] ?? $e['formulario'])}</strong> <span class=meta>envio-{$e['id']} · " . ($estado === 'archivados' ? "<span class=etiqueta>{$h($e['estado'])}</span> · " : '') . "{$h($e['obra'])} · {$h($e['creado'])}" . ($e['cuenta'] ? " · con cuenta: <a href='/admin/personas'>{$h($e['cuenta'])}</a>" : ' · sin cuenta') . "</span><dl>";
  foreach (json_decode($e['datos'], true) as $k => $v) $html .= "<dt>{$h($k)}</dt><dd>{$h($v)}</dd>";
  $html .= '</dl>';
  foreach (json_decode($e['fotos'], true) as $f) $html .= "<a href='/admin/foto/{$h($f)}'><img src='/admin/foto/{$h($f)}' alt='Foto enviada'></a>";
  $html .= '<div class=acciones>';
  if (!$e['archivado']) {
    if ($e['formulario'] === 'obra_nueva' && $e['estado'] !== 'aprobada') $html .= "<a class=boton href='/admin/obra?desde_envio={$e['id']}'>Pasar al catálogo</a>";
    if ($e['formulario'] === 'completar' && $e['estado'] !== 'aprobada' && $e['obra']) $html .= "<a class=boton href='/admin/obra?id={$h($e['obra'])}&desde_completar={$e['id']}'>Revisar y pasar a la ficha</a>";
    if ($e['formulario'] === 'ruta' && $e['estado'] !== 'aprobada') $html .= "<a class=boton href='/admin/ruta?desde_envio={$e['id']}'>Pasar a rutas</a>";
    foreach (['aprobada' => 'Aprobar', 'rechazada' => 'Rechazar', 'pendiente' => 'Devolver a pendientes'] as $a => $t) {
      if ($a !== $e['estado']) $html .= $boton('/admin/estado', ['id' => $e['id'], 'estado' => $a, 'volver' => $estado], $t, $a === 'pendiente' ? '' : $a);
    }
  }
  $html .= $boton('/admin/envio/archivar', ['id' => $e['id'], 'archivar' => $e['archivado'] ? '' : '1', 'volver' => $estado], $e['archivado'] ? 'Desarchivar' : 'Archivar')
    . '</div>' . zona_borrado('/admin/envio/eliminar', ['id' => $e['id'], 'volver' => $estado], $nombre_envio($e), 'este envío',
      $e['estado'] === 'aprobada' ? 'Ya está aprobado: lo que se pasó a una ficha y los méritos sumados se quedan.' : 'También se borran sus fotos.') . '</article>';
}
pagina('Envíos', $html);
