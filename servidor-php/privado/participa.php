<?php
// «Quiero participar» de la página de lanzamiento: lista de correo con doble confirmación y votos de las propuestas.
// Nada cuenta hasta que la persona confirma su correo: los votos, alias e ideas de cada envío esperan en
// participantes.pendiente y se aplican al confirmar. Así nadie puede cambiar los votos de otra persona con su correo.

const VOTOS = ['prioritaria', 'interesante', 'no'];
const ISLAS = ['', 'El Hierro', 'La Palma', 'La Gomera', 'Tenerife', 'Gran Canaria', 'Fuerteventura', 'Lanzarote', 'La Graciosa', 'fuera'];
const PERFILES = ['', 'artista', 'vecindad', 'investigacion', 'entidad', 'otro'];

// Ids de las propuestas votables según la copia publicada de propuestas.json (caché de 1 hora).
// Si no se puede leer, devuelve null y solo se comprueba el formato del id.
function propuestas_votables(): ?array {
  $f = PRIVADO . '/cache/propuestas.json';
  if (!is_file($f) || filemtime($f) < time() - 3600) {
    $ch = curl_init(config()['web'] . '/propuestas.json');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_USERAGENT => 'canarias.africa participa']);
    $json = curl_exec($ch);
    if (curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200 && is_array(json_decode((string) $json, true))) {
      @mkdir(dirname($f), 0700, true);
      file_put_contents("$f.tmp", $json);
      rename("$f.tmp", $f);
    } else {
      @touch($f); // no reintentar en cada envío si GitHub Pages no responde
    }
  }
  $d = json_decode((string) @file_get_contents($f), true);
  if (!is_array($d['propuestas'] ?? null)) return null;
  $ids = [];
  foreach ($d['propuestas'] as $p) if (in_array($p['estado'] ?? '', ['abierta', 'aprobada'], true)) $ids[] = (string) $p['id'];
  return $ids;
}

// POST /api/participa {correo, alias, isla, perfil, votos: {id: voto}, ideas_nuevas: [texto], acepta_privacidad}
function participa_alta(): never {
  $d = cuerpo_json();
  $correo = strtolower(trim((string) ($d['correo'] ?? '')));
  if (!filter_var($correo, FILTER_VALIDATE_EMAIL) || strlen($correo) > 254) error_publico('Escribe un correo válido para poder apuntarte.');
  if (!in_array((string) ($d['acepta_privacidad'] ?? ''), ['true', 'on', '1'], true)) error_publico('Marca la casilla de privacidad para poder apuntarte.');
  $alias = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($d['alias'] ?? ''))), 0, 60);
  $isla = in_array($d['isla'] ?? '', ISLAS, true) ? ($d['isla'] ?? '') : '';
  $perfil = in_array($d['perfil'] ?? '', PERFILES, true) ? ($d['perfil'] ?? '') : '';

  $votos = is_array($d['votos'] ?? null) ? $d['votos'] : [];
  if (count($votos) > 100) error_publico('Demasiados votos en un solo envío.');
  $validos = propuestas_votables();
  foreach ($votos as $id => $voto) {
    if (!preg_match('/^[a-z0-9-]{1,60}$/', (string) $id) || !in_array($voto, VOTOS, true)) error_publico('Hay un voto que no se entiende. Recarga la página y prueba otra vez.');
    if ($validos !== null && !in_array((string) $id, $validos, true)) unset($votos[$id]); // propuesta cerrada o retirada
  }
  $ideas = [];
  foreach (array_slice(is_array($d['ideas_nuevas'] ?? null) ? $d['ideas_nuevas'] : [], 0, 5) as $t) {
    if (is_string($t) && trim($t) !== '') $ideas[] = mb_substr(trim($t), 0, 3000);
  }

  $db = db();
  $ip = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . date('Y-m-d'));
  $st = $db->prepare("SELECT COUNT(*) FROM participa_envios WHERE ip_hash = ? AND creado > datetime('now', '-1 hour')");
  $st->execute([$ip]);
  if ($st->fetchColumn() >= config()['envios_por_hora_e_ip']) error_publico('Demasiados envíos seguidos. Prueba dentro de un rato.', 429);
  $st = $db->prepare("SELECT COUNT(*) FROM participa_envios WHERE correo = ? AND creado > datetime('now', '-1 day')");
  $st->execute([$correo]);
  if ($st->fetchColumn() >= 3) error_publico('Ya te hemos enviado varios correos hoy. Busca el último en tu bandeja (o en spam) y ábrelo para confirmar.', 429);
  $db->prepare('INSERT INTO participa_envios (correo, ip_hash) VALUES (?, ?)')->execute([$correo, $ip]);

  $st = $db->prepare('SELECT * FROM participantes WHERE correo = ?');
  $st->execute([$correo]);
  $p = $st->fetch();
  if (!$p) {
    $db->prepare('INSERT INTO participantes (correo, baja) VALUES (?, ?)')->execute([$correo, bin2hex(random_bytes(16))]);
    $st->execute([$correo]);
    $p = $st->fetch();
  }
  $t = bin2hex(random_bytes(24));
  $pendiente = json_encode(['alias' => $alias, 'isla' => $isla, 'perfil' => $perfil, 'votos' => $votos, 'ideas' => $ideas], JSON_UNESCAPED_UNICODE);
  $db->prepare("UPDATE participantes SET pendiente = ?, confirmar_hash = ?, confirmar_vence = datetime('now', '+7 days') WHERE id = ?")
     ->execute([$pendiente, hash('sha256', $t), $p['id']]);

  $n = count($votos);
  $texto = ($alias !== '' ? "Hola, $alias:" : 'Hola:') . "\n\n"
    . "Gracias por sumarte a CANARIAS, Catálogo Atlántico de Núnea Arqueología. Para confirmar tu correo" . ($n ? " y que cuenten tus $n votos" : '') . ", abre este enlace (vale 7 días):\n"
    . config()['web'] . "/#confirmar?t=$t\n\n"
    . "Si vuelves a votar más adelante, en cada propuesta cuenta tu último voto.\n"
    . "Si no te has apuntado tú, ignora este correo: sin confirmar no te escribiremos más.\n";
  enviar_correo_lista($correo, 'Confirma tu correo para participar en canarias.africa', $texto, $p['baja']);
  json_respuesta(['ok' => true, 'mensaje' => 'Te hemos enviado un correo para confirmar tu dirección.'], 201);
}

