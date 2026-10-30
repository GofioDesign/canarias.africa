<?php
// Cuentas de colaboradores: alias + correo verificado, entrada con enlace por correo (sin contraseñas).
// La web guarda el token de sesión y lo envía como «Authorization: Bearer <token>».

const NIVELES = ['visitante' => 0.5, 'colaborador' => 1.0, 'acreditado' => 1.25, 'custodia' => 1.5];

function cuerpo_json(): array {
  $d = json_decode(file_get_contents('php://input') ?: '[]', true);
  return is_array($d) ? $d : $_POST;
}

function crear_token(int $colaborador, string $tipo, string $duracion): string {
  $t = bin2hex(random_bytes(24));
  db()->prepare("INSERT INTO tokens (hash, tipo, colaborador, vence) VALUES (?, ?, ?, datetime('now', ?))")
      ->execute([hash('sha256', $t), $tipo, $colaborador, $duracion]);
  return $t;
}

function enviar_enlace(array $c): void {
  $st = db()->prepare("SELECT COUNT(*) FROM tokens WHERE colaborador = ? AND tipo = 'enlace' AND vence > datetime('now', '+23 hours')");
  $st->execute([$c['id']]);
  if ($st->fetchColumn() >= 3) error_publico('Ya te hemos enviado varios enlaces hace poco. Mira tu correo o prueba dentro de una hora.', 429);
  $t = crear_token((int) $c['id'], 'enlace', '+24 hours');
  $url = config()['web'] . '/#/entrar?t=' . $t;
  $texto = "Hola, {$c['alias']}:\n\nPara entrar en canarias.africa abre este enlace (vale 24 horas y una sola vez):\n$url\n\nSi no lo has pedido tú, ignora este correo.\n";
  $de = config()['contacto'];
  @mail($c['correo'], '=?UTF-8?B?' . base64_encode('Tu enlace para entrar en canarias.africa') . '?=', $texto,
    "From: canarias.africa <$de>\r\nContent-Type: text/plain; charset=utf-8");
}

// POST /api/cuenta/alta {alias, correo, acepta_privacidad}  ·  POST /api/cuenta/entrar {correo}
function cuenta_alta(): never {
  $d = cuerpo_json();
  $alias = trim((string) ($d['alias'] ?? ''));
  $correo = strtolower(trim((string) ($d['correo'] ?? '')));
  if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) error_publico('El correo no es válido.');
  if (!preg_match('/^[\p{L}\p{N} ._-]{3,40}$/u', $alias)) error_publico('El alias debe tener entre 3 y 40 letras, números, espacios, puntos o guiones.');
  if (!in_array((string) ($d['acepta_privacidad'] ?? ''), ['true', 'on', '1'], true)) error_publico('Hay que aceptar el aviso de privacidad.');
  $db = db();
  $st = $db->prepare('SELECT * FROM colaboradores WHERE correo = ?');
  $st->execute([$correo]);
  $c = $st->fetch();
  if (!$c) {
    try {
      $db->prepare('INSERT INTO colaboradores (alias, correo) VALUES (?, ?)')->execute([$alias, $correo]);
    } catch (PDOException) {
      error_publico('Ese alias ya está en uso. Elige otro.', 409);
    }
    $c = ['id' => $db->lastInsertId(), 'alias' => $alias, 'correo' => $correo];
  }
  enviar_enlace($c);
  json_respuesta(['ok' => true, 'mensaje' => 'Te hemos enviado un enlace para entrar.']);
}

function cuenta_entrar(): never {
  $correo = strtolower(trim((string) (cuerpo_json()['correo'] ?? '')));
  $st = db()->prepare('SELECT * FROM colaboradores WHERE correo = ?');
  $st->execute([$correo]);
  if ($c = $st->fetch()) enviar_enlace($c);
  // La misma respuesta exista o no la cuenta, para no revelar qué correos están dados de alta.
  json_respuesta(['ok' => true, 'mensaje' => 'Si ese correo tiene cuenta, te hemos enviado un enlace para entrar.']);
}

