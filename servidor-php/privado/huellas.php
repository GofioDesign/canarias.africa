<?php
// Huellas de espectadores: cada visita a la ficha y cada escaneo de la etiqueta deja un trazo anónimo en la obra.
//   POST /api/publico/obras/<uuid>/huella   la web la envía al abrir una ficha (no si el navegador pide no ser rastreado)
//   GET  /api/publico/obras/<uuid>/huellas  {visitas, escaneos, verificaciones, observaciones, meses: {AAAA-MM: n}}

function sumar_huella(string $obra, string $tipo): void {
  $obra = strtolower($obra);
  $dia = gmdate('Y-m-d');
  $db = db();
  $st = $db->prepare('SELECT 1 FROM obras WHERE uuid = ?');
  $st->execute([$obra]);
  if (!$st->fetchColumn()) return;
  // Una huella por persona, obra, tipo y día. La sal cambia cada día y no se guarda: el hash no se puede revertir.
  $sal = hash('sha256', $dia . (config()['admin_hash'] ?? '') . PRIVADO);
  $h = hash('sha256', $sal . ($_SERVER['REMOTE_ADDR'] ?? '') . ($_SERVER['HTTP_USER_AGENT'] ?? '') . $obra . $tipo);
  $st = $db->prepare('INSERT OR IGNORE INTO huellas_vistas (hash, dia) VALUES (?, ?)');
  $st->execute([$h, $dia]);
  if (!$st->rowCount()) return;
  $db->prepare('INSERT INTO huellas (obra, dia, tipo, n) VALUES (?, ?, ?, 1) ON CONFLICT(obra, dia, tipo) DO UPDATE SET n = n + 1')
     ->execute([$obra, $dia, $tipo]);
}

function huella_visita(string $obra): never {
  sumar_huella($obra, 'visita');
  http_response_code(204);
  exit;
}

function huellas_publicas(string $obra): never {
  $obra = strtolower($obra);
  $db = db();
  $st = $db->prepare("SELECT tipo, SUM(n) FROM huellas WHERE obra = ? GROUP BY tipo");
  $st->execute([$obra]);
  $t = $st->fetchAll(PDO::FETCH_KEY_PAIR);
  $st = $db->prepare("SELECT substr(dia, 1, 7), SUM(n) FROM huellas WHERE obra = ? GROUP BY 1 ORDER BY 1");
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