// POST /api/participa/confirmar {t}: aplica lo pendiente. En cada propuesta cuenta el último voto confirmado;
// las que no vienen en este envío conservan el voto anterior.
function participa_confirmar(): never {
  $t = (string) (cuerpo_json()['t'] ?? '');
  if (!preg_match('/^[0-9a-f]{48}$/', $t)) error_publico('El enlace no es válido.');
  $db = db();
  $st = $db->prepare("SELECT * FROM participantes WHERE confirmar_hash = ? AND confirmar_vence > datetime('now')");
  $st->execute([hash('sha256', $t)]);
  $p = $st->fetch() ?: error_publico('El enlace ha caducado o ya se usó.', 410);
  $d = json_decode((string) $p['pendiente'], true) ?: [];
  $db->beginTransaction();
  $db->prepare("UPDATE participantes SET estado = 'confirmada', confirmado = COALESCE(confirmado, datetime('now')), actualizado = datetime('now'),
      alias = ?, isla = ?, perfil = ?, pendiente = NULL, confirmar_hash = NULL, confirmar_vence = NULL WHERE id = ?")
     ->execute([$d['alias'] ?? '', $d['isla'] ?? '', $d['perfil'] ?? '', $p['id']]);
  $ins = $db->prepare('INSERT OR REPLACE INTO participa_votos (participante, propuesta, voto) VALUES (?, ?, ?)');
  foreach ($d['votos'] ?? [] as $id => $v) $ins->execute([$p['id'], $id, $v]);
  $ins = $db->prepare('INSERT INTO participa_ideas (participante, texto) VALUES (?, ?)');
  foreach ($d['ideas'] ?? [] as $texto) $ins->execute([$p['id'], $texto]);
  $db->commit();
  if (!empty($d['ideas'])) avisar('canarias.africa: idea nueva', 'Hay ideas nuevas para revisar en https://' . ($_SERVER['HTTP_HOST'] ?? '') . "/admin/participa\n");
  json_respuesta(['ok' => true]);
}

// GET /api/participa/resultados: votos de las personas confirmadas (las que se dan de baja siguen contando).
function participa_resultados(): never {
  $db = db();
  $ideas = [];
  foreach ($db->query("SELECT v.propuesta, v.voto, COUNT(*) n FROM participa_votos v JOIN participantes p ON p.id = v.participante
      WHERE p.estado IN ('confirmada', 'baja') GROUP BY v.propuesta, v.voto") as $f) {
    $ideas[$f['propuesta']] ??= array_fill_keys(VOTOS, 0);
    $ideas[$f['propuesta']][$f['voto']] = (int) $f['n'];
  }
  $n = (int) $db->query("SELECT COUNT(*) FROM participantes WHERE estado = 'confirmada'")->fetchColumn();
  header('Cache-Control: public, max-age=60');
  json_respuesta(['participantes' => $n, 'ideas' => (object) $ideas]);
}

// GET /api/participa/baja?t=… muestra un botón; POST hace la baja. Un GET no da de baja, porque los filtros
// de correo abren los enlaces solos. El POST también sirve para la baja en un clic de Gmail y otros (RFC 8058).
function participa_baja(string $metodo): never {
  $t = (string) ($_GET['t'] ?? $_POST['t'] ?? '');
  $p = null;
  if (preg_match('/^[0-9a-f]{32}$/', $t)) {
    $st = db()->prepare('SELECT * FROM participantes WHERE baja = ?');
    $st->execute([$t]);
    $p = $st->fetch();
  }
  if (!$p) pagina_simple('Enlace no válido', '<p>Este enlace de baja no es válido. Si sigues recibiendo correos, contesta a cualquiera de ellos y te damos de baja a mano.</p>', 404);
  if ($metodo === 'POST') {
    // Se borran los datos de contacto que no hacen falta; el correo se queda para no volver a escribirle y para no duplicar sus votos.
    db()->prepare("UPDATE participantes SET estado = 'baja', alias = '', isla = '', perfil = '', pendiente = NULL, confirmar_hash = NULL,
        confirmar_vence = NULL, actualizado = datetime('now') WHERE id = ?")->execute([$p['id']]);
    pagina_simple('Te has dado de baja', '<p>No te escribiremos más. Si cambias de idea, vuelve a apuntarte en <a href="' . config()['web'] . '/#participa">canarias.africa</a>.</p>');
  }
  $correo = htmlspecialchars($p['correo'], ENT_QUOTES);
  pagina_simple('Darte de baja', $p['estado'] === 'baja' ? '<p>Ya estás de baja. No te escribiremos más.</p>'
    : "<p>¿Quieres dejar de recibir correos de CANARIAS (Catálogo Atlántico de Núnea Arqueología) en <b>$correo</b>?</p>
       <form method=post><input type=hidden name=t value=$t><button>Darme de baja</button></form>");
}

function enviar_correo_lista(string $para, string $asunto, string $texto, string $baja): void {
  $c = config();
  $url = rtrim($c['api'], '/') . '/api/participa/baja?t=' . $baja;
  $texto .= "\n--\nCANARIAS · Catálogo Atlántico de Núnea Arqueología · " . $c['web'] . "\nPara no recibir más correos: $url\n";
  @mail($para, '=?UTF-8?B?' . base64_encode($asunto) . '?=', $texto, implode("\r\n", [
    "From: =?UTF-8?B?" . base64_encode('CANARIAS · Catálogo Atlántico de Núnea Arqueología') . "?= <{$c['contacto']}>",
    'Content-Type: text/plain; charset=utf-8',
    "List-Unsubscribe: <$url>",
    'List-Unsubscribe-Post: List-Unsubscribe=One-Click',
  ]));
}

function pagina_simple(string $titulo, string $cuerpo, int $estado = 200): never {
  http_response_code($estado);
  header('Content-Type: text/html; charset=utf-8');
  header('X-Robots-Tag: noindex');
  echo "<!doctype html><html lang=es><head><meta charset=utf-8><meta name=viewport content='width=device-width, initial-scale=1'><title>$titulo · canarias.africa</title>
<style>:root{--bg:#faf6ee;--fg:#16130f;--ac:#0A8A3A}@media (prefers-color-scheme:dark){:root{--bg:#12100d;--fg:#f4eee2;--ac:#2FA84F}}
body{margin:0;background:var(--bg);color:var(--fg);font:17px/1.55 system-ui,sans-serif}main{max-width:560px;margin:0 auto;padding:40px 16px}
a{color:inherit}button{font:inherit;padding:10px 16px;border:0;border-radius:6px;background:var(--ac);color:#fff;cursor:pointer}</style></head>
<body><main><h1>$titulo</h1>$cuerpo</main></body></html>";
  exit;
}
