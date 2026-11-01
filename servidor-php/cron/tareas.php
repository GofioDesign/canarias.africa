<?php
// Tareas programadas. Configurar en el panel del hosting cada hora:
//   php /ruta/a/servidor-php/cron/tareas.php
declare(strict_types=1);
require __DIR__ . '/../privado/lib.php';
$c = config();

// 0. Catálogo: se edita en /admin. Si lo publicado ha cambiado (por ejemplo, el estado de una etiqueta QR) y no se ha
//    publicado aún, se pide a GitHub que vuelva a copiarlo a canarias.africa/datos/.
publicar_web();

// 1. Etiquetas: plazos de las asignaciones y continuidad de las verificaciones.
caducar_asignaciones();
envejecer_etiquetas();

// 2. Recortar la caché de teselas: si pasa del límite, se borran primero las menos usadas recientemente.
$dir = PRIVADO . '/cache/teselas';
if (is_dir($dir)) {
  $ficheros = [];
  $total = 0;
  foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
    $ficheros[] = [$f->getPathname(), $f->getATime(), $f->getSize()];
    $total += $f->getSize();
  }
  $limite = $c['teselas_max_mb'] * 1024 * 1024;
  if ($total > $limite) {
    usort($ficheros, fn($a, $b) => $a[1] <=> $b[1]);
    foreach ($ficheros as [$ruta, , $tam]) {
      if ($total <= $limite * 0.8) break;
      @unlink($ruta);
      $total -= $tam;
    }
  }
}

// 3. Una vez al día: copia de la base de datos (se guardan 7) y borrado de las huellas de IP antiguas.
if ((int) date('G') === 4) {
  $db = db();
  @mkdir(PRIVADO . '/copias', 0700, true);
  $f = PRIVADO . '/copias/canarias-' . date('Y-m-d') . '.sqlite';
  @unlink($f);
  $db->exec('VACUUM INTO ' . $db->quote($f));
  $copias = glob(PRIVADO . '/copias/canarias-*.sqlite');
  sort($copias);
  foreach (array_slice($copias, 0, -7) as $vieja) unlink($vieja);
  $db->exec("UPDATE envios SET ip_hash = NULL WHERE creado < datetime('now', '-30 days')");
  $db->exec("DELETE FROM participa_envios WHERE creado < datetime('now', '-30 days')");
  // Altas que nunca se confirmaron: fuera a los 30 días.
  $db->exec("DELETE FROM participantes WHERE estado = 'pendiente' AND creado < datetime('now', '-30 days')");
}
