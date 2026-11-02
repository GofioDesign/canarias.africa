<?php
// /admin: catálogo de obras, rutas y artistas. Lo que se guarda aquí es la fuente de /datos/*.geojson,
// que leen la web y las capas de uMap. Se incluye desde admin.php con $sub, $metodo, $db, $csrf y $h ya definidos.

// Fases de vida de una obra, con una pista breve de cuándo usar cada una.
$ETIQUETAS_FASE = ['gestacion' => 'Gestación: en proyecto o boceto', 'creacion' => 'Creación: se está haciendo', 'plenitud' => 'Plenitud: terminada y en buen estado',
  'desgaste' => 'Desgaste: sol, sal, humedad o roces', 'intervencion' => 'Intervención: restaurada, repintada o tapada en parte',
  'transformacion' => 'Transformación: otra obra encima o al lado la cambia', 'desaparicion' => 'Desaparición: borrada, retirada o destruida',
  'memoria' => 'Memoria: ya no existe; queda su registro'];
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

// Mapa para marcar el punto (o tomarlo del GPS del móvil). Leaflet y las teselas se sirven desde este mismo servidor.
// El recuadro lleva overflow:hidden propio para que nunca se salga aunque falle la hoja de estilos de Leaflet.
$mapa = function (): string {
  return <<<HTML
<div id=mapa style="height:320px;max-width:100%;overflow:hidden;position:relative;border:1px solid var(--rule);border-radius:4px;margin:8px 0;background:var(--rule)"></div>
<p><button type=button id=gps>Usar mi ubicación</button> <span class=meta>Pulsa en el mapa para colocar el punto. Puedes arrastrarlo.</span></p>
<link rel=stylesheet href="/vendor/leaflet-1.9.4/leaflet.css"><script src="/vendor/leaflet-1.9.4/leaflet.js"></script>
<style>.punto{width:18px;height:18px;margin:-9px 0 0 -9px;border-radius:50%;background:#007a3d;border:3px solid #fff;box-shadow:0 0 0 1px #0006;cursor:grab}</style>
<script>
(function(){
  const la=document.querySelector('[name=lat]'), lo=document.querySelector('[name=lon]');
  if(!window.L){ document.getElementById('mapa').outerHTML='<p class=meta>El mapa no ha cargado: escribe la latitud y la longitud a mano.</p>'; return; }
  const m=L.map('mapa').setView([28.3,-15.8],8);
  L.tileLayer('/teselas/osm/{z}/{x}/{y}.png',{maxZoom:19,attribution:'© OpenStreetMap'}).addTo(m);
  let p=null;
  const poner=(lat,lon,zoom)=>{ la.value=(+lat).toFixed(6); lo.value=(+lon).toFixed(6);
    if(p) p.setLatLng([lat,lon]); else { p=L.marker([lat,lon],{draggable:true,icon:L.divIcon({className:'punto',iconSize:null})}).addTo(m); p.on('dragend',e=>{const g=e.target.getLatLng(); poner(g.lat,g.lng);}); }
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
document.querySelectorAll('fieldset.nodos[data-campos]').forEach(fs=>{
  const campos=JSON.parse(fs.dataset.campos), oculto=fs.querySelector('input[type=hidden]'), cont=fs.querySelector('.filas');
  const guardar=()=>{ oculto.value=JSON.stringify([...cont.children].map(f=>Object.fromEntries(campos.map(([k,t])=>{ const i=f.querySelector(`[data-k="${k}"]`); return [k,t==='check'?i.checked:i.value.trim()]; })))); };
  const lista=f=>{ const n=f.querySelector('[data-k=naturaleza]'), e=f.querySelector('[data-k=entidad]'); if(!n||!e) return;
    e.setAttribute('list','ent-'+n.value); e.placeholder=n.value==='particular'?'Nombre (solo para el archivo, no se publica)':'Elige de la lista o escribe una nueva'; };
  const fila=(d={})=>{ const f=document.createElement('div'); f.className='fila';
    campos.forEach(([k,t,et,op])=>{ let i;
      if(t==='select'){ i=document.createElement('select'); Object.entries(op).forEach(([v,n])=>i.add(new Option(n,v))); }
      else { i=document.createElement('input'); i.type=t==='check'?'checkbox':'text'; if(t!=='check') i.placeholder=et; if(op&&t==='text') i.setAttribute('list',op); }
      i.dataset.k=k; i.title=et; if(t==='check') i.checked=d[k]!==undefined?!!d[k]:op!=='no'; else if(d[k]!==undefined) i.value=d[k];
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
.foto-actual{max-width:320px;display:block;margin:6px 0}
.seccion{border:1px solid var(--rule);border-radius:4px;margin:12px 0;padding:0 12px}.seccion>summary{cursor:pointer;font-weight:600;padding:10px 0}.seccion[open]>summary{border-bottom:1px solid var(--rule);margin-bottom:6px}
.guardar{position:sticky;bottom:0;background:var(--bg);border-top:1px solid var(--rule);padding:4px 0;z-index:500}.guardar .campo{margin:6px 0}</style>';

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
if ($sub === '/campo/subir' && $metodo === 'POST') campo_subir($foto_formulario);

if ($sub === '/catalogo') {
  $html = $aviso_pub() . '<p><a href=/admin/obra>+ Nueva obra</a> · <a href=/admin/soportes>Soportes</a> · <a href=/admin/funciones>Funciones</a> · <a href=/admin/eventos>Eventos</a> · <a href=/admin/rutas>Rutas</a> · <a href=/admin/artistas>Artistas</a></p>
    <form method=post action=/admin/publicar><input type=hidden name=csrf value=' . $csrf . '><button>Publicar ahora en canarias.africa</button>
    <span class=meta>Al guardar se publica solo; este botón fuerza una copia nueva.</span></form>
    <p class=meta>La fuente es este servidor (<a href=/datos/obras.geojson>/datos/obras.geojson</a>); canarias.africa sirve una copia en /datos/, que leen la web y uMap.</p>
    <table class=filtrable><tr><th>Obra</th><th>Municipio</th><th>Fase</th><th>Sensibilidad</th><th>Publicada</th><th>Versión</th></tr>';
  foreach ($db->query('SELECT * FROM obras ORDER BY publicada DESC, actualizado DESC') as $o) {
    $html .= "<tr><td><a href='/admin/obra?id={$h($o['uuid'])}'>{$h($o['titulo'] ?: 'Sin título')}</a></td><td>{$h($o['municipio'])}</td><td>{$h($o['fase'])}</td>
      <td>{$h($o['sensibilidad'])}</td><td>" . ($o['publicada'] ? 'sí' : 'no') . "</td><td><a href='/admin/obra/versiones?id={$h($o['uuid'])}'>v{$o['version']}</a></td></tr>";
  }
  pagina('Catálogo de obras', tabla_o_vacio($html, 'El archivo está vacío. <a class=boton href=/admin/obra>+ Añadir la primera obra</a><br><span class=meta>Desde el móvil, junto a la obra: marca el punto con «Usar mi ubicación» y saca la foto en el mismo formulario.</span>'));
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
      if (!empty($_POST['desde_envio'])) aprobar_envio((int) $_POST['desde_envio'], $id);
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
        'tipo' => $ed['tipo'] ?? '', 'disciplina' => isset(DISCIPLINAS[$ed['disciplina'] ?? '']) ? $ed['disciplina'] : '',
        'permanencia' => isset(PERMANENCIAS[$ed['permanencia'] ?? '']) ? $ed['permanencia'] : '', 'fecha_obra' => $ed['fecha_obra'] ?? '', 'fecha_precision' => $ed['fecha_precision'] ?? '',
        'agentes' => json_encode($agentes, JSON_UNESCAPED_UNICODE), 'pagadores' => $ed['pagadores'] ?? '[]', 'relaciones' => $ed['relaciones'] ?? '[]',
        'fuentes' => $ed['fuentes'] ?? '', 'sensibilidad' => in_array($ed['sensibilidad'] ?? '', SENSIBILIDADES, true) ? $ed['sensibilidad'] : (!empty($ed['posible_sensible']) ? 'difusa' : 'abierta'),
        'isla' => isset(ISLAS_CODIGO[$ed['isla'] ?? '']) ? $ed['isla'] : '',
        'fase' => in_array($ed['fase'] ?? '', FASES, true) ? $ed['fase'] : 'plenitud', 'publicada' => 0];
      $origen = "<input type=hidden name=desde_envio value={$e['id']}><p class=meta>Desde un envío del formulario «obra nueva». Al guardar, el envío queda aprobado.</p>";
    }
  }
  // Completar con lo que aporta alguien desde la ficha pública: solo se rellenan los campos vacíos.
  // Las personas que nombra no se publican hasta revisarlas (visible = no).
  if ($id && empty($error) && !empty($_GET['desde_completar'])) {
    $st = $db->prepare("SELECT * FROM envios WHERE id = ? AND formulario = 'completar' AND obra = ?");
    $st->execute([(int) $_GET['desde_completar'], $id]);
    if ($e = $st->fetch()) {
      $ed = json_decode($e['datos'], true);
      $hechos = [];
      foreach (['fecha_obra', 'fecha_precision', 'disciplina', 'permanencia', 'tipo', 'descripcion', 'lugar'] as $k) {
        if (trim((string) ($ed[$k] ?? '')) !== '' && trim((string) ($o[$k] ?? '')) === '') { $o[$k] = trim($ed[$k]); $hechos[] = $k; }
      }
      foreach (['agentes', 'pagadores'] as $k) {
        $nuevos = json_decode($ed[$k] ?? '[]', true) ?: [];
        if ($k === 'agentes') $nuevos = array_map(fn($a) => ['visible' => false] + (array) $a, $nuevos);
        if ($nuevos) { $o[$k] = json_encode(array_merge(json_decode($o[$k] ?? '[]', true) ?: [], $nuevos), JSON_UNESCAPED_UNICODE); $hechos[] = $k; }
      }
      $fotos = json_decode($e['fotos'], true) ?: [];
      if ($fotos && trim((string) ($o['foto'] ?? '')) === '') {
        $o['foto'] = rtrim(config()['api'], '/') . '/datos/fotos/' . $fotos[0];
        $o['foto_credito'] = 'Foto: ' . (($ed['alias'] ?? '') ?: 'colaboración') . ($ed['licencia'] ?? '' ? ' · ' . $ed['licencia'] : '');
        $hechos[] = 'foto';
      }
      $o['desconocidos'] = implode(',', array_diff(explode(',', $o['desconocidos'] ?? ''), $hechos));
      $nota = trim((string) ($ed['texto'] ?? $ed['fuente'] ?? ''));
      $origen = "<input type=hidden name=desde_envio value={$e['id']}><p class=hecho>Aportación del público" . (($ed['alias'] ?? '') ? " ({$h($ed['alias'])})" : '')
        . ': ' . ($hechos ? 'rellenados ' . $h(implode(', ', array_map(fn($k) => CAMPOS_DESCONOCIBLES[$k] ?? $k, $hechos))) : 'no rellena ningún campo vacío')
        . '. Revisa y guarda; el envío queda aprobado.' . ($nota ? "<br>Cómo lo sabe: {$h($nota)}" : '') . '</p>';
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
    . '<details class=seccion open><summary>Lo esencial: qué es, dónde está y foto</summary>'
    . $campo('titulo', 'Título', $v('titulo'), 'text', '', ['required' => 'required'])
    . $campo('foto_archivo', 'Sacar o subir una foto', '', 'file', 'En el móvil abre la cámara. Se guarda sin la ubicación del GPS y sustituye a la anterior.', ['accept' => 'image/*', 'capture' => 'environment'])
    . ($v('foto') ? "<img class=foto-actual src=\"{$h($v('foto'))}\" alt='Foto actual'>" : '')
    . '<div class=par>' . $campo('municipio', 'Municipio', $v('municipio'), 'text', 'No se publica si la obra es reservada.') . $campo('lugar', 'Lugar', $v('lugar'), 'text', 'Calle, plaza o paraje. Solo se publica si la obra es abierta.') . '</div>'
    . $lista('isla', 'Isla', ['' => 'La del punto (o Canarias si no hay punto)'] + ISLAS_CODIGO, (string) $v('isla'))
    . '<div class=par>' . $campo('lat', 'Latitud', $v('lat'), 'number', 'Entre 27,4 y 29,6 (norte). Ej.: 28.4682', ['step' => 'any', 'min' => '27.4', 'max' => '29.6', 'inputmode' => 'decimal']) . $campo('lon', 'Longitud', $v('lon'), 'number', 'Entre −18,4 y −13,2 (oeste, en negativo). Ej.: -16.2546', ['step' => 'any', 'min' => '-18.4', 'max' => '-13.2', 'inputmode' => 'decimal']) . '</div>'
    . $mapa()
    . '<div class=par>' . $lista('fase', 'Fase', $ETIQUETAS_FASE, (string) $v('fase')) . $lista('sensibilidad', 'Sensibilidad', $ETIQUETAS_SENS, (string) $v('sensibilidad')) . '</div>'
    . '<p class=meta>Sensibilidad: <b>abierta</b> si está en un espacio público y no corre peligro; <b>difusa</b> si conviene no dar el punto exacto (terreno privado, obra frágil); <b>reservada</b> para patrimonio delicado, como los grabados aborígenes: no se publica el municipio y no lleva QR.</p>'
    . '</details>'
    . '<details class=seccion' . ($id ? ' open' : '') . '><summary>Qué es y cuándo se hizo</summary>'
    . $lista('ambito', 'Ámbito', AMBITOS, (string) $v('ambito'))
    . '<p class=meta>Una obra <b>de referencia</b> es de fuera (la novela que adapta un montaje, el disco preferido de un artista): no lleva lugar ni va en el mapa; se publica en <a href=/datos/referencias.geojson>/datos/referencias.geojson</a>.</p>'
    . '<div class=par>' . $lista('disciplina', 'Disciplina', DISCIPLINAS, (string) $v('disciplina')) . $lista('permanencia', 'Permanencia', PERMANENCIAS, (string) $v('permanencia')) . '</div>'
    . $campo('tipo', 'Tipo', $v('tipo'), 'text', 'Mural, grafiti, grabado, canción, disco, montaje teatral, coreografía, isa, romería, luchada, novela, película, obra web…')
    // El campo de autoría en texto libre es el antiguo: solo aparece si la obra ya lo tiene relleno.
    . ($v('autoria') !== '' ? $campo('autoria', 'Autoría (texto antiguo)', $v('autoria'), 'text', 'Pásalo a «Personas y colectivos» y vacía este campo.') : '')
    . '<div class=par>' . $campo('fecha_obra', 'Fecha de la obra', $v('fecha_obra'), 'text', 'Año, década o época.') . $lista('fecha_precision', 'Precisión de la fecha', PRECISIONES_FECHA, (string) $v('fecha_precision')) . '</div>'
    . $campo('descripcion', 'Descripción', $v('descripcion'), 'textarea')
    . '</details>'
    . '<details class=seccion' . ($id ? ' open' : '') . '><summary>Quién la hizo y quién la pagó</summary>'
    . $nodos('agentes', 'Personas y colectivos', 'Quién la hizo, la encargó, la restauró o la cuida. «Visible» solo si ha aceptado figurar o es una figura pública; si no, se guarda aquí pero no se publica.',
        [['nombre', 'text', 'Nombre o colectivo', ''], ['rol', 'select', 'Rol', ROLES_AGENTE], ['certeza', 'select', 'Certeza', CERTEZAS], ['visible', 'check', 'Visible', '']], (string) $v('agentes'), '+ Añadir persona o colectivo')
    . $nodos('pagadores', 'Quién la pagó', 'Entidad pública o privada: elige de la lista (entidades de otras obras) o escribe una nueva. Un particular se guarda pero su nombre no se publica.',
        [['naturaleza', 'select', 'Naturaleza', NATURALEZAS], ['entidad', 'text', 'Entidad', 'ent-publica'], ['modalidad', 'select', 'Modalidad', MODALIDADES]], (string) $v('pagadores'), '+ Añadir pagador')
    . $datalists
    . '</details>'
    . '<details class=seccion' . ($id ? ' open' : '') . '><summary>Relaciones con otras obras y soportes</summary>'
    . $nodos('relaciones', 'Relación con otras obras', 'Solo se publican las relaciones con obras publicadas.',
        [['modo', 'select', 'Relación', MODOS_RELACION], ['obra', 'select', 'Obra', $obras_ops], ['nota', 'text', 'Nota (opcional)', '']], (string) $v('relaciones'), '+ Añadir relación')
    . ($id ? '<h3>Soportes</h3>' . (implode('', array_map(fn($x) => "<p><a href='/admin/soporte?id={$h($x['soporte'])}'>{$h($x['nombre'] ?: 'Soporte')}</a> <span class=meta>" . ($x['tipo'] ? '· ' . $h(TIPOS_SOPORTE[$x['tipo']] ?? '') : '') . "" . ($x['desde'] ? " · desde {$h($x['desde'])}" : '') . ($x['hasta'] ? " · hasta {$h($x['hasta'])}" : ' · ahora') . '</span></p>',
        (function () use ($db, $id) { $st = $db->prepare('SELECT os.*, s.nombre, s.tipo FROM obra_soporte os JOIN soportes s ON s.uuid = os.soporte WHERE os.obra = ? ORDER BY os.hasta != \'\', os.id DESC'); $st->execute([$id]); return $st->fetchAll(); })()))
        ?: '<p class=meta>Sin soporte. Sin punto propio, la obra toma el de su soporte actual.</p>') . "<p><a href='/admin/soporte?obra={$h($id)}'>+ Nuevo soporte para esta obra</a> <span class=meta>(o añádela a uno que ya existe desde <a href=/admin/soportes>Soportes</a>)</span></p>"
      . '<h3>Funciones</h3>' . (implode('', array_map(fn($f) => "<p><a href='/admin/funcion?id={$h($f['uuid'])}'>{$h($f['inicio'])} · {$h($f['titulo'] ?: ($f['lugar'] ?: $f['municipio']) ?: 'Función')}</a> <span class=meta>· {$h(ESTADOS_FUNCION[$f['estado']] ?? '')}" . ($f['publicada'] ? '' : ' · sin publicar') . '</span></p>', funciones_de_obra($id)))
        ?: '<p class=meta>Sin funciones. Una obra de repertorio o efímera (concierto, montaje, romería, acción) tiene una por cada vez que ocurrió.</p>') . "<p><a href='/admin/funcion?obra={$h($id)}'>+ Nueva función de esta obra</a></p>" : '')
    . '</details>'
    . '<details class=seccion><summary>Lo que no se sabe y las fuentes</summary>'
    . '<fieldset class=nodos><legend>Datos desconocidos</legend><p class=meta>Marca lo que no se sabe. La ficha pública lo muestra como desconocido e invita a completarlo a quien la mira o escanea su QR. Al rellenar un campo, deja de ser desconocido.</p>'
      . implode(' ', array_map(fn($k, $t) => "<label class=chk style='margin-right:12px'><input type=checkbox name='desconocidos[]' value=$k" . (in_array($k, explode(',', (string) $v('desconocidos')), true) ? ' checked' : '') . "> {$h($t)}</label>",
        array_keys(CAMPOS_DESCONOCIBLES), CAMPOS_DESCONOCIBLES)) . '</fieldset>'
    . $campo('fuentes', 'Fuentes', $v('fuentes'), 'textarea', 'Cómo se sabe: testimonios, documentos, placas, prensa. Uno por línea. No se publica.')
    . '</details>'
    . '<details class=seccion><summary>Foto por enlace, crédito y enlace externo</summary>'
    . $campo('foto', 'O enlace a la foto', $v('foto'), 'url', 'https:// directo a la imagen, por ejemplo de Wikimedia Commons.')
    . $campo('foto_credito', 'Crédito de la foto', $v('foto_credito'), 'text', 'Autoría y licencia: «Foto: Ana Pérez · CC BY-SA 4.0».')
    . $campo('ficha', 'Enlace externo', $v('ficha'), 'text', 'Opcional: artículo, web del artista…')
    . '</details>'
    . '<div class=guardar>'
    . $campo('publicada', 'Publicada en la web y en uMap', $v('publicada'), 'checkbox')
    . '<p><button class=aprobada>Guardar</button> <a href=/admin/catalogo>Volver al catálogo</a></p></div></form>' . $js_nodos;
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
  $html = '<p><a href=/admin/ruta>+ Nueva ruta</a> · <a href=/admin/catalogo>Obras</a></p><p class=meta>Se publican en <a href=/datos/rutas.geojson>/datos/rutas.geojson</a>. Solo cuentan las paradas publicadas y con punto (nunca las reservadas).</p><table class=filtrable><tr><th>Ruta</th><th>Paradas</th><th>Publicada</th></tr>';
  foreach ($db->query('SELECT * FROM rutas ORDER BY nombre') as $r) {
    $n = count(array_filter(explode(',', $r['paradas'])));
    $html .= "<tr><td><a href='/admin/ruta?id={$h($r['id'])}'>{$h($r['nombre'])}</a></td><td>$n</td><td>" . ($r['publicada'] ? 'sí' : 'no') . '</td></tr>';
  }
  pagina('Rutas', tabla_o_vacio($html, 'No hay rutas todavía. <a class=boton href=/admin/ruta>+ Crear la primera ruta</a><br><span class=meta>Una ruta une obras ya catalogadas: añade antes algunas obras.</span>'));
}

if ($sub === '/ruta') {
  $error = '';
  $id = (string) ($_REQUEST['id'] ?? '');
  if ($metodo === 'POST') {
    try {
      $id = ruta_guardar($_POST, $id ?: null);
      if (!empty($_POST['desde_envio'])) aprobar_envio((int) $_POST['desde_envio']);
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
  $html = '<p><a href=/admin/artista>+ Nuevo perfil</a> · <a href=/admin/catalogo>Obras</a></p><p class=meta>Galería de artistas. Su tienda se alojará en canarias.africa (fase siguiente); mientras, el campo «Tienda» puede enlazar a la suya. Se publica en <a href=/datos/artistas.geojson>/datos/artistas.geojson</a>, solo con su consentimiento.</p><table class=filtrable><tr><th>Artista</th><th>Disciplina</th><th>Municipio</th><th>Publicada</th></tr>';
  foreach ($db->query('SELECT * FROM artistas ORDER BY nombre') as $a) {
    $html .= "<tr><td><a href='/admin/artista?id={$h($a['id'])}'>{$h($a['nombre'])}</a></td><td>{$h($a['disciplina'])}</td><td>{$h($a['municipio'])}</td><td>" . ($a['publicada'] ? 'sí' : 'no') . '</td></tr>';
  }
  pagina('Artistas', tabla_o_vacio($html, 'No hay perfiles de artistas. <a class=boton href=/admin/artista>+ Crear el primer perfil</a><br><span class=meta>Solo se publica con su consentimiento anotado.</span>'));
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
    . $nodos('preferidas', 'Obras preferidas e influencias', 'Las que elige: un disco, un libro, una película, un mural… Elige la obra si ya tiene ficha (también de referencia); si no, escribe el título. Se publican con su perfil.',
        [['obra', 'select', 'Obra', ['' => 'Sin ficha (escribe el título)'] + (function () use ($db) { $r = []; foreach ($db->query("SELECT uuid, titulo, ambito FROM obras ORDER BY titulo") as $x) $r[$x['uuid']] = $x['titulo'] . ($x['ambito'] === 'referencia' ? ' (referencia)' : ''); return $r; })()],
         ['titulo', 'text', 'Título (si no tiene ficha)', ''], ['tipo', 'select', 'Tipo', TIPOS_PREFERIDA], ['nota', 'text', 'Por qué (opcional)', '']], (string) ($v('preferidas') ?: '[]'), '+ Añadir obra')
    . $nodos('referentes', 'Referentes', 'Personas que le influyen. «Público» solo si es una figura pública o ha aceptado figurar; si no, se guarda pero no se publica.',
        [['nombre', 'text', 'Nombre', ''], ['enlace', 'text', 'Enlace https:// (opcional)', ''], ['nota', 'text', 'Nota (opcional)', ''], ['publico', 'check', 'Público', 'no']], (string) ($v('referentes') ?: '[]'), '+ Añadir referente')
    . '<h2>Punto en el mapa (opcional)</h2><p class=meta>Solo para un espacio que la persona quiera mostrar: taller abierto, galería, tienda. Nunca su domicilio. Sin punto, sale solo el municipio.</p>'
    . $campo('espacio', 'Qué espacio es', $v('espacio'))
    . '<div class=par>' . $campo('lat', 'Latitud', $v('lat'), 'number', 'Entre 27,4 y 29,6 (norte). Ej.: 28.4682', ['step' => 'any', 'min' => '27.4', 'max' => '29.6', 'inputmode' => 'decimal']) . $campo('lon', 'Longitud', $v('lon'), 'number', 'Entre −18,4 y −13,2 (oeste, en negativo). Ej.: -16.2546', ['step' => 'any', 'min' => '-18.4', 'max' => '-13.2', 'inputmode' => 'decimal']) . '</div>'
    . $mapa()
    . $campo('consentimiento', 'Consentimiento', $v('consentimiento'), 'text', 'Obligatorio para publicar: fecha y cómo lo dio («2026-11-02, por correo»).')
    . $campo('publicada', 'Publicado', $v('publicada'), 'checkbox')
    . '<p><button class=aprobada>Guardar</button> <a href=/admin/artistas>Volver</a></p></form>' . $js_nodos;
  pagina($id ? 'Editar artista' : 'Nuevo perfil de artista', $html);
}

// ---------------- Soportes ----------------
// Lo que identifica cada etiqueta QR: una pared, una roca, un banco… o una web. Lleva sus obras con «desde» y «hasta».
// PDF de azulejos 15 × 15 (QR al UUID del soporte), del derecho y en espejo para el transfer. Se genera en el navegador.
if ($sub === '/azulejos.js') { header('Content-Type: application/javascript; charset=utf-8'); readfile(PRIVADO . '/azulejos.js'); exit; }
if ($sub === '/azulejos') {
  $marcados = array_flip(array_map('strtolower', (array) ($_GET['id'] ?? [])));
  $filas = '';
  foreach ($db->query('SELECT * FROM soportes ORDER BY actualizado DESC') as $s) {
    $res = sensibilidad_soporte($s) === 'reservada';
    $filas .= "<tr><td><input type=checkbox name=u value='{$h($s['uuid'])}'" . (isset($marcados[$s['uuid']]) ? ' checked' : '') . ($res ? ' disabled' : '') . "></td>"
      . "<td><a href='/admin/soporte?id={$h($s['uuid'])}'>{$h($s['nombre'] ?: 'Sin nombre')}</a>" . ($res ? ' <span class=meta>(reservado: sin QR)</span>' : '') . "</td><td>{$h($s['municipio'])}</td><td><code>" . strtoupper($h($s['uuid'])) . '</code></td></tr>';
  }
  $html = '<p class=meta>Marca los soportes y genera el PDF. Por cada uno salen dos páginas A4: <strong>en espejo</strong> para el transfer de tóner boca abajo y <strong>al derecho</strong> para probar la lectura en papel o para calcomanía al agua. El QR lleva <code>HTTPS://CANARIAS.AFRICA/&lt;UUID&gt;</code> en mayúsculas (sale más pequeño y se lee de más lejos). Imprime al 100 %, sin «ajustar a página»: la barra de control mide 100 mm.</p>'
    . ($filas ? "<p><button type=button id=todos>Marcar todos</button> <button type=button id=pdf class=aprobada>Generar PDF</button> <span id=res class=meta></span></p>
      <table class=filtrable><tr><th></th><th>Soporte</th><th>Municipio</th><th>UUID</th></tr>$filas</table>
      <script src=/vendor/qrcode-generator-1.4.4/qrcode.js></script><script src=/admin/azulejos.js></script>
      <script>
      const cajas=()=>[...document.querySelectorAll('input[name=u]:not(:disabled)')];
      document.getElementById('todos').onclick=()=>cajas().forEach(c=>c.checked=!c.closest('tr').hidden);
      document.getElementById('pdf').onclick=()=>{ const u=cajas().filter(c=>c.checked).map(c=>c.value), r=document.getElementById('res');
        if(!u.length){ r.textContent='Marca al menos un soporte.'; return; }
        const a=document.createElement('a'); a.href=URL.createObjectURL(pdfAzulejos(u)); a.download='azulejos-'+new Date().toISOString().slice(0,10)+'.pdf';
        a.textContent='Descargar de nuevo'; r.textContent=u.length+(u.length>1?' azulejos, ':' azulejo, ')+2*u.length+' páginas. '; r.append(a); a.click(); };
      </script>"
      : '<div class=vacio>No hay soportes todavía. <a class=boton href=/admin/soporte>+ Crear el primer soporte</a></div>');
  pagina('Azulejos para imprimir', $html);
}

if ($sub === '/soportes') {
  $html = (!empty($_GET['eliminado']) ? '<p class=hecho>Soporte «' . $h($_GET['eliminado']) . '» eliminado.</p>' : '') . $aviso_pub() . '<p><a href=/admin/soporte>+ Nuevo soporte</a> · <a href=/admin/azulejos>Imprimir azulejos (PDF)</a> · <a href=/admin/catalogo>Obras</a></p>
    <p class=meta>La etiqueta QR identifica el soporte, no una obra: en un mismo muro puede haber varias obras, o unas encima de otras con el tiempo. Se publica en <a href=/datos/soportes.geojson>/datos/soportes.geojson</a> (los soportes con punto y alguna obra publicada).</p>
    <table class=filtrable><tr><th>Soporte</th><th>Tipo</th><th>Municipio</th><th>Obras ahora</th><th>Etiquetas</th></tr>';
  foreach ($db->query('SELECT * FROM soportes ORDER BY actualizado DESC') as $s) {
    $ob = obras_de_soporte($s['uuid']);
    $ahora = count(array_filter($ob, fn($o) => $o['hasta'] === ''));
    $st = $db->prepare('SELECT COUNT(*) FROM qr_codigos WHERE soporte = ?');
    $st->execute([$s['uuid']]);
    $html .= "<tr><td><a href='/admin/soporte?id={$h($s['uuid'])}'>{$h($s['nombre'] ?: 'Sin nombre')}</a></td><td>{$h($s['tipo'] ? TIPOS_SOPORTE[$s['tipo']] ?? '' : '')}</td><td>{$h($s['municipio'])}</td><td>$ahora de " . count($ob) . "</td><td>{$st->fetchColumn()}</td></tr>";
  }
  pagina('Soportes', tabla_o_vacio($html, 'No hay soportes. <a class=boton href=/admin/soporte>+ Crear el primer soporte</a><br><span class=meta>También se crean solos al colocar una etiqueta con la app, o desde la ficha de una obra.</span>'));
}

if ($sub === '/soporte') {
  $error = '';
  $id = (string) ($_REQUEST['id'] ?? '');
  // Retirar una etiqueta del soporte (se quitó de la pared, o era de prueba): queda en su historial como «retirado».
  if ($metodo === 'POST' && !empty($_POST['retirar'])) {
    $st = $db->prepare('SELECT codigo FROM qr_codigos WHERE codigo = ? AND soporte = ?');
    $st->execute([(string) $_POST['retirar'], $id]);
    if ($cod = $st->fetchColumn()) { cambiar_estado($cod, 'retirado', null, ['motivo' => 'retirada desde /admin']); $_SESSION['publicacion'] = publicar_web(); }
    header('Location: /admin/soporte?id=' . urlencode($id), true, 303);
    exit;
  }
  if ($metodo === 'POST' && !empty($_POST['eliminar'])) {
    try {
      if (empty($_POST['confirmo'])) throw new InvalidArgumentException('Marca la casilla para confirmar que quieres eliminarlo.');
      $nombre = soporte_eliminar($id);
      $_SESSION['publicacion'] = publicar_web();
      header('Location: /admin/soportes?eliminado=' . urlencode($nombre), true, 303);
      exit;
    } catch (InvalidArgumentException $e) { $error = $e->getMessage(); }
  } elseif ($metodo === 'POST') {
    $d = $_POST;
    try {
      $id = soporte_guardar($d, $id ?: null);
      if ($codigo = trim((string) ($d['etiqueta_nueva'] ?? ''))) qr_vincular_soporte($codigo, soporte($id));
      $_SESSION['publicacion'] = publicar_web();
      header('Location: /admin/soporte?ok=1&id=' . urlencode($id), true, 303);
      exit;
    } catch (InvalidArgumentException $e) { $error = $e->getMessage(); $s = $d; }
  }
  if (!isset($s) && $id) $s = soporte($id);
  // Desde una obra: «+ Nuevo soporte para esta obra» ya la trae en la lista.
  if (!isset($s) && !$id && !empty($_GET['obra']) && ($ob = obra((string) $_GET['obra']))) {
    $s = ['tipo' => '', 'obras' => json_encode([['obra' => $ob['uuid'], 'desde' => $ob['fecha_obra'], 'hasta' => '', 'nota' => '']], JSON_UNESCAPED_UNICODE),
      'lat' => $ob['lat'], 'lon' => $ob['lon'], 'municipio' => $ob['municipio'], 'lugar' => $ob['lugar'], 'nombre' => 'Soporte de «' . $ob['titulo'] . '»', 'sensibilidad' => $ob['sensibilidad']];
  }
  $s ??= ['nombre' => '', 'tipo' => '', 'sensibilidad' => 'abierta'];
  if (!isset($s['obras'])) {
    $s['obras'] = json_encode(array_map(fn($o) => ['obra' => $o['obra'], 'desde' => $o['desde'], 'hasta' => $o['hasta'], 'nota' => $o['nota']],
      $id ? array_reverse(obras_de_soporte($id)) : []), JSON_UNESCAPED_UNICODE);
  }
  $v = fn($k) => $s[$k] ?? '';
  $obras_ops = ['' => 'Elige la obra…'];
  foreach ($db->query('SELECT uuid, titulo, municipio FROM obras ORDER BY titulo') as $x) $obras_ops[$x['uuid']] = $x['titulo'] . ' · ' . $x['municipio'];
  $etiquetas = '';
  if ($id) {
    $st = $db->prepare('SELECT codigo, estado, ultima_verificacion FROM qr_codigos WHERE soporte = ? ORDER BY creado');
    $st->execute([$id]);
    foreach ($st->fetchAll() as $q) $etiquetas .= "<li><code>{$h($q['codigo'])}</code> · {$h($q['estado'])}" . ($q['ultima_verificacion'] ? " · verificada {$h($q['ultima_verificacion'])}" : '')
      . (in_array($q['estado'], ESTADOS_EN_SITIO, true) ? " <button form=retirar name=retirar value=\"{$h($q['codigo'])}\" class=enlace onclick=\"return confirm('¿Retirar la etiqueta {$h($q['codigo'])}? Dejará de contar como colocada.')\">Retirar</button>" : '') . '</li>';
  }
  $html = $estilo_form . ($error ? "<p class=error>{$h($error)}</p>" : '') . (!empty($_GET['ok']) ? '<p class=hecho>Guardado.</p>' . $aviso_pub() : '')
    . "<form method=post action=/admin/soporte><input type=hidden name=csrf value=$csrf><input type=hidden name=id value=\"{$h($id)}\">"
    . ($id ? "<p class=meta>Id: <code>{$h($id)}</code></p><p class=meta>QR del azulejo: <code>https://canarias.africa/" . strtoupper($h($id)) . "</code> (en mayúsculas: el QR sale más pequeño) · <a href='/admin/azulejos?id[]={$h($id)}'>Azulejo en PDF</a></p>" : '')
    . $campo('nombre', 'Nombre', $v('nombre'), 'text', 'Por ejemplo: «Muro norte del lavadero», «Web del colectivo».', ['required' => 'required'])
    . '<div class=par>' . $lista('tipo', 'Tipo', TIPOS_SOPORTE, (string) $v('tipo')) . $lista('sensibilidad', 'Sensibilidad', $ETIQUETAS_SENS, (string) $v('sensibilidad')) . '</div>'
    . '<p class=meta>La protección que cuenta es la mayor entre la del soporte y la de sus obras: si una obra es delicada, el soporte tampoco se publica con detalle.</p>'
    . $campo('url', 'URL (si es una web o tiene página propia)', $v('url'), 'url', 'Obligatoria para el tipo «Web o soporte digital», que no necesita punto. Una obra de realidad aumentada lleva URL y punto: su etiqueta se coloca y se verifica con el GPS.')
    . $lista('isla', 'Isla', ['' => 'La del punto'] + ISLAS_CODIGO, (string) $v('isla'))
    . '<div class=par>' . $campo('municipio', 'Municipio', $v('municipio')) . $campo('lugar', 'Lugar', $v('lugar'), 'text', 'Solo se publica si es abierto.') . '</div>'
    . '<div class=par>' . $campo('lat', 'Latitud', $v('lat'), 'number', 'Entre 27,4 y 29,6 (norte). Ej.: 28.4682', ['step' => 'any', 'min' => '27.4', 'max' => '29.6', 'inputmode' => 'decimal']) . $campo('lon', 'Longitud', $v('lon'), 'number', 'Entre −18,4 y −13,2 (oeste, en negativo). Ej.: -16.2546', ['step' => 'any', 'min' => '-18.4', 'max' => '-13.2', 'inputmode' => 'decimal']) . '</div>'
    . $mapa()
    . $campo('descripcion', 'Descripción', $v('descripcion'), 'textarea', 'Material, estado, quién lo cuida… No se publica.')
    . $nodos('obras', 'Obras en este soporte', 'Las de ahora sin «hasta». Si una se pintó encima de otra, la de debajo lleva «hasta» con la fecha (estratigrafía). Si una obra se trasladó, se cierra aquí con «hasta» y se añade en su soporte nuevo.',
        [['obra', 'select', 'Obra', $obras_ops], ['desde', 'text', 'Desde (año o fecha)', ''], ['hasta', 'text', 'Hasta (vacío: sigue)', ''], ['nota', 'text', 'Nota (opcional)', '']], (string) $v('obras'), '+ Añadir obra')
    . '<h2>Etiquetas QR</h2>' . ($etiquetas ? "<ul>$etiquetas</ul>" : '<p class=meta>Sin etiquetas todavía.</p>')
    . $campo('etiqueta_nueva', 'Dar de alta una etiqueta libre en este soporte', '', 'text', 'Lo normal es colocarlas con la app: guardan su punto GPS y se verifican, también las de una web (en un cartel, un local…). Aquí, un código del pool (/admin/qr) para una etiqueta que solo se muestra en pantalla (queda confirmada, sin GPS) o que se colocó sin la app.')
    . '<p><button class=aprobada>Guardar</button> <a href=/admin/soportes>Volver</a></p></form>' . $js_nodos
    . ($id ? "<form id=retirar method=post action=/admin/soporte><input type=hidden name=csrf value=$csrf><input type=hidden name=id value=\"{$h($id)}\"></form>" : '')
    . ($id ? "<details style='margin-top:32px'><summary>Eliminar este soporte</summary>
      <form method=post action=/admin/soporte><input type=hidden name=csrf value=$csrf><input type=hidden name=id value=\"{$h($id)}\"><input type=hidden name=eliminar value=1>
      <p class=meta>Se borran el soporte y sus vínculos con obras; las obras no se tocan. Si ya hay un azulejo impreso o colocado con este UUID, su QR dejará de abrir nada. No se puede eliminar si tiene etiquetas QR colocadas.</p>
      <p><label><input type=checkbox name=confirmo value=1> Sí, quiero eliminar «{$h($v('nombre') ?: 'este soporte')}».</label></p>
      <p><button class=rechazada>Eliminar soporte</button></p></form></details>" : '');
  pagina($id ? 'Editar soporte' : 'Nuevo soporte', $html);
}

// ---------------- Funciones y eventos ----------------
// Cada vez que una obra de repertorio o efímera ocurre (concierto, representación, romería de un año, acción) es una función.
// Los eventos (festival, gira, ciclo, temporada, fiesta) las agrupan. Ver funciones.php.
$ops_obras = function () use ($db): array {
  $r = ['' => 'Elige la obra…'];
  foreach ($db->query('SELECT uuid, titulo, municipio, ambito FROM obras ORDER BY titulo') as $x) $r[$x['uuid']] = $x['titulo'] . ($x['ambito'] === 'referencia' ? ' (referencia)' : ($x['municipio'] ? ' · ' . $x['municipio'] : ''));
  return $r;
};
$ops_eventos = function (string $salvo = '') use ($db): array {
  $r = ['' => 'Ninguno'];
  foreach ($db->query('SELECT uuid, nombre, inicio FROM eventos ORDER BY inicio DESC, nombre') as $x) if ($x['uuid'] !== $salvo) $r[$x['uuid']] = $x['nombre'] . ($x['inicio'] ? ' · ' . $x['inicio'] : '');
  return $r;
};

if ($sub === '/funciones') {
  $html = $aviso_pub() . '<p><a href=/admin/funcion>+ Nueva función</a> · <a href=/admin/eventos>Eventos</a> · <a href=/admin/catalogo>Obras</a></p>
    <p class=meta>Cada vez que una obra ocurre: un concierto, una representación, la romería de un año, una acción. Se publican en <a href=/datos/funciones.geojson>/datos/funciones.geojson</a> si alguna de sus obras está publicada.</p>
    <table class=filtrable><tr><th>Fecha</th><th>Función</th><th>Lugar</th><th>Estado</th><th>Publicada</th></tr>';
  foreach ($db->query('SELECT f.*, s.nombre AS soporte_nombre FROM funciones f LEFT JOIN soportes s ON s.uuid = f.soporte ORDER BY f.inicio DESC') as $f) {
    $titulo = $f['titulo'] ?: (obra(json_decode($f['obras'], true)[0] ?? '')['titulo'] ?? 'Función');
    $html .= "<tr><td>{$h($f['inicio'])}</td><td><a href='/admin/funcion?id={$h($f['uuid'])}'>{$h($titulo)}</a></td><td>{$h($f['soporte_nombre'] ?: ($f['lugar'] ?: $f['municipio']))}</td><td>{$h(ESTADOS_FUNCION[$f['estado']] ?? '')}</td><td>" . ($f['publicada'] ? 'sí' : 'no') . '</td></tr>';
  }
  pagina('Funciones', tabla_o_vacio($html, 'No hay funciones. <a class=boton href=/admin/funcion>+ Añadir la primera</a><br><span class=meta>También desde la ficha de una obra: «+ Nueva función de esta obra».</span>'));
}

if ($sub === '/funcion') {
  $error = '';
  $id = (string) ($_REQUEST['id'] ?? '');
  if ($metodo === 'POST') {
    $d = $_POST;
    try {
      $id = funcion_guardar($d, $id ?: null);
      $_SESSION['publicacion'] = publicar_web();
      header('Location: /admin/funcion?ok=1&id=' . urlencode($id), true, 303);
      exit;
    } catch (InvalidArgumentException $e) { $error = $e->getMessage(); $f = $d; }
  }
  if (!isset($f) && $id) $f = funcion($id);
  $f ??= ['obras' => json_encode(array_filter([strtolower((string) ($_GET['obra'] ?? ''))])), 'estado' => 'celebrada', 'publicada' => 0, 'evento' => (string) ($_GET['evento'] ?? '')];
  $v = fn($k) => $f[$k] ?? '';
  $lista_obras = json_decode((string) $v('obras'), true) ?: [];
  $obras_filas = json_encode(array_map(fn($x) => ['obra' => is_array($x) ? (string) ($x['obra'] ?? '') : (string) $x], array_values((array) $lista_obras)));
  $soportes = ['' => 'Sin soporte (usa municipio o lugar)'];
  foreach ($db->query('SELECT uuid, nombre, tipo, municipio FROM soportes ORDER BY nombre') as $s) $soportes[$s['uuid']] = ($s['nombre'] ?: 'Soporte') . ($s['tipo'] ? ' · ' . (TIPOS_SOPORTE[$s['tipo']] ?? '') : '') . ($s['municipio'] ? ' · ' . $s['municipio'] : '');
  $html = $estilo_form . ($error ? "<p class=error>{$h($error)}</p>" : '') . (!empty($_GET['ok']) ? '<p class=hecho>Guardada.</p>' . $aviso_pub() : '')
    . "<form method=post action=/admin/funcion><input type=hidden name=csrf value=$csrf><input type=hidden name=id value=\"{$h($id)}\">"
    . ($id ? "<p class=meta>Id: <code>{$h($id)}</code></p>" : '')
    . $nodos('obras', 'Obras que se hicieron', 'La obra que se representó o se tocó. Un concierto puede tener varias canciones: añade una fila por obra.',
        [['obra', 'select', 'Obra', $ops_obras()]], $obras_filas, '+ Añadir obra')
    . $campo('titulo', 'Título de la función (opcional)', $v('titulo'), 'text', 'Vacío: el de la primera obra. Ej.: «Mararía en el Teatro Leal».')
    . '<div class=par>' . $campo('inicio', 'Cuándo empezó', $v('inicio'), 'text', 'AAAA, AAAA-MM, AAAA-MM-DD o AAAA-MM-DDTHH:MM. Obligatorio: al menos el año.', ['required' => 'required'])
      . $campo('fin', 'Cuándo acabó (opcional)', $v('fin'), 'text', 'Para funciones de varios días.') . '</div>'
    . '<div class=par>' . $lista('fecha_precision', 'Precisión de la fecha', PRECISIONES_FECHA, (string) $v('fecha_precision')) . $lista('estado', 'Estado', ESTADOS_FUNCION, (string) $v('estado')) . '</div>'
    . $lista('evento', 'Evento (festival, gira, ciclo…)', $ops_eventos(), (string) $v('evento'))
    . '<h2>Dónde</h2><p class=meta>Opcional. Lo mejor es un soporte (sala, plaza, emisión): el QR del sitio mostrará su cartelera. Si no, el municipio, o un lugar escrito a mano (también fuera de Canarias: «Teatro Español, Madrid»). Una función en la red va con un soporte de tipo emisión o web.</p>'
    . $lista('soporte', 'Soporte', $soportes, (string) $v('soporte'))
    . '<div class=par>' . $lista('isla', 'Isla', ['' => 'La del soporte (o ninguna)'] + ISLAS_CODIGO, (string) $v('isla')) . $campo('municipio', 'Municipio', $v('municipio')) . '</div>'
    . $campo('lugar', 'Lugar (texto)', $v('lugar'), 'text', 'Sala, plaza o ciudad. Solo se publica si sus obras y su soporte son abiertos.')
    . '<h2>Quién y qué queda</h2>'
    . $nodos('interpretes', 'Intérpretes y equipo de esta función', 'Quién actuó, tocó, dirigió o hizo la luz ese día. «Visible» solo si ha aceptado figurar o es una figura pública.',
        [['nombre', 'text', 'Nombre o colectivo', ''], ['rol', 'select', 'Rol', ROLES_AGENTE], ['certeza', 'select', 'Certeza', CERTEZAS], ['visible', 'check', 'Visible', 'no']], (string) ($v('interpretes') ?: '[]'), '+ Añadir persona o grupo')
    . $nodos('registros', 'Registros', 'Lo que queda: cartel, programa, entrada, fotos, vídeo, grabación, crónica. Grabaciones y partituras completas solo con permiso de quien tiene los derechos; si no, enlaza o pon un fragmento.',
        [['tipo', 'select', 'Tipo', TIPOS_REGISTRO], ['url', 'text', 'Enlace https://', ''], ['credito', 'text', 'Crédito y licencia', ''], ['nota', 'text', 'Nota', '']], (string) ($v('registros') ?: '[]'), '+ Añadir registro')
    . $campo('descripcion', 'Descripción', $v('descripcion'), 'textarea')
    . $campo('fuentes', 'Fuentes', $v('fuentes'), 'textarea', 'Cómo se sabe: testimonios, prensa, programas. No se publica.')
    . '<div class=guardar>' . $campo('publicada', 'Publicada', $v('publicada'), 'checkbox')
    . '<p><button class=aprobada>Guardar</button> <a href=/admin/funciones>Volver</a></p></div></form>' . $js_nodos;
  pagina($id ? 'Editar función' : 'Nueva función', $html);
}

if ($sub === '/eventos') {
  $html = $aviso_pub() . '<p><a href=/admin/evento>+ Nuevo evento</a> · <a href=/admin/funciones>Funciones</a></p>
    <p class=meta>Festivales, giras, ciclos, temporadas y fiestas. Pueden ir unos dentro de otros (el festival → su edición de 2025). Se publican en <a href=/datos/eventos.geojson>/datos/eventos.geojson</a>.</p>
    <table class=filtrable><tr><th>Evento</th><th>Tipo</th><th>Fechas</th><th>Dentro de</th><th>Funciones</th><th>Publicado</th></tr>';
  foreach ($db->query('SELECT e.*, p.nombre AS padre_nombre, (SELECT COUNT(*) FROM funciones f WHERE f.evento = e.uuid) AS n FROM eventos e LEFT JOIN eventos p ON p.uuid = e.padre ORDER BY e.inicio DESC, e.nombre') as $e) {
    $html .= "<tr><td><a href='/admin/evento?id={$h($e['uuid'])}'>{$h($e['nombre'])}</a></td><td>{$h(TIPOS_EVENTO[$e['tipo']] ?? '')}</td><td>{$h(trim($e['inicio'] . ' – ' . $e['fin'], ' –'))}</td><td>{$h($e['padre_nombre'] ?? '')}</td><td>{$e['n']}</td><td>" . ($e['publicada'] ? 'sí' : 'no') . '</td></tr>';
  }
  pagina('Eventos', tabla_o_vacio($html, 'No hay eventos. <a class=boton href=/admin/evento>+ Añadir el primero</a>'));
}

if ($sub === '/evento') {
  $error = '';
  $id = (string) ($_REQUEST['id'] ?? '');
  if ($metodo === 'POST') {
    $d = $_POST;
    try {
      $id = evento_guardar($d, $id ?: null);
      $_SESSION['publicacion'] = publicar_web();
      header('Location: /admin/evento?ok=1&id=' . urlencode($id), true, 303);
      exit;
    } catch (InvalidArgumentException $e) { $error = $e->getMessage(); $ev = $d; }
  }
  if (!isset($ev) && $id) $ev = evento($id);
  $ev ??= ['nombre' => '', 'publicada' => 0];
  $v = fn($k) => $ev[$k] ?? '';
  $funcs = '';
  if ($id) {
    $st = $db->prepare('SELECT * FROM funciones WHERE evento = ? ORDER BY inicio');
    $st->execute([$id]);
    foreach ($st->fetchAll() as $f) $funcs .= "<li><a href='/admin/funcion?id={$h($f['uuid'])}'>{$h($f['inicio'])} · {$h($f['titulo'] ?: (obra(json_decode($f['obras'], true)[0] ?? '')['titulo'] ?? 'Función'))}</a></li>";
  }
  $html = $estilo_form . ($error ? "<p class=error>{$h($error)}</p>" : '') . (!empty($_GET['ok']) ? '<p class=hecho>Guardado.</p>' . $aviso_pub() : '')
    . "<form method=post action=/admin/evento><input type=hidden name=csrf value=$csrf><input type=hidden name=id value=\"{$h($id)}\">"
    . $campo('nombre', 'Nombre', $v('nombre'), 'text', 'Ej.: «Festival de Música de Canarias 2025», «Gira de Mararía 1998».', ['required' => 'required'])
    . '<div class=par>' . $lista('tipo', 'Tipo', TIPOS_EVENTO, (string) $v('tipo')) . $lista('escala', 'Escala', ['' => 'Sin indicar'] + escalas_ids(), (string) $v('escala')) . '</div>'
    . $lista('padre', 'Dentro de (opcional)', $ops_eventos($id), (string) $v('padre'))
    . '<div class=par>' . $campo('inicio', 'Inicio', $v('inicio'), 'text', 'AAAA, AAAA-MM o AAAA-MM-DD.') . $campo('fin', 'Fin', $v('fin'), 'text') . '</div>'
    . $campo('descripcion', 'Descripción', $v('descripcion'), 'textarea')
    . $campo('web', 'Web', $v('web'), 'url')
    . ($id ? '<h2>Funciones</h2>' . ($funcs ? "<ul>$funcs</ul>" : '<p class=meta>Ninguna todavía.</p>') . "<p><a href='/admin/funcion?evento={$h($id)}'>+ Nueva función en este evento</a></p>" : '')
    . $campo('publicada', 'Publicado', $v('publicada'), 'checkbox')
    . '<p><button class=aprobada>Guardar</button> <a href=/admin/eventos>Volver</a></p></form>';
  pagina($id ? 'Editar evento' : 'Nuevo evento', $html);
}
