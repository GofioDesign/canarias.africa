<?php
// Tareas programadas. Configurar en el panel del hosting cada hora:
//   php /ruta/a/servidor-php/cron/tareas.php
declare(strict_types=1);
require __DIR__ . '/../privado/lib.php';
$c = config();

// 1. Copia de las capas públicas de uMap. Si una descarga falla, se conserva la anterior.
if ($c['umap']['mapa']) {
  @mkdir(PRIVADO . '/umap', 0700, true);
  foreach ($c['umap']['capas'] as $nombre => $capa) {
    if (!$capa) continue;
    $ch = curl_init("{$c['umap']['servidor']}/datalayer/{$c['umap']['mapa']}/$capa/");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_USERAGENT => 'canarias.africa umap sync']);
    $gj = curl_exec($ch);
    if (curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200 && (json_decode((string) $gj, true)['type'] ?? '') === 'FeatureCollection') {
      file_put_contents(PRIVADO . "/umap/$nombre.geojson", $gj);
    } else {
      fwrite(STDERR, "uMap: no se pudo actualizar la capa $nombre\n");
    }
  }
}

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
}