// POST /api/cuenta/sesion {t}: canjea el enlace del correo por un token de sesión de 90 días.
function cuenta_sesion(): never {
  $db = db();
  $hash = hash('sha256', (string) (cuerpo_json()['t'] ?? ''));
  $st = $db->prepare("SELECT colaborador FROM tokens WHERE hash = ? AND tipo = 'enlace' AND usado = 0 AND vence > datetime('now')");
  $st->execute([$hash]);
  $id = $st->fetchColumn();
  if (!$id) error_publico('El enlace no es válido o ha caducado. Pide otro.', 401);
  $db->prepare('UPDATE tokens SET usado = 1 WHERE hash = ?')->execute([$hash]);
  $db->prepare('UPDATE colaboradores SET verificado = 1 WHERE id = ?')->execute([$id]);
  json_respuesta(['token' => crear_token((int) $id, 'sesion', '+90 days')] + perfil((int) $id));
}

// Colaborador de la petición (o null si es un visitante).
function colaborador_actual(): ?array {
  static $c = false;
  if ($c !== false) return $c;
  $c = null;
  if (preg_match('/^Bearer\s+([0-9a-f]{48})$/', $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '', $m)) {
    $st = db()->prepare("SELECT c.* FROM tokens t JOIN colaboradores c ON c.id = t.colaborador
                         WHERE t.hash = ? AND t.tipo = 'sesion' AND t.vence > datetime('now') AND c.verificado = 1");
    $st->execute([hash('sha256', $m[1])]);
    $c = $st->fetch() ?: null;
  }
  return $c;
}

function exigir_colaborador(): array {
  return colaborador_actual() ?? error_publico('Tienes que entrar con tu cuenta de colaborador.', 401);
}

function nivel(?array $c): string {
  if (!$c) return 'visitante';
  $st = db()->prepare("SELECT
      (SELECT COUNT(*) FROM eventos_merito WHERE colaborador = ?1 AND tipo = 'colocacion_confirmada'),
      (SELECT COUNT(*) FROM verificaciones WHERE colaborador = ?1 AND valida = 1)");
  $st->execute([$c['id']]);
  [$colocadas, $verificadas] = $st->fetch(PDO::FETCH_NUM);
  if ($c['custodia'] || ($colocadas >= 15 && $verificadas >= 30)) return 'custodia';
  return $colocadas >= 3 ? 'acreditado' : 'colaborador';
}

function perfil(int $id): array {
  $st = db()->prepare('SELECT * FROM colaboradores WHERE id = ?');
  $st->execute([$id]);
  $c = $st->fetch();
  $nivel = nivel($c);
  $st = db()->prepare("SELECT a.qr, a.vence, a.descargada, q.estado FROM asignaciones a JOIN qr_codigos q ON q.codigo = a.qr
                       WHERE a.colaborador = ? AND (a.resultado IS NULL OR (a.resultado = 'caducado' AND q.estado = 'caducado')) ORDER BY a.vence");
  $st->execute([$id]);
  $abiertas = $st->fetchAll();
  $cupo = config()['qr']['cupos'][$nivel];
  return ['alias' => $c['alias'], 'nivel' => $nivel, 'meritos' => (int) $c['meritos'], 'cupo' => $cupo,
          'disponibles' => max(0, $cupo - count(array_filter($abiertas, fn($a) => $a['estado'] === 'asignado'))),
          'etiquetas' => $abiertas];
}

function sumar_meritos(int $colaborador, string $tipo, int $puntos, string $referencia): void {
  $db = db();
  $st = $db->prepare('INSERT OR IGNORE INTO eventos_merito (colaborador, tipo, puntos, referencia) VALUES (?, ?, ?, ?)');
  $st->execute([$colaborador, $tipo, $puntos, $referencia]);
  if ($st->rowCount()) $db->prepare('UPDATE colaboradores SET meritos = meritos + ? WHERE id = ?')->execute([$puntos, $colaborador]);
}
