<?php
// Vínculos de personas: privados por defecto. Ver la tabla «vinculos» en esquema.sql.
//   POST /api/vinculos                       {tipo, alias? | obra?, rol?, nota?}  crear (entre personas queda propuesto)
//   GET  /api/vinculos/mios                  los míos, con nombre, y los que esperan mi respuesta
//   GET  /api/vinculos/obra/<uuid>           los de esta obra de los que formo parte, con nombre
//   POST /api/vinculos/<id>/aceptar|rechazar|retirar
//   POST /api/vinculos/<id>/publicar         {publico: true|false}  mi consentimiento; se publica cuando lo dan todas las partes
//   GET  /api/publico/obras/<uuid>/vinculos  {privados: n, publicos: [...]}: el público solo ve el número y lo publicado

const RE_TIPO_VINCULO = '/^[a-z][a-z0-9_]{1,40}$/';

function vinculo(int $id): array {
  $st = db()->prepare('SELECT * FROM vinculos WHERE id = ?');
  $st->execute([$id]);
  return $st->fetch() ?: error_publico('Ese vínculo no existe.', 404);
}

function es_parte(array $v, int $yo): bool {
  return (int) $v['de'] === $yo || (int) $v['a'] === $yo;
}

// Un vínculo es público cuando todas sus partes lo consienten (entre personas, las dos; con una obra, quien lo creó).
function vinculo_publico(array $v): bool {
  return $v['estado'] === 'aceptado' && $v['publico_de'] && ($v['a'] === null || $v['publico_a']);
}

function alias_de(?int $id): ?string {
  if ($id === null) return null;
  $st = db()->prepare('SELECT alias FROM colaboradores WHERE id = ?');
  $st->execute([$id]);
  return $st->fetchColumn() ?: null;
}

// Lo que ve una de las partes: todo, con nombres.
function vinculo_para(array $v, int $yo): array {
  $otro = (int) $v['de'] === $yo ? ($v['a'] === null ? null : (int) $v['a']) : (int) $v['de'];
  return ['id' => (int) $v['id'], 'tipo' => $v['tipo'], 'rol' => $v['rol'], 'nota' => $v['nota'], 'obra' => $v['obra'],
          'con' => alias_de($otro), 'estado' => $v['estado'], 'propuesto_por_mi' => (int) $v['de'] === $yo,
          'publico' => vinculo_publico($v), 'mi_consentimiento' => (bool) ((int) $v['de'] === $yo ? $v['publico_de'] : $v['publico_a']),
          'creado' => $v['creado']];
}

function vinculo_crear(): never {
  $yo = exigir_colaborador();
  $d = cuerpo_json();
  $tipo = (string) ($d['tipo'] ?? '');
  if (!preg_match(RE_TIPO_VINCULO, $tipo)) error_publico('El tipo de vínculo va en minúsculas y con guiones bajos (familia, amistad, colaboracion…).');
  $rol = isset($d['rol']) ? mb_substr(trim((string) $d['rol']), 0, 60) : null;
  $nota = isset($d['nota']) ? mb_substr(trim((string) $d['nota']), 0, 500) : null;
  $db = db();
  // Límite para que nadie llene de propuestas a los demás.
  $st = $db->prepare("SELECT COUNT(*) FROM vinculos WHERE de = ? AND creado > datetime('now', '-1 day')");
  $st->execute([$yo['id']]);
  if ($st->fetchColumn() >= 30) error_publico('Has creado muchos vínculos hoy. Prueba mañana.', 429);

  if (!empty($d['alias'])) {
    $st = $db->prepare('SELECT id FROM colaboradores WHERE alias = ? AND verificado = 1');
    $st->execute([trim((string) $d['alias'])]);
    $otro = $st->fetchColumn() ?: error_publico('No hay ninguna persona con ese alias.', 404);
    if ((int) $otro === (int) $yo['id']) error_publico('Un vínculo necesita a otra persona.');
    $db->prepare('INSERT INTO vinculos (tipo, de, a, rol, nota) VALUES (?, ?, ?, ?, ?)')->execute([$tipo, $yo['id'], $otro, $rol, $nota]);
  } elseif (!empty($d['obra'])) {
    $obra = strtolower((string) $d['obra']);
    $st = $db->prepare('SELECT 1 FROM obras WHERE uuid = ?');
    $st->execute([$obra]);
    if (!$st->fetchColumn()) error_publico('Esa obra no está en el catálogo.', 404);
    // Con una obra no hace falta que nadie acepte: es tu propia línea temporal.
    $db->prepare("INSERT INTO vinculos (tipo, de, obra, rol, nota, estado) VALUES (?, ?, ?, ?, ?, 'aceptado')")->execute([$tipo, $yo['id'], $obra, $rol, $nota]);
  } else {
    error_publico('Indica el alias de la otra persona o la obra.');
  }
  json_respuesta(vinculo_para(vinculo((int) $db->lastInsertId()), (int) $yo['id']), 201);
}

