<?php
// /admin: catálogo de obras, rutas y artistas. Lo que se guarda aquí es la fuente de /datos/*.geojson,
// que leen la web y las capas de uMap. Se incluye desde admin.php con $sub, $metodo, $db, $csrf y $h ya definidos.

$ETIQUETAS_FASE = ['gestacion' => 'Gestación', 'creacion' => 'Creación', 'plenitud' => 'Plenitud', 'desgaste' => 'Desgaste',
  'intervencion' => 'Intervención', 'transformacion' => 'Transformación', 'desaparicion' => 'Desaparición', 'memoria' => 'Memoria'];
$ETIQUETAS_SENS = ['abierta' => 'Abierta (punto exacto)', 'difusa' => 'Difusa (zona de ~2 km)', 'reservada' => 'Reservada: obra delicada (solo la isla, con un punto simbólico; sin QR)'];

// Si viene una foto del formulario (la cámara del móvil o un archivo), la guarda sin metadatos y devuelve su URL pública.
$foto_formulario = function (): ?string {
  $f = $_FILES['foto_archivo'] ?? null;
  if (!$f || $f['error'] === UPLOAD_ERR_NO_FILE || ($f['error'] === UPLOAD_ERR_OK && $f['size'] === 0)) return null;
  if ($f['error'] !== UPLOAD_ERR_OK || $f['size'] > 15 * 1024 * 1024) throw new InvalidArgumentException('La foto no ha llegado bien o pasa de 15 MB.');
  return rtrim(config()['api'], '/') . '/datos/fotos/' . guardar_foto($f['tmp_name'], mime_content_type($f['tmp_name']) ?: '');
};

$campo = function (string $nombre, string $etiqueta, $valor, string $tipo = 'text', string $ayuda = '', array $extra = []) use ($h): string {
  $attrs = '';
  foreach ($extra as $k => $v) $attrs .= " $k=\"{$h($v)}\"";
  $control = match ($tipo) {
    'textarea' => "<textarea name=$nombre rows=4$attrs>{$h($valor)}</textarea>",
    'checkbox' => "<input type=checkbox name=$nombre value=1" . ($valor ? ' checked' : '') . "$attrs>",
    default => "<input type=$tipo name=$nombre value=\"{$h($valor)}\"$attrs>",
  };
  return "<label class=campo><span>$etiqueta</span>$control" . ($ayuda ? "<small>$ayuda</small>" : '') . '</label>';
};
$lista = function (string $nombre, string $etiqueta, array $opciones, string $valor) use ($h): string {
  $ops = '';
  foreach ($opciones as $v => $t) $ops .= "<option value=\"{$h($v)}\"" . ($v === $valor ? ' selected' : '') . ">{$h($t)}</option>";
  return "<label class=campo><span>$etiqueta</span><select name=$nombre>$ops</select></label>";
};

// Mapa para marcar el punto (o tomarlo del GPS del móvil). Usa Leaflet de la web y las teselas cacheadas de este servidor.
$mapa = function (): string {
  $web = rtrim(config()['web'], '/');
  return <<<HTML
<div id=mapa style="height:320px;border:1px solid var(--rule);border-radius:4px;margin:8px 0"></div>
<p><button type=button id=gps>Usar mi ubicación</button> <span class=meta>Pulsa en el mapa para colocar el punto. Puedes arrastrarlo.</span></p>
<link rel=stylesheet href="$web/vendor/leaflet-1.9.4/leaflet.css"><script src="$web/vendor/leaflet-1.9.4/leaflet.js"></script>
<script>
(function(){
  const la=document.querySelector('[name=lat]'), lo=document.querySelector('[name=lon]');
  if(!window.L){ document.getElementById('mapa').outerHTML='<p class=meta>El mapa no ha cargado: escribe la latitud y la longitud a mano.</p>'; return; }
  const m=L.map('mapa').setView([28.3,-15.8],8);
  L.tileLayer('/teselas/osm/{z}/{x}/{y}.png',{maxZoom:19,attribution:'© OpenStreetMap'}).addTo(m);
  let p=null;
  const poner=(lat,lon,zoom)=>{ la.value=(+lat).toFixed(6); lo.value=(+lon).toFixed(6);
    if(p) p.setLatLng([lat,lon]); else { p=L.marker([lat,lon],{draggable:true}).addTo(m); p.on('dragend',e=>{const g=e.target.getLatLng(); poner(g.lat,g.lng);}); }
    if(zoom) m.setView([lat,lon],zoom); };
  if(la.value&&lo.value) poner(la.value,lo.value,16);
  m.on('click',e=>poner(e.latlng.lat,e.latlng.lng));
  [la,lo].forEach(i=>i.addEventListener('change',()=>{ if(la.value&&lo.value) poner(la.value,lo.value,16); }));
  document.getElementById('gps').onclick=()=>navigator.geolocation.getCurrentPosition(
    g=>poner(g.coords.latitude,g.coords.longitude,18), e=>alert('No se ha podido leer la ubicación: '+e.message),{enableHighAccuracy:true,timeout:20000});
})();
</script>
HTML;
};

