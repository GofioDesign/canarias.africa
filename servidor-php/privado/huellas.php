<?php
// Huellas de espectadores: cada visita a la ficha y cada escaneo de la etiqueta deja un trazo anónimo en la obra.
// Solo se traza con la sesión iniciada (decisión del 01-11-2026): sin cuenta no se registra nada.
//   POST /api/obras/<uuid>/huella           la web la envía al abrir una ficha con sesión (no si el navegador pide no ser rastreado)
//   GET  /api/publico/obras/<uuid>/huellas  {visitas, escaneos, verificaciones, observaciones, meses: {AAAA-MM: n}} (solo con sesión)
// Las visitas sin sesión no son trazo de la obra: solo se suman, sin identificar, al mapa de calor.
//   POST /api/publico/obras/<uuid>/visita   visita sin sesión (una por navegador, obra y día; sin cookies ni IP guardada)
//   GET  /api/publico/calor                  {desde, obras: {<uuid>: n}} de los últimos 90 días; solo obras con 5 o más

const CALOR_MINIMO = 5;   // por debajo no se publica: con tan pocas visitas se podría adivinar quién fue
const CALOR_DIAS = 90;

function sumar_huella(string $obra, string $tipo): void {
  $yo = colaborador_actual();
  if (!$yo && $tipo !== 'anonima') return;  // sin sesión no hay huella
  $obra = strtolower($obra);
  $dia = gmdate('Y-m-d');
  $db = db();
  $st = $db->prepare('SELECT 1 FROM obras WHERE uuid = ?');
  $st->execute([$obra]);
  if (!$st->fetchColumn()) return;
  // Una huella por persona, obra, tipo y día. La sal cambia cada día y no se guarda: el hash no dice quién fue.
  $sal = hash('sha256', $dia . (config()['admin_hash'] ?? '') . PRIVADO);
  $quien = $yo ? 'c' . $yo['id'] : 'a' . ($_SERVER['REMOTE_ADDR'] ?? '') . ($_SERVER['HTTP_USER_AGENT'] ?? '');
  $h = hash('sha256', $sal . $quien . $obra . $tipo);
  $st = $db->prepare('INSERT OR IGNORE INTO huellas_vistas (hash, dia) VALUES (?, ?)');
  $st->execute([$h, $dia]);
  if (!$st->rowCount()) return;
  $db->prepare('INSERT INTO huellas (obra, dia, tipo, n) VALUES (?, ?, ?, 1) ON CONFLICT(obra, dia, tipo) DO UPDATE SET n = n + 1')
     ->execute([$obra, $dia, $tipo]);
}

function huella_visita(string $obra): never {
  exigir_colaborador();
  sumar_huella($obra, 'visita');
  http_response_code(204);
  exit;
}

function visita_anonima(string $obra): never {
  if (!colaborador_actual()) sumar_huella($obra, 'anonima');
  http_response_code(204);
  exit;
}

function calor(): never {
  $st = db()->prepare("SELECT obra, SUM(n) AS n FROM huellas WHERE tipo = 'anonima' AND dia >= date('now', ?) GROUP BY obra HAVING n >= ?");
  $st->execute(['-' . CALOR_DIAS . ' days', CALOR_MINIMO]);
  header('Cache-Control: public, max-age=3600');
  json_respuesta(['desde' => gmdate('Y-m-d', time() - CALOR_DIAS * 86400), 'obras' => array_map('intval', $st->fetchAll(PDO::FETCH_KEY_PAIR))]);
}

function huellas_publicas(string $obra): never {
  $obra = strtolower($obra);
  $db = db();
  $st = $db->prepare("SELECT tipo, SUM(n) FROM huellas WHERE obra = ? AND tipo <> 'anonima' GROUP BY tipo");
  $st->execute([$obra]);
  $t = $st->fetchAll(PDO::FETCH_KEY_PAIR);
  $st = $db->prepare("SELECT substr(dia, 1, 7), SUM(n) FROM huellas WHERE obra = ? AND tipo <> 'anonima' GROUP BY 1 ORDER BY 1");
  $st->execute([$obra]);
  $meses = array_map('intval', $st->fetchAll(PDO::FETCH_KEY_PAIR));
  $st = $db->prepare("SELECT COUNT(*) FROM verificaciones v JOIN qr_codigos q ON q.codigo = v.qr WHERE q.obra = ? AND v.valida = 1");
  $st->execute([$obra]);
  $verificaciones = (int) $st->fetchColumn();
  $st = $db->prepare("SELECT COUNT(*) FROM envios WHERE obra = ? AND formulario IN ('observacion','contexto') AND estado = 'aprobada'");
  $st->execute([$obra]);
  header('Cache-Control: public, max-age=600');
  json_respuesta(['obra' => $obra, 'visitas' => (int) ($t['visita'] ?? 0), 'escaneos' => (int) ($t['escaneo'] ?? 0),
                  'verificaciones' => $verificaciones, 'observaciones' => (int) $st->fetchColumn(), 'meses' => $meses]);
}
