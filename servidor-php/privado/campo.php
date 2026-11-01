<?php
// /admin/campo: app para registrar obras sin cobertura. La página se instala en el móvil (PWA), guarda cada obra con su
// foto y su punto GPS en el propio teléfono y las sube como borradores (sin publicar) cuando vuelve la red.
// Luego se revisan y se completan en /admin/obra, con el mapa y el resto de campos.
declare(strict_types=1);

// Archivos de la app: no llevan datos, así que se sirven sin sesión (el service worker los guarda para abrirlos sin red).
function campo_estatico(string $sub): void {
  $dir = PRIVADO . '/campo/';
  $archivos = ['/campo/cola.js' => ['cola.js', 'application/javascript'], '/campo/icono-192.png' => ['icono-192.png', 'image/png'],
    '/campo/icono-512.png' => ['icono-512.png', 'image/png']];
  if (isset($archivos[$sub])) {
    [$f, $tipo] = $archivos[$sub];
    header("Content-Type: $tipo" . (str_ends_with($f, '.js') ? '; charset=utf-8' : ''));
    header('Cache-Control: no-cache');
    readfile($dir . $f);
    exit;
  }
  if ($sub === '/sw.js') {
    // Huella de todo lo que forma la app: cualquier cambio en estos archivos instala la versión nueva en los móviles.
    $v = substr(md5(implode('|', array_map(fn($f) => md5_file($f), [__FILE__, $dir . 'sw.js', $dir . 'cola.js']))), 0, 10);
    header('Content-Type: application/javascript; charset=utf-8');
    header('Cache-Control: no-cache');
    echo str_replace('__VERSION__', $v, file_get_contents($dir . 'sw.js'));
    exit;
  }
  if ($sub === '/manifest.webmanifest') {
    header('Content-Type: application/manifest+json; charset=utf-8');
    echo json_encode(['name' => 'canarias.africa · En campo', 'short_name' => 'En campo', 'lang' => 'es', 'start_url' => '/admin/campo',
      'scope' => '/admin/', 'display' => 'standalone', 'background_color' => '#faf6ee', 'theme_color' => '#007a3d',
      'icons' => [['src' => '/admin/campo/icono-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
        ['src' => '/admin/campo/icono-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable']]],
      JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
  }
  if ($sub === '/campo') campo_pagina();
}

// La cola pide el token antes de subir: así sabe si la sesión sigue abierta sin tener que cargar ninguna página.
function campo_token(): never {
  header('Cache-Control: no-store');
  json_respuesta(empty($_SESSION['admin']) ? ['sesion' => false] : ['sesion' => true, 'csrf' => $_SESSION['csrf']]);
}

// Recibe una obra de la cola. Siempre entra como borrador sin publicar. Si ya existe (la subida se repitió), no la toca.
function campo_subir(callable $foto_formulario): never {
  header('Cache-Control: no-store');
  $uuid = strtolower((string) ($_POST['uuid'] ?? ''));
  if (!preg_match('/^' . RE_UUID . '$/', $uuid)) error_publico('Falta el identificador de la obra.', 422);
  if (obra($uuid)) json_respuesta(['ok' => true, 'id' => $uuid, 'repetida' => true]);
  $d = array_intersect_key($_POST, array_flip(['titulo', 'lat', 'lon', 'isla', 'municipio', 'lugar', 'tipo', 'fase', 'sensibilidad', 'descripcion', 'desconocidos']));
  $d['publicada'] = 0;
  $d['isla'] = isset(ISLAS_CODIGO[$d['isla'] ?? '']) ? $d['isla'] : '';
  // La precisión del GPS y la nota quedan en «fuentes», a la vista al revisar la ficha.
  $nota = [];
  if (($p = (float) ($_POST['precision'] ?? 0)) > 0) $nota[] = 'Punto tomado con el GPS del móvil (±' . round($p) . ' m).';
  if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', (string) ($_POST['creada'] ?? ''))) $nota[] = 'Registrada en campo el ' . substr((string) $_POST['creada'], 0, 10) . '.';
  if (trim((string) ($_POST['nota'] ?? '')) !== '') $nota[] = trim((string) $_POST['nota']);
  $d['fuentes'] = implode("\n", $nota);
  try {
    if ($url = $foto_formulario()) $d['foto'] = $url;
    $id = obra_guardar($d, $uuid, 'campo');
  } catch (InvalidArgumentException $e) {
    error_publico($e->getMessage(), 422);
  }
  db()->prepare('INSERT INTO admin_registro (accion, detalle, ip, agente) VALUES (?, ?, ?, ?)')
     ->execute(['campo_subida', $id, $_SERVER['REMOTE_ADDR'] ?? '', mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 300)]);
  json_respuesta(['ok' => true, 'id' => $id]);
}

function campo_pagina(): never {
  $h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
  $ops = fn(array $l, string $sel = '') => implode('', array_map(fn($k, $t) => "<option value=\"{$h($k)}\"" . ($k === $sel ? ' selected' : '') . ">{$h($t)}</option>", array_keys($l), $l));
  $fases = ['plenitud' => 'Plenitud: terminada y en buen estado', 'creacion' => 'Creación: se está haciendo', 'desgaste' => 'Desgaste: sol, sal, humedad o roces',
    'intervencion' => 'Intervención: restaurada o tapada en parte', 'transformacion' => 'Transformación: otra obra la cambia',
    'desaparicion' => 'Desaparición: borrada o retirada', 'memoria' => 'Memoria: ya no existe', 'gestacion' => 'Gestación: en proyecto'];
  $sens = ['abierta' => 'Abierta (punto exacto)', 'difusa' => 'Difusa (zona de ~2 km)', 'reservada' => 'Reservada: obra delicada (solo la isla)'];
  $islas = ['' => 'La que diga el GPS'] + ISLAS_CODIGO;
  $desc = implode(' ', array_map(fn($k, $t) => "<label class=casilla><input type=checkbox name=desconocidos value=$k> {$h($t)}</label>", array_keys(CAMPOS_DESCONOCIBLES), CAMPOS_DESCONOCIBLES));
  header('Content-Type: text/html; charset=utf-8');
  header('Cache-Control: no-cache');
  echo <<<HTML
<!doctype html><html lang=es><head><meta charset=utf-8><meta name=viewport content="width=device-width, initial-scale=1"><meta name=robots content=noindex>
<title>En campo · canarias.africa</title><link rel=manifest href=/admin/manifest.webmanifest><meta name=theme-color content="#007a3d">
<link rel=icon href=/admin/campo/icono-192.png><link rel=apple-touch-icon href=/admin/campo/icono-192.png>
<style>
:root{--bg:#faf6ee;--fg:#16130f;--muted:#6b6257;--rule:#d9d0c1;--ok:#007a3d;--no:#c8102e;--av:#b26b00}
@media (prefers-color-scheme:dark){:root{--bg:#12100d;--fg:#f4eee2;--muted:#a39a8c;--rule:#2e2a24;--ok:#2aa866;--no:#e5354f;--av:#e0a030}}
html,body{overflow-x:hidden}body{margin:0;background:var(--bg);color:var(--fg);font:16px/1.5 system-ui,sans-serif}main{max-width:640px;margin:0 auto;padding:16px 16px 96px}
a{color:inherit}h1{font-size:22px;margin:0}h2{font-size:18px;margin:24px 0 8px}.meta{color:var(--muted);font-size:14px}
.barra{display:flex;flex-wrap:wrap;gap:6px 12px;align-items:center;justify-content:space-between;margin:0 0 12px;padding:0 0 10px;border-bottom:1px solid var(--rule)}
.red{font-size:13px;padding:2px 8px;border-radius:9px;border:1px solid currentColor}.red.si{color:var(--ok)}.red.no{color:var(--av)}
.campo{display:block;margin:12px 0}.campo>span{display:block;font-weight:600;font-size:14px}.campo small{display:block;color:var(--muted);font-size:13px}
input,select,textarea,button{font:inherit;padding:8px 10px;border:1px solid var(--rule);background:transparent;color:inherit;border-radius:4px;box-sizing:border-box}
.campo input,.campo select,.campo textarea{width:100%}button{cursor:pointer}.dos{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.primario{border-color:var(--ok);background:var(--ok);color:#fff;font-weight:600}.casilla{display:inline-block;margin:2px 10px 2px 0;font-size:14px}
.guardar{position:fixed;left:0;right:0;bottom:0;background:var(--bg);border-top:1px solid var(--rule);padding:10px 16px}.guardar div{max-width:640px;margin:0 auto;display:flex;gap:8px}.guardar .primario{flex:1}
.aviso{border:1px solid var(--av);color:var(--av);padding:8px 12px;border-radius:4px;margin:8px 0}.aviso.ok{border-color:var(--ok);color:var(--ok)}.aviso.no{border-color:var(--no);color:var(--no)}
#vista{max-width:100%;max-height:220px;border-radius:4px;display:block;margin:6px 0}
.item{border-top:1px solid var(--rule);padding:10px 0;display:flex;gap:10px;align-items:flex-start}.item img{width:64px;height:64px;object-fit:cover;border-radius:4px;flex:none}
.item .t{font-weight:600;overflow-wrap:anywhere}.item .acc{margin-top:4px;display:flex;flex-wrap:wrap;gap:6px}.item button{padding:4px 8px;font-size:14px}
.est{font-size:12px;padding:1px 6px;border-radius:8px;border:1px solid currentColor}.est.pendiente{color:var(--av)}.est.subida{color:var(--ok)}.est.revisar{color:var(--no)}
details summary{cursor:pointer;color:var(--muted)}
</style></head><body><main>
<div class=barra><h1>En campo</h1><span id=red class=red></span><a class=meta href=/admin>Volver a /admin</a></div>
<p class=meta>Registra obras aunque no haya cobertura: se guardan en este móvil y se suben solas, sin publicar, cuando vuelve la red. Después se revisan y se completan en /admin.</p>
<div id=sesion></div>
<form id=f autocomplete=off>
<input type=hidden name=id>
<label class=campo><span>Título *</span><input name=titulo required maxlength=300 placeholder="Si no tiene, describe lo que se ve: «Mural del lavadero»"></label>
<label class=campo><span>Foto</span><input type=file name=foto accept="image/*" capture=environment><small>Se reduce en el móvil antes de guardarla. No guarda la ubicación de la foto.</small></label>
<img id=vista alt="" hidden>
<div class=campo><span>Punto</span>
<p style="margin:4px 0"><button type=button id=gps>Tomar el punto con el GPS</button> <span id=gpsinfo class=meta></span></p>
<div class=dos><input name=lat type=number step=any min=27.4 max=29.6 placeholder=Latitud inputmode=decimal><input name=lon type=number step=any min=-18.4 max=-13.2 placeholder=Longitud inputmode=decimal></div>
<input type=hidden name=precision><small>Sin punto, hace falta al menos el municipio.</small></div>
<div class=dos><label class=campo><span>Municipio</span><input name=municipio maxlength=120></label><label class=campo><span>Isla</span><select name=isla>{$ops($islas)}</select></label></div>
<label class=campo><span>Lugar</span><input name=lugar maxlength=300 placeholder="Calle, plaza, barranco…"></label>
<label class=campo><span>Sensibilidad</span><select name=sensibilidad>{$ops($sens, 'abierta')}</select><small>Ante la duda, protege más: se puede abrir después, pero lo publicado no se puede borrar del todo.</small></label>
<details><summary>Más datos (opcional)</summary>
<label class=campo><span>Tipo</span><input name=tipo maxlength=120 placeholder="Mural, escultura, grafiti, instalación…"></label>
<label class=campo><span>Fase</span><select name=fase>{$ops($fases, 'plenitud')}</select></label>
<label class=campo><span>Descripción</span><textarea name=descripcion rows=3></textarea></label>
<div class=campo><span>Datos que no se conocen</span>$desc</div>
<label class=campo><span>Nota para la revisión</span><textarea name=nota rows=2 placeholder="Lo que quieras recordar al pasarla a la ficha (no se publica)"></textarea></label>
</details>
<div id=msg></div>
<div class=guardar><div><button type=button id=nueva hidden>Cancelar</button><button class=primario>Guardar en el móvil</button></div></div>
</form>
<h2>En este móvil <span id=cuenta class=meta></span></h2>
<p><button type=button id=subir>Subir ahora</button></p>
<div id=lista></div>
</main>
<script src=/admin/campo/cola.js></script>
<script>
const f=document.getElementById('f'), \$=id=>document.getElementById(id);
let foto=null;  // Blob JPEG ya reducido
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]);
const aviso=(t,cl='')=>\$('msg').innerHTML=t?'<p class="aviso '+cl+'">'+t+'</p>':'';

// Estado de la red
const red=()=>{ const s=navigator.onLine; \$('red').textContent=s?'Con conexión':'Sin conexión'; \$('red').className='red '+(s?'si':'no'); };
addEventListener('online',()=>{red(); subir();}); addEventListener('offline',red); red();

// Foto: se reduce a 2000 px de lado en JPEG para que ocupe poco y suba rápido con poca cobertura.
f.foto.onchange=async()=>{ const a=f.foto.files[0]; if(!a) return;
  try{ const b=await createImageBitmap(a,{imageOrientation:'from-image'}); const k=Math.min(1,2000/Math.max(b.width,b.height));
    const c=document.createElement('canvas'); c.width=Math.round(b.width*k); c.height=Math.round(b.height*k); c.getContext('2d').drawImage(b,0,0,c.width,c.height);
    foto=await new Promise(ok=>c.toBlob(ok,'image/jpeg',0.85)); }
  catch(e){ foto=a; }
  \$('vista').src=URL.createObjectURL(foto); \$('vista').hidden=false; };

// GPS: se queda con la lectura más precisa durante unos segundos.
\$('gps').onclick=()=>{ if(!navigator.geolocation) return aviso('Este móvil no da la ubicación.','no');
  let mejor=null; \$('gpsinfo').textContent='Buscando satélites…';
  const w=navigator.geolocation.watchPosition(g=>{ if(!mejor||g.coords.accuracy<mejor.accuracy){ mejor=g.coords;
      f.lat.value=mejor.latitude.toFixed(6); f.lon.value=mejor.longitude.toFixed(6); f.precision.value=Math.round(mejor.accuracy);
      \$('gpsinfo').textContent='±'+Math.round(mejor.accuracy)+' m'+(mejor.accuracy>25?' (espera un poco o muévete a cielo abierto)':''); } },
    e=>{ \$('gpsinfo').textContent=''; aviso('No se ha podido leer la ubicación: '+esc(e.message),'no'); },{enableHighAccuracy:true,timeout:60000,maximumAge:0});
  setTimeout(()=>navigator.geolocation.clearWatch(w),20000); };

f.onsubmit=async ev=>{ ev.preventDefault(); aviso('');
  const d=Object.fromEntries(['titulo','lat','lon','precision','municipio','isla','lugar','sensibilidad','tipo','fase','descripcion','nota'].map(k=>[k,f[k].value.trim()]));
  d.desconocidos=[...f.querySelectorAll('[name=desconocidos]:checked')].map(i=>i.value);
  if(!d.titulo) return aviso('Falta el título.','no');
  if(!!d.lat!==!!d.lon) return aviso('Faltan la latitud o la longitud.','no');
  if(d.lat&&(+d.lat<27.4||+d.lat>29.6||+d.lon<-18.4||+d.lon>-13.2)) return aviso('Las coordenadas no caen en Canarias.','no');
  if(!d.lat&&!d.municipio) return aviso('Sin punto, escribe al menos el municipio.','no');
  const id=f.id.value||crypto.randomUUID(), antes=f.id.value?await Cola.una(id):null;
  await Cola.guardar({id,creada:antes?.creada||new Date().toISOString(),datos:d,foto:foto||antes?.foto||null,estado:'pendiente',error:''});
  limpiar(); aviso('Guardada en el móvil.'+(navigator.onLine?' Subiendo…':' Se subirá cuando haya conexión.'),'ok');
  await pintar(); subir(); };

const limpiar=()=>{ f.reset(); f.id.value=''; foto=null; \$('vista').hidden=true; \$('gpsinfo').textContent=''; \$('nueva').hidden=true; };
\$('nueva').onclick=()=>{ limpiar(); aviso(''); };

async function editar(id){ const i=await Cola.una(id); if(!i) return; limpiar(); f.id.value=id;
  for(const [k,v] of Object.entries(i.datos)) if(f[k]&&k!=='desconocidos') f[k].value=v;
  f.querySelectorAll('[name=desconocidos]').forEach(c=>c.checked=(i.datos.desconocidos||[]).includes(c.value));
  if(i.foto){ \$('vista').src=URL.createObjectURL(i.foto); \$('vista').hidden=false; }
  \$('nueva').hidden=false; scrollTo(0,0); }

async function pintar(){ const l=await Cola.todas(), p=l.filter(i=>i.estado==='pendiente').length;
  \$('cuenta').textContent=l.length?'· '+p+' por subir de '+l.length:''; \$('subir').hidden=!p;
  \$('lista').innerHTML=l.length?l.map(i=>'<div class=item>'+(i.foto?'<img src="'+URL.createObjectURL(i.foto)+'" alt="">':'')
    +'<div><div class=t>'+esc(i.datos.titulo)+'</div><div class=meta>'+esc([i.datos.municipio,i.datos.precision&&'±'+i.datos.precision+' m',new Date(i.creada).toLocaleString('es')].filter(Boolean).join(' · '))+'</div>'
    +'<span class="est '+i.estado+'">'+({pendiente:'Por subir',subida:'Subida, sin publicar',revisar:'Hay que revisarla'})[i.estado]+'</span>'
    +(i.error?' <span class=meta>'+esc(i.error)+'</span>':'')
    +'<div class=acc>'+(i.estado==='subida'?'<a href="/admin/obra?id='+esc(i.obra)+'">Completar en /admin</a> <button data-quitar="'+i.id+'">Quitar de la lista</button>'
      :'<button data-editar="'+i.id+'">Editar</button> <button data-borrar="'+i.id+'">Borrar</button>')+'</div></div></div>').join('')
    :'<p class=meta>Todavía no hay obras guardadas en este móvil.</p>'; }

\$('lista').onclick=async e=>{ const b=e.target.closest('button'); if(!b) return;
  if(b.dataset.editar) return editar(b.dataset.editar);
  if(b.dataset.borrar&&!confirm('¿Borrar esta obra del móvil? No se ha subido y se perderá.')) return;
  await Cola.borrar(b.dataset.borrar||b.dataset.quitar); pintar(); };

function resultado(r){ if(!r) return;
  \$('sesion').innerHTML=r.sesion?'':'<p class=aviso>La sesión de /admin ha caducado. <a href="/admin?volver=campo">Entra de nuevo</a> y vuelve aquí para subir lo pendiente.</p>';
  if(r.subidas) aviso(r.subidas===1?'Se ha subido 1 obra.':'Se han subido '+r.subidas+' obras.','ok');
  if(r.fallos) aviso('Hay obras que revisar: mira el motivo en la lista.','no');
  pintar(); }
async function subir(){ if(!navigator.onLine) return; resultado(await Cola.subirTodas()); }
\$('subir').onclick=subir;

if('serviceWorker' in navigator){
  navigator.serviceWorker.register('/admin/sw.js',{scope:'/admin/'}).catch(()=>{});
  navigator.serviceWorker.addEventListener('message',e=>{ if(e.data?.tipo==='subida') resultado(e.data.res); });
  // Si el móvil lo permite, el navegador sube la cola en cuanto vuelve la red aunque la página esté cerrada.
  navigator.serviceWorker.ready.then(r=>r.sync?.register('subir-obras')).catch(()=>{});
}
if(navigator.storage?.persist) navigator.storage.persist();  // que el navegador no borre la cola para liberar espacio
pintar(); subir();
</script></body></html>
HTML;
  exit;
}