// Listas de nodos (personas, quién pagó, relaciones): filas que se añaden y se quitan; el JSON va en un campo oculto.
// $campos: [clave, tipo (text|select|check), etiqueta, opciones o atributo list]. Las entidades usan una lista de sugerencias
// según la naturaleza elegida; si la entidad es nueva, se escribe y queda en la lista al guardar.
$nodos = function (string $nombre, string $titulo, string $ayuda, array $campos, string $valor, string $boton) use ($h): string {
  $def = htmlspecialchars(json_encode($campos, JSON_UNESCAPED_UNICODE), ENT_QUOTES);
  $filas = htmlspecialchars($valor ?: '[]', ENT_QUOTES);
  return "<fieldset class=nodos data-nombre=$nombre data-campos=\"$def\" data-filas=\"$filas\"><legend>{$h($titulo)}</legend>"
    . ($ayuda ? "<p class=meta>$ayuda</p>" : '') . "<div class=filas></div><button type=button class=mas>{$h($boton)}</button>"
    . "<input type=hidden name=$nombre></fieldset>";
};
$js_nodos = <<<'HTML'
<script>
document.querySelectorAll('fieldset.nodos').forEach(fs=>{
  const campos=JSON.parse(fs.dataset.campos), oculto=fs.querySelector('input[type=hidden]'), cont=fs.querySelector('.filas');
  const guardar=()=>{ oculto.value=JSON.stringify([...cont.children].map(f=>Object.fromEntries(campos.map(([k,t])=>{ const i=f.querySelector(`[data-k="${k}"]`); return [k,t==='check'?i.checked:i.value.trim()]; })))); };
  const lista=f=>{ const n=f.querySelector('[data-k=naturaleza]'), e=f.querySelector('[data-k=entidad]'); if(!n||!e) return;
    e.setAttribute('list','ent-'+n.value); e.placeholder=n.value==='particular'?'Nombre (solo para el archivo, no se publica)':'Elige de la lista o escribe una nueva'; };
  const fila=(d={})=>{ const f=document.createElement('div'); f.className='fila';
    campos.forEach(([k,t,et,op])=>{ let i;
      if(t==='select'){ i=document.createElement('select'); Object.entries(op).forEach(([v,n])=>i.add(new Option(n,v))); }
      else { i=document.createElement('input'); i.type=t==='check'?'checkbox':'text'; if(t!=='check') i.placeholder=et; if(op&&t==='text') i.setAttribute('list',op); }
      i.dataset.k=k; i.title=et; if(t==='check') i.checked=d[k]!==undefined?!!d[k]:true; else if(d[k]!==undefined) i.value=d[k];
      if(t==='check'){ const l=document.createElement('label'); l.className='chk'; l.append(i,' '+et); f.append(l); } else f.append(i); });
    const x=document.createElement('button'); x.type='button'; x.textContent='Quitar'; x.onclick=()=>{ f.remove(); guardar(); }; f.append(x);
    cont.append(f); lista(f); };
  JSON.parse(fs.dataset.filas||'[]').forEach(fila);
  fs.querySelector('.mas').onclick=()=>{ fila(); guardar(); };
  fs.addEventListener('input',guardar); fs.addEventListener('change',e=>{ if(e.target.dataset.k==='naturaleza') lista(e.target.closest('.fila')); guardar(); });
  guardar();
});
</script>
HTML;

$estilo_form = '<style>.nodos{border:1px solid var(--rule);border-radius:4px;margin:12px 0;padding:8px 12px}.nodos legend{font-weight:600;font-size:14px}
.fila{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin:6px 0}.fila input:not([type=checkbox]),.fila select{flex:1 1 140px;font:inherit;padding:5px 8px;border:1px solid var(--rule);background:transparent;color:inherit;border-radius:4px}.fila .chk{font-size:13px;white-space:nowrap}
.campo{display:block;margin:10px 0}.campo span{display:block;font-weight:600;font-size:14px}.campo small{display:block;color:var(--muted);font-size:13px}
.campo input:not([type=checkbox]),.campo textarea,.campo select{width:100%;box-sizing:border-box;font:inherit;padding:6px 10px;border:1px solid var(--rule);background:transparent;color:inherit;border-radius:4px}
.par{display:grid;grid-template-columns:1fr 1fr;gap:12px}.error{border:1px solid var(--no);color:var(--no);padding:8px 12px;border-radius:4px}.hecho{border:1px solid var(--ok);color:var(--ok);padding:8px 12px;border-radius:4px}
.foto-actual{max-width:320px;display:block;margin:6px 0}</style>';

