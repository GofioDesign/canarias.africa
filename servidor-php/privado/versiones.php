<?php
// Árbol de modificaciones público de una obra (lo leen scripts/generar-fichas.py y la web).
//   GET /api/publico/obras/<uuid>/versiones  {obra, version, versiones: [{version, fecha, cambios: [{campo, antes?, ahora?}]}]}
// Cada versión dice cuándo empezó y qué campos cambiaron respecto a la anterior. Solo se dan los valores de los campos
// que ya son públicos sin matices; de la ubicación, las personas, quién pagó o las fuentes solo se dice que cambiaron.
// Solo para obras publicadas.

const CAMPOS_CON_VALOR = ['titulo', 'fase', 'disciplina', 'permanencia', 'tipo', 'descripcion', 'fecha_obra', 'fecha_precision', 'foto', 'foto_credito', 'ficha', 'sensibilidad'];

function versiones_publicas(string $obra): never {
  $obra = strtolower($obra);
  $actual = obra($obra);
  if (!$actual || empty($actual['publicada'])) error_publico('No existe.', 404);
  $st = db()->prepare('SELECT version, datos, fecha FROM obras_versiones WHERE obra = ? ORDER BY version, id');
  $st->execute([$obra]);
  $previas = [];
  foreach ($st as $f) $previas[(int) $f['version']] = ['datos' => json_decode($f['datos'], true) ?: [], 'hasta' => $f['fecha']];
  $estados = $previas + [(int) $actual['version'] => ['datos' => $actual, 'hasta' => null]];
  ksort($estados);
  $out = [];
  $antes = null;
  $inicio = $actual['creado'] ?? null;
  foreach ($estados as $v => $e) {
    $cambios = [];
    if ($antes === null) $cambios[] = ['campo' => 'alta'];
    else foreach (CAMPOS_OBRA as $k) {
      if (($antes[$k] ?? null) == ($e['datos'][$k] ?? null)) continue;
      $cambios[] = in_array($k, CAMPOS_CON_VALOR, true) ? ['campo' => $k, 'antes' => $antes[$k] ?? null, 'ahora' => $e['datos'][$k] ?? null] : ['campo' => $k];
    }
    $out[] = ['version' => $v, 'fecha' => fecha_iso($inicio), 'cambios' => $cambios];
    $antes = $e['datos'];
    $inicio = $e['hasta'];
  }
  header('Cache-Control: public, max-age=600');
  json_respuesta(['obra' => $obra, 'version' => (int) $actual['version'], 'versiones' => $out]);
}