function vinculos_mios(): never {
  $yo = (int) exigir_colaborador()['id'];
  $st = db()->prepare("SELECT * FROM vinculos WHERE (de = ?1 OR a = ?1) AND estado IN ('propuesto','aceptado') ORDER BY creado DESC");
  $st->execute([$yo]);
  $todos = array_map(fn($v) => vinculo_para($v, $yo), $st->fetchAll());
  json_respuesta(['vinculos' => array_values(array_filter($todos, fn($v) => $v['estado'] === 'aceptado' || $v['propuesto_por_mi'])),
                  'por_responder' => array_values(array_filter($todos, fn($v) => $v['estado'] === 'propuesto' && !$v['propuesto_por_mi']))]);
}

// Personas vinculadas a una obra (por un vínculo persona ↔ obra aceptado).
function personas_de_obra(string $obra): array {
  $st = db()->prepare("SELECT DISTINCT de FROM vinculos WHERE obra = ? AND estado = 'aceptado'");
  $st->execute([strtolower($obra)]);
  return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

// El mundo de una obra: sus vínculos con personas y los vínculos entre esas personas.
function vinculos_de_obra(string $obra): array {
  $personas = personas_de_obra($obra);
  $st = db()->prepare("SELECT * FROM vinculos WHERE obra = ? AND estado = 'aceptado'");
  $st->execute([strtolower($obra)]);
  $vs = $st->fetchAll();
  if ($personas) {
    $in = implode(',', $personas);  // enteros de la propia base de datos
    $vs = array_merge($vs, db()->query("SELECT * FROM vinculos WHERE a IS NOT NULL AND estado = 'aceptado' AND (de IN ($in) OR a IN ($in))")->fetchAll());
  }
  return $vs;
}

function vinculos_obra_mios(string $obra): never {
  $yo = (int) exigir_colaborador()['id'];
  $mios = array_filter(vinculos_de_obra($obra), fn($v) => es_parte($v, $yo));
  json_respuesta(['obra' => strtolower($obra), 'vinculos' => array_values(array_map(fn($v) => vinculo_para($v, $yo), $mios))]);
}

function vinculos_obra_publicos(string $obra): never {
  $vs = vinculos_de_obra($obra);
  $publicos = [];
  $privados = 0;
  foreach ($vs as $v) {
    if (!vinculo_publico($v)) { $privados++; continue; }
    $publicos[] = ['tipo' => $v['tipo'], 'rol' => $v['rol'], 'de' => alias_de((int) $v['de']), 'a' => alias_de($v['a'] === null ? null : (int) $v['a']),
                   'obra' => $v['obra']];
  }
  header('Cache-Control: public, max-age=300');
  json_respuesta(['obra' => strtolower($obra), 'privados' => $privados, 'publicos' => $publicos]);
}

function vinculo_accion(int $id, string $accion): never {
  $yo = (int) exigir_colaborador()['id'];
  $v = vinculo($id);
  if (!es_parte($v, $yo)) error_publico('Ese vínculo no existe.', 404);  // a quien no forma parte, ni se le confirma que exista
  $db = db();
  switch ($accion) {
    case 'aceptar':
    case 'rechazar':
      if ($v['estado'] !== 'propuesto' || (int) $v['a'] !== $yo) error_publico('Solo la otra persona puede responder a una propuesta pendiente.');
      $db->prepare("UPDATE vinculos SET estado = ?, cambiado = datetime('now') WHERE id = ?")->execute([$accion === 'aceptar' ? 'aceptado' : 'rechazado', $id]);
      break;
    case 'retirar':
      // Cualquiera de las partes puede retirar un vínculo, y deja de verse (también el número público).
      $db->prepare("UPDATE vinculos SET estado = 'retirado', publico_de = 0, publico_a = 0, cambiado = datetime('now') WHERE id = ?")->execute([$id]);
      break;
    case 'publicar':
      $si = in_array(cuerpo_json()['publico'] ?? false, [true, 'true', '1', 1], true) ? 1 : 0;
      $col = (int) $v['de'] === $yo ? 'publico_de' : 'publico_a';
      $db->prepare("UPDATE vinculos SET $col = ?, cambiado = datetime('now') WHERE id = ?")->execute([$si, $id]);
      break;
  }
  json_respuesta(vinculo_para(vinculo($id), $yo));
}