// Aviso de publicación en canarias.africa tras guardar (se muestra una vez en la página siguiente).
$aviso_pub = function () use ($h): string {
  $t = $_SESSION['publicacion'] ?? '';
  unset($_SESSION['publicacion']);
  return $t ? "<p class=meta>{$h($t)}</p>" : '';
};
if ($sub === '/publicar' && $metodo === 'POST') {
  $_SESSION['publicacion'] = publicar_web(true);
  header('Location: /admin/catalogo', true, 303);
  exit;
}

// ---------------- Obras ----------------
if ($sub === '/catalogo') {
  $html = $aviso_pub() . '<p><a href=/admin/obra>+ Nueva obra</a> · <a href=/admin/rutas>Rutas</a> · <a href=/admin/artistas>Artistas</a></p>
    <form method=post action=/admin/publicar><input type=hidden name=csrf value=' . $csrf . '><button>Publicar ahora en canarias.africa</button>
    <span class=meta>Al guardar se publica solo; este botón fuerza una copia nueva.</span></form>
    <p class=meta>La fuente es este servidor (<a href=/datos/obras.geojson>/datos/obras.geojson</a>); canarias.africa sirve una copia en /datos/, que leen la web y uMap.</p>
    <table><tr><th>Obra</th><th>Municipio</th><th>Fase</th><th>Sensibilidad</th><th>Publicada</th><th>Versión</th></tr>';
  foreach ($db->query('SELECT * FROM obras ORDER BY publicada DESC, actualizado DESC') as $o) {
    $html .= "<tr><td><a href='/admin/obra?id={$h($o['uuid'])}'>{$h($o['titulo'] ?: 'Sin título')}</a></td><td>{$h($o['municipio'])}</td><td>{$h($o['fase'])}</td>
      <td>{$h($o['sensibilidad'])}</td><td>" . ($o['publicada'] ? 'sí' : 'no') . "</td><td><a href='/admin/obra/versiones?id={$h($o['uuid'])}'>v{$o['version']}</a></td></tr>";
  }
  pagina('Catálogo de obras', $html . '</table>');
}

if ($sub === '/obra') {
  $error = '';
  $id = (string) ($_REQUEST['id'] ?? '');
  if ($metodo === 'POST') {
    $d = $_POST;
    try {
      if ($url = $foto_formulario()) $d['foto'] = $url;
      // Lo que pidió quien la propuso es el mínimo: se puede proteger más, nunca menos.
      if (!empty($_POST['desde_envio'])) {
        $st = $db->prepare('SELECT datos FROM envios WHERE id = ?');
        $st->execute([(int) $_POST['desde_envio']]);
        $pedida = json_decode((string) $st->fetchColumn(), true)['sensibilidad'] ?? 'abierta';
        $nivel = array_flip(SENSIBILIDADES);
        if (isset($nivel[$pedida]) && ($nivel[$d['sensibilidad'] ?? ''] ?? -1) < $nivel[$pedida]) {
          throw new InvalidArgumentException("Quien la propuso pidió que fuera «{$pedida}»: se puede proteger más, no menos.");
        }
      }
      $id = obra_guardar($d, $id ?: null);
      if (!empty($_POST['desde_propuesta'])) $db->prepare("UPDATE obras_propuestas SET estado = 'aceptada' WHERE uuid = ?")->execute([$id]);
      if (!empty($_POST['desde_envio'])) $db->prepare("UPDATE envios SET estado = 'aprobada' WHERE id = ?")->execute([(int) $_POST['desde_envio']]);
      $_SESSION['publicacion'] = publicar_web();
      header('Location: /admin/obra?ok=1&id=' . urlencode($id), true, 303);
      exit;
    } catch (InvalidArgumentException $e) {
      $error = $e->getMessage();
      $o = $d;
    }
  }
  $o ??= ($id ? obra($id) : null) ?? ['titulo' => '', 'sensibilidad' => 'abierta', 'fase' => 'plenitud', 'publicada' => 0];
  // Rellenar con una obra propuesta al colocar una etiqueta, o con un envío del formulario «obra nueva».
  $origen = '';
  if (!$id && !empty($_GET['desde_propuesta'])) {
    $st = $db->prepare('SELECT * FROM obras_propuestas WHERE uuid = ?');
    $st->execute([(string) $_GET['desde_propuesta']]);
    if ($p = $st->fetch()) {
      $pd = json_decode($p['datos'], true);
      $o = ['titulo' => $pd['titulo'] ?? '', 'descripcion' => $pd['descripcion'] ?? '', 'municipio' => $pd['municipio'] ?? '',
        'sensibilidad' => in_array($pd['sensibilidad'] ?? '', SENSIBILIDADES, true) ? $pd['sensibilidad'] : 'abierta', 'fase' => 'plenitud', 'publicada' => 0];
      $st = $db->prepare('SELECT lat, lon FROM colocaciones WHERE obra = ? ORDER BY id DESC LIMIT 1');
      $st->execute([$p['uuid']]);
      if ($g = $st->fetch()) $o += $g;
      $id = $p['uuid'];
      $origen = "<input type=hidden name=desde_propuesta value=1><p class=meta>Desde la propuesta de una persona colaboradora. Se conserva su UUID, que ya usa la etiqueta.</p>";
    }
  } elseif (!$id && !empty($_GET['desde_envio'])) {
    $st = $db->prepare("SELECT * FROM envios WHERE id = ? AND formulario = 'obra_nueva'");
    $st->execute([(int) $_GET['desde_envio']]);
    if ($e = $st->fetch()) {
      $ed = json_decode($e['datos'], true);
      // Las personas que nombra quien propone no se publican hasta que se revise (visible = no).
      $agentes = array_map(fn($a) => ['visible' => false] + (array) $a, json_decode($ed['agentes'] ?? '[]', true) ?: []);
      $o = ['titulo' => $ed['titulo'] ?? $ed['nombre'] ?? '', 'descripcion' => $ed['descripcion'] ?? $ed['texto'] ?? '', 'municipio' => $ed['municipio'] ?? '',
        'lugar' => $ed['lugar'] ?? '', 'autoria' => $ed['autoria'] ?? '', 'lat' => $ed['lat'] ?? '', 'lon' => $ed['lon'] ?? '',
        'tipo' => $ed['tipo'] ?? '', 'fecha_obra' => $ed['fecha_obra'] ?? '', 'fecha_precision' => $ed['fecha_precision'] ?? '',
        'agentes' => json_encode($agentes, JSON_UNESCAPED_UNICODE), 'pagadores' => $ed['pagadores'] ?? '[]', 'relaciones' => $ed['relaciones'] ?? '[]',
        'fuentes' => $ed['fuentes'] ?? '', 'sensibilidad' => in_array($ed['sensibilidad'] ?? '', SENSIBILIDADES, true) ? $ed['sensibilidad'] : (!empty($ed['posible_sensible']) ? 'difusa' : 'abierta'),
        'isla' => isset(ISLAS_CODIGO[$ed['isla'] ?? '']) ? $ed['isla'] : '',
        'fase' => in_array($ed['fase'] ?? '', FASES, true) ? $ed['fase'] : 'plenitud', 'publicada' => 0];
      $origen = "<input type=hidden name=desde_envio value={$e['id']}><p class=meta>Desde un envío del formulario «obra nueva». Al guardar, el envío queda aprobado.</p>";
    }
  }
  $v = fn($k) => $o[$k] ?? '';
  // Otras obras (para las relaciones) y entidades ya usadas (sugerencias del campo «Entidad», por naturaleza).
  $obras_ops = ['' => 'Elige la obra…'];
  foreach ($db->query('SELECT uuid, titulo, municipio FROM obras ORDER BY titulo') as $x) if ($x['uuid'] !== $id) $obras_ops[$x['uuid']] = $x['titulo'] . ' · ' . $x['municipio'];
  $datalists = '';
  foreach (entidades_conocidas(false) + ['particular' => []] as $nat => $ents) {
    $datalists .= "<datalist id=ent-$nat>" . implode('', array_map(fn($e) => "<option value=\"{$h($e)}\">", $ents)) . '</datalist>';
  }
  $html = $estilo_form . ($error ? "<p class=error>{$h($error)}</p>" : '') . (!empty($_GET['ok']) ? '<p class=hecho>Guardada.</p>' . $aviso_pub() : '')
    . "<form method=post action=/admin/obra enctype=multipart/form-data><input type=hidden name=csrf value=$csrf><input type=hidden name=id value=\"{$h($id)}\">$origen"
    . ($id ? "<p class=meta>Id: <code>{$h($id)}</code>" . (isset($o['version']) ? " · versión {$o['version']} · <a href='/admin/obra/versiones?id={$h($id)}'>historial</a>" : '') . '</p>' : '')
    . $campo('titulo', 'Título', $v('titulo'), 'text', '', ['required' => 'required'])
    . '<div class=par>' . $lista('fase', 'Fase', $ETIQUETAS_FASE, (string) $v('fase')) . $lista('sensibilidad', 'Sensibilidad', $ETIQUETAS_SENS, (string) $v('sensibilidad')) . '</div>'
    . $lista('isla', 'Isla', ['' => 'La del punto (o Canarias si no hay punto)'] + ISLAS_CODIGO, (string) $v('isla'))
    . '<div class=par>' . $campo('municipio', 'Municipio', $v('municipio'), 'text', 'No se publica si la obra es reservada.') . $campo('lugar', 'Lugar', $v('lugar'), 'text', 'Calle, plaza o paraje. Solo se publica si la obra es abierta.') . '</div>'
    . '<div class=par>' . $campo('lat', 'Latitud', $v('lat'), 'text', '', ['inputmode' => 'decimal']) . $campo('lon', 'Longitud', $v('lon'), 'text', '', ['inputmode' => 'decimal']) . '</div>'
    . $mapa()
    . '<div class=par>' . $campo('tipo', 'Tipo', $v('tipo'), 'text', 'Mural, escultura, grafiti, instalación, grabado, acción, obra web…') . $campo('autoria', 'Autoría (texto libre, antiguo)', $v('autoria'), 'text', 'Mejor usa «Personas y colectivos».') . '</div>'
    . '<div class=par>' . $campo('fecha_obra', 'Fecha de la obra', $v('fecha_obra'), 'text', 'Año, década o época.') . $lista('fecha_precision', 'Precisión de la fecha', PRECISIONES_FECHA, (string) $v('fecha_precision')) . '</div>'
    . $campo('descripcion', 'Descripción', $v('descripcion'), 'textarea')
    . $nodos('agentes', 'Personas y colectivos', 'Quién la hizo, la encargó, la restauró o la cuida. «Visible» solo si ha aceptado figurar o es una figura pública; si no, se guarda aquí pero no se publica.',
        [['nombre', 'text', 'Nombre o colectivo', ''], ['rol', 'select', 'Rol', ROLES_AGENTE], ['certeza', 'select', 'Certeza', CERTEZAS], ['visible', 'check', 'Visible', '']], (string) $v('agentes'), '+ Añadir persona o colectivo')
    . $nodos('pagadores', 'Quién la pagó', 'Entidad pública o privada: elige de la lista (entidades de otras obras) o escribe una nueva. Un particular se guarda pero su nombre no se publica.',
        [['naturaleza', 'select', 'Naturaleza', NATURALEZAS], ['entidad', 'text', 'Entidad', 'ent-publica'], ['modalidad', 'select', 'Modalidad', MODALIDADES]], (string) $v('pagadores'), '+ Añadir pagador')
    . $nodos('relaciones', 'Relación con otras obras', 'Solo se publican las relaciones con obras publicadas.',
        [['modo', 'select', 'Relación', MODOS_RELACION], ['obra', 'select', 'Obra', $obras_ops], ['nota', 'text', 'Nota (opcional)', '']], (string) $v('relaciones'), '+ Añadir relación')
    . $campo('fuentes', 'Fuentes', $v('fuentes'), 'textarea', 'Cómo se sabe: testimonios, documentos, placas, prensa. Uno por línea. No se publica.')
    . $datalists
    . ($v('foto') ? "<img class=foto-actual src=\"{$h($v('foto'))}\" alt='Foto actual'>" : '')
    . $campo('foto_archivo', 'Sacar o subir una foto', '', 'file', 'En el móvil abre la cámara. Se guarda sin la ubicación del GPS y sustituye a la anterior.', ['accept' => 'image/*', 'capture' => 'environment'])
    . $campo('foto', 'O enlace a la foto', $v('foto'), 'url', 'https:// directo a la imagen, por ejemplo de Wikimedia Commons.')
    . $campo('foto_credito', 'Crédito de la foto', $v('foto_credito'), 'text', 'Autoría y licencia: «Foto: Ana Pérez · CC BY-SA 4.0».')
    . $campo('ficha', 'Enlace externo', $v('ficha'), 'text', 'Opcional: artículo, web del artista…')
    . $campo('publicada', 'Publicada en la web y en uMap', $v('publicada'), 'checkbox')
    . '<p><button class=aprobada>Guardar</button> <a href=/admin/catalogo>Volver al catálogo</a></p></form>' . $js_nodos;
  pagina($id ? 'Editar obra' : 'Nueva obra', $html);
}

if ($sub === '/obra/versiones') {
  $o = obra((string) ($_GET['id'] ?? '')) ?? error_publico('Obra no encontrada.', 404);
  $st = $db->prepare('SELECT * FROM obras_versiones WHERE obra = ? ORDER BY version DESC');
  $st->execute([$o['uuid']]);
  $html = "<p><a href='/admin/obra?id={$h($o['uuid'])}'>Volver a la obra</a> · versión actual: {$o['version']} ({$h($o['actualizado'])})</p>";
  $despues = $o;
  foreach ($st->fetchAll() as $ver) {
    $antes = json_decode($ver['datos'], true);
    $cambios = [];
    foreach (CAMPOS_OBRA as $k) if ((string) ($antes[$k] ?? '') !== (string) ($despues[$k] ?? '')) {
      $cambios[] = "<dt>{$h($k)}</dt><dd><del>{$h($antes[$k] ?? '')}</del> → {$h($despues[$k] ?? '')}</dd>";
    }
    $html .= "<article><strong>v{$ver['version']} → v" . ($ver['version'] + 1) . "</strong> <span class=meta>{$h($ver['fecha'])} · {$h($ver['autor'])}</span><dl>" . implode('', $cambios ?: ['<dd>Sin cambios en los campos.</dd>']) . '</dl></article>';
    $despues = $antes;
  }
  pagina('Historial: ' . $h($o['titulo']), $html);
}

// ---------------- Rutas ----------------
if ($sub === '/rutas') {
  $html = '<p><a href=/admin/ruta>+ Nueva ruta</a> · <a href=/admin/catalogo>Obras</a></p><p class=meta>Se publican en <a href=/datos/rutas.geojson>/datos/rutas.geojson</a>. Solo cuentan las paradas publicadas y con punto (nunca las reservadas).</p><table><tr><th>Ruta</th><th>Paradas</th><th>Publicada</th></tr>';
  foreach ($db->query('SELECT * FROM rutas ORDER BY nombre') as $r) {
    $n = count(array_filter(explode(',', $r['paradas'])));
    $html .= "<tr><td><a href='/admin/ruta?id={$h($r['id'])}'>{$h($r['nombre'])}</a></td><td>$n</td><td>" . ($r['publicada'] ? 'sí' : 'no') . '</td></tr>';
  }
  pagina('Rutas', $html . '</table>');
}

if ($sub === '/ruta') {
  $error = '';
  $id = (string) ($_REQUEST['id'] ?? '');
  if ($metodo === 'POST') {
    try {
      $id = ruta_guardar($_POST, $id ?: null);
      if (!empty($_POST['desde_envio'])) $db->prepare("UPDATE envios SET estado = 'aprobada' WHERE id = ?")->execute([(int) $_POST['desde_envio']]);
      $_SESSION['publicacion'] = publicar_web();
      header('Location: /admin/ruta?ok=1&id=' . urlencode($id), true, 303);
      exit;
    } catch (InvalidArgumentException $e) { $error = $e->getMessage(); $r = $_POST; }
  }
  if (!isset($r) && $id) { $st = $db->prepare('SELECT * FROM rutas WHERE id = ?'); $st->execute([$id]); $r = $st->fetch() ?: null; }
  $origen = '';
  if (!isset($r) && !$id && !empty($_GET['desde_envio'])) {
    $st = $db->prepare("SELECT * FROM envios WHERE id = ? AND formulario = 'ruta'");
    $st->execute([(int) $_GET['desde_envio']]);
    if ($e = $st->fetch()) {
      $ed = json_decode($e['datos'], true);
      $r = ['nombre' => $ed['titulo'] ?? '', 'descripcion' => $ed['tesis'] ?? '', 'curaduria' => $ed['curaduria'] ?? '',
        'paradas' => $ed['obras'] ?? '', 'notas' => $ed['notas'] ?? '{}', 'publicada' => 0];
      $origen = "<input type=hidden name=desde_envio value={$e['id']}><p class=meta>Desde una propuesta de ruta (contacto: {$h($ed['contacto'] ?? '')}). Al guardar, el envío queda aprobado.</p>";
    }
  }
  $r ??= ['nombre' => '', 'publicada' => 0];
  // Agregador de nodos: busca obras del catálogo y las añade en orden, cada una con su nota curatorial.
  $obras = [];
  foreach ($db->query("SELECT uuid, titulo, municipio, tipo, sensibilidad, publicada, lat, lon FROM obras ORDER BY municipio, titulo") as $o) {
    $obras[$o['uuid']] = ['t' => $o['titulo'], 'm' => $o['municipio'], 'tipo' => $o['tipo'], 's' => $o['sensibilidad'], 'p' => (int) $o['publicada'],
      'lat' => $o['lat'] === null ? null : (float) $o['lat'], 'lon' => $o['lon'] === null ? null : (float) $o['lon']];
  }
  $v = fn($k) => $r[$k] ?? '';
  $datos = htmlspecialchars(json_encode(['obras' => $obras, 'paradas' => array_values(array_filter(preg_split('/[\s,]+/', (string) $v('paradas')))),
    'notas' => json_decode((string) ($v('notas') ?: '{}'), true) ?: []], JSON_UNESCAPED_UNICODE), ENT_QUOTES);
  $html = $estilo_form . ($error ? "<p class=error>{$h($error)}</p>" : '') . (!empty($_GET['ok']) ? '<p class=hecho>Guardada.</p>' . $aviso_pub() : '')
    . "<form method=post action=/admin/ruta><input type=hidden name=csrf value=$csrf><input type=hidden name=id value=\"{$h($id)}\">$origen"
    . $campo('nombre', 'Nombre', $v('nombre'), 'text', '', ['required' => 'required'])
    . $campo('curaduria', 'Curaduría', $v('curaduria'), 'text', 'Quién propone la ruta.')
    . $campo('descripcion', 'Tesis y datos prácticos', $v('descripcion'), 'textarea', 'Idea de la ruta, distancia, duración, dificultad.')
    . "<fieldset class=nodos id=agregador data-datos=\"$datos\"><legend>Paradas (agregador de nodos)</legend>"
    . '<p class=meta>Busca obras del catálogo y añádelas en el orden del recorrido. Solo cuentan en la web las publicadas y con punto; las reservadas nunca.</p>'
    . '<input type=search id=busca placeholder="Busca por título, municipio o tipo" style="width:100%;box-sizing:border-box;font:inherit;padding:6px 10px;border:1px solid var(--rule);background:transparent;color:inherit;border-radius:4px">'
    . '<div id=resultados></div><ol id=lista></ol><p class=meta id=km></p><input type=hidden name=paradas><input type=hidden name=notas></fieldset>'
    . <<<'HTML'
<script>
(function(){
  const fs=document.getElementById('agregador'), D=JSON.parse(fs.dataset.datos), O=D.obras, par=D.paradas.filter(u=>O[u]), notas=D.notas||{};
  const busca=document.getElementById('busca'), res=document.getElementById('resultados'), ol=document.getElementById('lista');
  const esc=t=>String(t??'').replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
  const aviso=o=>!o.p?' (sin publicar)':o.s==='reservada'?' (reservada: no cuenta)':o.lat===null?' (sin punto)':o.s==='difusa'?' (difusa: zona)':'';
  const km=()=>{ let d=0; for(let i=1;i<par.length;i++){ const a=O[par[i-1]], b=O[par[i]]; if(a.lat===null||b.lat===null) continue; const r=Math.PI/180;
    d+=12742*Math.asin(Math.sqrt(Math.sin((b.lat-a.lat)*r/2)**2+Math.cos(a.lat*r)*Math.cos(b.lat*r)*Math.sin((b.lon-a.lon)*r/2)**2)); } return d; };
  const pinta=()=>{
    ol.innerHTML=par.map((u,i)=>`<li class=fila data-u="${u}"><span style="flex:1 1 200px"><b>${esc(O[u].t)}</b> <span class=meta>· ${esc(O[u].m)}${aviso(O[u])}</span></span>
      <input data-nota placeholder="Nota curatorial de esta parada" value="${esc(notas[u]||'')}">
      <button type=button data-a=sube ${i?'':'disabled'}>↑</button><button type=button data-a=baja ${i<par.length-1?'':'disabled'}>↓</button><button type=button data-a=quita>Quitar</button></li>`).join('');
    document.getElementById('km').textContent=par.length>1?`${par.length} paradas · ${km().toFixed(1).replace('.',',')} km en línea recta`:'Añade al menos dos paradas.';
    fs.querySelector('[name=paradas]').value=par.join(','); fs.querySelector('[name=notas]').value=JSON.stringify(Object.fromEntries(par.filter(u=>notas[u]).map(u=>[u,notas[u]])));
    buscar(); };
  const buscar=()=>{ const q=busca.value.trim().toLowerCase();
    const l=Object.entries(O).filter(([u,o])=>!par.includes(u)&&(!q||[o.t,o.m,o.tipo].join(' ').toLowerCase().includes(q))).slice(0,8);
    res.innerHTML=l.map(([u,o])=>`<div class=fila><button type=button data-mas="${u}">+ Añadir</button> ${esc(o.t)} <span class=meta>· ${esc(o.m)}${aviso(o)}</span></div>`).join('')||'<p class=meta>No hay más obras que coincidan.</p>'; };
  busca.addEventListener('input',buscar);
  res.addEventListener('click',e=>{ const u=e.target.dataset.mas; if(u){ par.push(u); pinta(); } });
  ol.addEventListener('click',e=>{ const a=e.target.dataset.a; if(!a) return; const u=e.target.closest('li').dataset.u, i=par.indexOf(u);
    if(a==='quita') par.splice(i,1); else { const j=a==='sube'?i-1:i+1; [par[i],par[j]]=[par[j],par[i]]; } pinta(); });
  ol.addEventListener('input',e=>{ if(e.target.dataset.nota!==undefined){ notas[e.target.closest('li').dataset.u]=e.target.value.trim();
    fs.querySelector('[name=notas]').value=JSON.stringify(Object.fromEntries(par.filter(u=>notas[u]).map(u=>[u,notas[u]]))); } });
  pinta();
})();
</script>
HTML
    . $campo('trazado', 'Trazado (opcional)', $v('trazado'), 'textarea', 'LineString en GeoJSON, por ejemplo dibujado en geojson.io. Vacío: línea recta entre paradas.')
    . $campo('publicada', 'Publicada', $v('publicada'), 'checkbox')
    . '<p><button class=aprobada>Guardar</button> <a href=/admin/rutas>Volver</a></p></form>';
  pagina($id ? 'Editar ruta' : 'Nueva ruta', $html);
}

// ---------------- Artistas ----------------
if ($sub === '/artistas') {
  $html = '<p><a href=/admin/artista>+ Nuevo perfil</a> · <a href=/admin/catalogo>Obras</a></p><p class=meta>Galería de artistas. Su tienda se alojará en canarias.africa (fase siguiente); mientras, el campo «Tienda» puede enlazar a la suya. Se publica en <a href=/datos/artistas.geojson>/datos/artistas.geojson</a>, solo con su consentimiento.</p><table><tr><th>Artista</th><th>Disciplina</th><th>Municipio</th><th>Publicada</th></tr>';
  foreach ($db->query('SELECT * FROM artistas ORDER BY nombre') as $a) {
    $html .= "<tr><td><a href='/admin/artista?id={$h($a['id'])}'>{$h($a['nombre'])}</a></td><td>{$h($a['disciplina'])}</td><td>{$h($a['municipio'])}</td><td>" . ($a['publicada'] ? 'sí' : 'no') . '</td></tr>';
  }
  pagina('Artistas', $html . '</table>');
}

if ($sub === '/artista') {
  $error = '';
  $id = (string) ($_REQUEST['id'] ?? '');
  if ($metodo === 'POST') {
    $d = $_POST;
    try {
      if ($url = $foto_formulario()) $d['foto'] = $url;
      $id = artista_guardar($d, $id ?: null);
      $_SESSION['publicacion'] = publicar_web();
      header('Location: /admin/artista?ok=1&id=' . urlencode($id), true, 303);
      exit;
    } catch (InvalidArgumentException $e) { $error = $e->getMessage(); $a = $d; }
  }
  if (!isset($a) && $id) { $st = $db->prepare('SELECT * FROM artistas WHERE id = ?'); $st->execute([$id]); $a = $st->fetch() ?: null; }
  $a ??= ['nombre' => '', 'publicada' => 0];
  $v = fn($k) => $a[$k] ?? '';
  $html = $estilo_form . ($error ? "<p class=error>{$h($error)}</p>" : '') . (!empty($_GET['ok']) ? '<p class=hecho>Guardado.</p>' . $aviso_pub() : '')
    . "<form method=post action=/admin/artista enctype=multipart/form-data><input type=hidden name=csrf value=$csrf><input type=hidden name=id value=\"{$h($id)}\">"
    . $campo('nombre', 'Nombre artístico', $v('nombre'), 'text', '', ['required' => 'required'])
    . '<div class=par>' . $campo('disciplina', 'Disciplina', $v('disciplina'), 'text', 'Pintura, muralismo, cerámica, fotografía…') . $campo('municipio', 'Municipio', $v('municipio')) . '</div>'
    . $campo('bio', 'Presentación', $v('bio'), 'textarea')
    . '<div class=par>' . $campo('web', 'Web', $v('web'), 'url') . $campo('tienda', 'Tienda en línea', $v('tienda'), 'url', 'Su tienda en canarias.africa cuando esté lista; mientras, la suya propia.') . '</div>'
    . '<div class=par>' . $campo('mecenazgo', 'Mecenazgo o encargos', $v('mecenazgo'), 'url') . $campo('redes', 'Redes', $v('redes'), 'text', 'Usuarios o enlaces, separados por comas.') . '</div>'
    . ($v('foto') ? "<img class=foto-actual src=\"{$h($v('foto'))}\" alt='Foto actual'>" : '')
    . $campo('foto_archivo', 'Foto (retrato u obra)', '', 'file', '', ['accept' => 'image/*'])
    . $campo('foto', 'O enlace a la foto', $v('foto'), 'url')
    . $campo('foto_credito', 'Crédito de la foto', $v('foto_credito'))
    . $campo('obras', 'Obras del archivo', $v('obras'), 'text', 'Ids de obras separados por comas.')
    . '<h2>Punto en el mapa (opcional)</h2><p class=meta>Solo para un espacio que la persona quiera mostrar: taller abierto, galería, tienda. Nunca su domicilio. Sin punto, sale solo el municipio.</p>'
    . $campo('espacio', 'Qué espacio es', $v('espacio'))
    . '<div class=par>' . $campo('lat', 'Latitud', $v('lat'), 'text', '', ['inputmode' => 'decimal']) . $campo('lon', 'Longitud', $v('lon'), 'text', '', ['inputmode' => 'decimal']) . '</div>'
    . $mapa()
    . $campo('consentimiento', 'Consentimiento', $v('consentimiento'), 'text', 'Obligatorio para publicar: fecha y cómo lo dio («2026-11-02, por correo»).')
    . $campo('publicada', 'Publicado', $v('publicada'), 'checkbox')
    . '<p><button class=aprobada>Guardar</button> <a href=/admin/artistas>Volver</a></p></form>';
  pagina($id ? 'Editar artista' : 'Nuevo perfil de artista', $html);
}
