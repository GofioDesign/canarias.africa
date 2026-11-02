<?php
// Piezas comunes de la interfaz de /admin: la página con su menú por categorías, los avisos de una sola vez,
// las tablas vacías y la confirmación de borrado escribiendo el nombre (como en GitHub).
declare(strict_types=1);

// Menú de /admin por categorías. Cada entrada: [texto, enlace, rutas en las que queda marcada, contador].
function menu_admin(): array {
  $n = db()->query("SELECT
      (SELECT COUNT(*) FROM envios WHERE estado = 'pendiente' AND archivado IS NULL),
      (SELECT COUNT(*) FROM obras_propuestas WHERE estado = 'pendiente' AND archivado IS NULL),
      (SELECT COUNT(*) FROM participa_ideas WHERE estado = 'nueva' AND archivado IS NULL),
      (SELECT COUNT(*) FROM obras WHERE publicada = 1), (SELECT COUNT(*) FROM obras),
      (SELECT COUNT(*) FROM soportes), (SELECT COUNT(*) FROM qr_codigos WHERE estado = 'libre'),
      (SELECT COUNT(*) FROM colaboradores), (SELECT COUNT(*) FROM participantes WHERE estado = 'confirmada')")->fetch(PDO::FETCH_NUM);
  return [
    'Revisar' => [
      ['Envíos', '/admin', ['', '/'], [$n[0], 'pendientes de revisar']],
      ['Obras propuestas', '/admin/obras', ['/obras'], [$n[1], 'pendientes']],
      ['Ideas y votos', '/admin/participa', ['/participa'], [$n[2], 'ideas nuevas']],
    ],
    'Catálogo' => [
      ['Obras', '/admin/catalogo', ['/catalogo', '/obra', '/obra/versiones'], ["$n[3]/$n[4]", 'publicadas / total']],
      ['Soportes', '/admin/soportes', ['/soportes', '/soporte'], [$n[5], '']],
      ['Funciones', '/admin/funciones', ['/funciones', '/funcion'], null],
      ['Eventos', '/admin/eventos', ['/eventos', '/evento'], null],
      ['Rutas', '/admin/rutas', ['/rutas', '/ruta'], null],
      ['Artistas', '/admin/artistas', ['/artistas', '/artista'], null],
    ],
    'En la calle' => [
      ['En campo (app)', '/admin/campo', ['/campo'], null],
      ['Etiquetas QR', '/admin/qr', ['/qr'], [$n[6], 'libres en el pool']],
      ['Azulejos para imprimir', '/admin/azulejos', ['/azulejos'], null],
    ],
    'Comunidad' => [
      ['Personas colaboradoras', '/admin/personas', ['/personas'], [$n[7], 'cuentas']],
      ['Lista de participantes', '/admin/participantes', ['/participantes', '/participante'], [$n[8], 'confirmadas']],
      ['Escribir a la lista', '/admin/correo', ['/correo'], null],
    ],
    'Sistema' => [
      ['Registro de acciones', '/admin/registro', ['/registro'], null],
      ['Copia de la base de datos', '/admin/copia', ['/copia'], null],
    ],
  ];
}

// Aviso que se muestra una vez en la página siguiente (tras guardar, borrar, archivar…).
function aviso(string $texto, string $clase = 'hecho'): void { $_SESSION['avisos'][] = [$clase, $texto]; }

// Una tabla sin filas se sustituye por un mensaje con el primer paso.
function tabla_o_vacio(string $html, string $vacio): string {
  $i = strrpos($html, '<table');
  return $i !== false && !str_contains(substr($html, $i), '<td') ? substr($html, 0, $i) . "<div class=vacio>$vacio</div>" : $html . '</table>';
}

// Pestañas de filtro (Pendientes · Aprobados · …): [clave => [texto, enlace, contador]].
function pestanas(array $items, string $actual): string {
  $html = '<nav class=pestanas>';
  foreach ($items as $k => [$t, $url, $n]) {
    $html .= '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '"' . ($k === $actual ? ' aria-current=page' : '') . '>' . htmlspecialchars($t) . ($n !== null ? " <span class=cuenta>$n</span>" : '') . '</a>';
  }
  return $html . '</nav>';
}

// Borrar algo exige escribir su nombre, como en GitHub. $nombre es lo que hay que escribir; el servidor lo vuelve
// a comprobar con nombre_confirmado(). $ocultos son los campos que identifican lo que se borra.
function zona_borrado(string $accion, array $ocultos, string $nombre, string $que, string $aviso = ''): string {
  $e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
  $campos = '<input type=hidden name=csrf value="' . $e($_SESSION['csrf']) . '">';
  foreach ($ocultos as $k => $v) $campos .= "<input type=hidden name=\"{$e($k)}\" value=\"{$e($v)}\">";
  return "<details class=zona-peligro><summary>Eliminar {$e($que)}…</summary>
    <form method=post action=\"{$e($accion)}\" data-confirmar=\"{$e($nombre)}\">$campos
    <p>Esto <strong>no se puede deshacer</strong>." . ($aviso ? ' ' . $aviso : '') . "</p>
    <label class=campo><span>Para confirmar, escribe <code class=a-escribir>{$e($nombre)}</code></span>
    <input name=confirmar autocomplete=off autocapitalize=off spellcheck=false required></label>
    <p><button class=peligro disabled>Eliminar {$e($que)}</button></p></form></details>";
}

function nombre_confirmado(string $esperado): bool {
  $n = fn($s) => preg_replace('/\s+/u', ' ', trim((string) $s));
  return $n($esperado) !== '' && $n($_POST['confirmar'] ?? '') === $n($esperado);
}

function pagina(string $titulo, string $cuerpo): never {
  $menu = $avisos = '';
  $con_sesion = !empty($_SESSION['admin']);
  if ($con_sesion) {
    $actual = substr((string) parse_url($_SERVER['REQUEST_URI'] ?? '/admin', PHP_URL_PATH), strlen('/admin'));
    foreach (menu_admin() as $grupo => $items) {
      $menu .= '<div class=grupo><h2>' . htmlspecialchars($grupo) . '</h2>';
      foreach ($items as [$t, $url, $rutas, $c]) {
        $menu .= "<a href=\"$url\"" . (in_array($actual, $rutas, true) ? ' aria-current=page' : '') . '><span>' . htmlspecialchars($t) . '</span>'
          . ($c && $c[0] !== 0 && $c[0] !== '0' ? '<span class=cuenta' . ($c[1] ? ' title="' . htmlspecialchars($c[1]) . '"' : '') . ">$c[0]</span>" : '') . '</a>';
      }
      $menu .= '</div>';
    }
    $menu = "<details class=menu id=menu><summary>Menú</summary><nav aria-label='Panel'>$menu
      <form method=post action=/admin/salir><input type=hidden name=csrf value=\"{$_SESSION['csrf']}\"><button class=enlace>Cerrar la sesión</button></form></nav></details>";
    foreach ($_SESSION['avisos'] ?? [] as [$clase, $texto]) $avisos .= '<p class=' . htmlspecialchars($clase) . '>' . htmlspecialchars($texto) . '</p>';
    unset($_SESSION['avisos']);
  }
  header('Content-Type: text/html; charset=utf-8');
  $clase = $con_sesion ? 'con-menu' : 'sin-menu';
  echo <<<HTML
<!doctype html><html lang=es><head><meta charset=utf-8><meta name=viewport content="width=device-width, initial-scale=1"><meta name=robots content=noindex>
<title>$titulo · admin · canarias.africa</title><style>
:root{--bg:#faf6ee;--sup:#fffdf8;--lateral:#f1ebdf;--fg:#16130f;--muted:#6b6257;--rule:#d9d0c1;--ok:#0a8a3a;--ok-fondo:#e3f3e8;--no:#c8102e;--no-fondo:#fbe7ea;--az:#0b5cad}
@media (prefers-color-scheme:dark){:root{--bg:#12100d;--sup:#1b1814;--lateral:#191612;--fg:#f4eee2;--muted:#a39a8c;--rule:#332e27;--ok:#2fa84f;--ok-fondo:#13261a;--no:#e5354f;--no-fondo:#2a1217;--az:#5aa2ec}}
*{box-sizing:border-box}html,body{overflow-x:hidden}
body{margin:0;background:var(--bg);color:var(--fg);font:16px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif}
a{color:inherit}code{font-size:.92em;overflow-wrap:anywhere}
.con-menu{display:grid;grid-template-columns:250px minmax(0,1fr);min-height:100vh}
main{max-width:1000px;width:100%;padding:24px 28px 64px}
.sin-menu main{max-width:420px;margin:12vh auto 0}
h1{margin:0 0 18px;font-size:26px;line-height:1.2}h2{font-size:19px;margin:28px 0 10px}h3{font-size:16px}
/* Menú lateral por categorías */
.menu{background:var(--lateral);border-right:1px solid var(--rule)}
.menu>summary{display:none}
.menu nav{position:sticky;top:0;max-height:100vh;overflow-y:auto;padding:18px 12px 24px}
.marca{display:flex;align-items:center;gap:8px;font-weight:700;padding:0 10px 14px;text-decoration:none}
.marca svg{flex:none}
.grupo{margin:0 0 14px}
.grupo h2{font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);margin:0 0 4px;padding:0 10px}
.grupo a{display:flex;justify-content:space-between;align-items:center;gap:8px;padding:6px 10px;border-radius:6px;text-decoration:none;font-size:15px}
.grupo a:hover{background:var(--rule)}
.grupo a[aria-current]{background:var(--ok);color:#fff}
.grupo a[aria-current] .cuenta{background:#fff3;color:#fff}
.menu form{padding:8px 10px 0;border-top:1px solid var(--rule);margin-top:6px}
.cuenta{display:inline-block;min-width:1.6em;padding:0 7px;border-radius:10px;background:var(--rule);font-size:12px;line-height:1.6;text-align:center;font-weight:600}
@media (max-width:899px){
  .con-menu{display:block}
  .menu{border-right:0;border-bottom:1px solid var(--rule);position:sticky;top:0;z-index:1000}
  .menu>summary{display:flex;align-items:center;gap:8px;padding:12px 16px;cursor:pointer;font-weight:600;list-style:none}
  .menu>summary::before{content:"☰";font-size:18px}
  .menu nav{position:static;max-height:70vh;padding:0 12px 14px}
  main{padding:20px 16px 56px}
}
/* Botones: principal (verde), normal, peligro (rojo) y enlace */
button,.boton{display:inline-flex;align-items:center;gap:6px;font:inherit;font-size:15px;font-weight:500;line-height:1.3;padding:7px 14px;border-radius:6px;
  border:1px solid var(--rule);background:var(--sup);color:inherit;cursor:pointer;text-decoration:none}
button:hover,.boton:hover{border-color:var(--muted)}
button:focus-visible,.boton:focus-visible,a:focus-visible,input:focus-visible,select:focus-visible,textarea:focus-visible{outline:2px solid var(--az);outline-offset:2px}
.aprobada,.primario,a.boton{background:var(--ok);border-color:var(--ok);color:#fff}
.aprobada:hover,.primario:hover,a.boton:hover{filter:brightness(1.08);border-color:var(--ok)}
a.boton.secundario{background:var(--sup);color:inherit;border-color:var(--rule)}a.boton.secundario:hover{border-color:var(--muted);filter:none}
.rechazada,.peligro{color:var(--no);border-color:var(--no);background:transparent}
.rechazada:hover,.peligro:not(:disabled):hover{background:var(--no);color:#fff}
button:disabled{opacity:.45;cursor:not-allowed}
.enlace{border:0;padding:0;background:none;text-decoration:underline;font-size:14px;color:inherit}
.acciones{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:10px 0 0}
.acciones form,.inline{display:inline}
.botonera{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 16px}
/* Pestañas de filtro */
.pestanas{display:flex;flex-wrap:wrap;gap:4px;border-bottom:1px solid var(--rule);margin:0 0 16px}
.pestanas a{padding:7px 12px;text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-1px;font-size:15px;color:var(--muted)}
.pestanas a[aria-current]{border-bottom-color:var(--ok);color:var(--fg);font-weight:600}
/* Contenido */
.vacio{border:1px dashed var(--rule);border-radius:8px;padding:20px;text-align:center;margin:12px 0}
.error{border:1px solid var(--no);background:var(--no-fondo);color:var(--no);padding:10px 14px;border-radius:6px}
.hecho{border:1px solid var(--ok);background:var(--ok-fondo);padding:10px 14px;border-radius:6px}
.meta{color:var(--muted);font-size:14px}
article,.tarjeta{background:var(--sup);border:1px solid var(--rule);border-radius:8px;padding:14px 16px;margin:0 0 12px}
article>strong:first-child{font-size:17px}
dl{display:grid;grid-template-columns:max-content 1fr;gap:4px 12px}dt{color:var(--muted)}dd{margin:0;white-space:pre-wrap;overflow-wrap:anywhere}
img{max-width:220px;border-radius:4px;margin:4px 8px 0 0}
.tabla{overflow-x:auto}table{border-collapse:collapse;width:100%;background:var(--sup);border:1px solid var(--rule);border-radius:8px}
td,th{border-top:1px solid var(--rule);padding:8px 10px;text-align:left;font-size:14px;overflow-wrap:anywhere;vertical-align:middle}th{font-size:13px;color:var(--muted);font-weight:600;border-top:0}
input,select,textarea{font:inherit;padding:7px 10px;border:1px solid var(--rule);background:var(--sup);color:inherit;border-radius:6px;max-width:100%}
.campo{display:block;margin:10px 0}.campo span{display:block;font-weight:600;font-size:14px;margin-bottom:2px}.campo small{display:block;color:var(--muted);font-size:13px}
.campo input:not([type=checkbox]),.campo textarea,.campo select{width:100%}
.etiqueta{display:inline-block;font-size:12px;padding:1px 8px;border-radius:10px;border:1px solid var(--rule);color:var(--muted)}
.etiqueta.ok{border-color:var(--ok);color:var(--ok)}.etiqueta.no{border-color:var(--no);color:var(--no)}
.alias-form{display:flex;gap:6px;flex-wrap:wrap}.alias-form input{flex:1 1 140px;min-width:0;padding:5px 8px}.alias-form button{padding:5px 10px;font-size:14px}
/* Zona de peligro: borrar escribiendo el nombre */
.zona-peligro{border:1px solid var(--no);border-radius:8px;margin:28px 0 0;padding:0 14px;background:var(--sup)}
.zona-peligro>summary{cursor:pointer;color:var(--no);font-weight:600;padding:10px 0}
.zona-peligro[open]>summary{border-bottom:1px solid var(--rule);margin-bottom:8px}
article .zona-peligro{margin-top:12px;border-color:var(--rule)}
.a-escribir{background:var(--no-fondo);color:var(--no);padding:1px 6px;border-radius:4px;user-select:all}
</style></head><body class=$clase>$menu<main><h1>$titulo</h1>$avisos$cuerpo</main>
<script>
// En pantallas anchas el menú está siempre abierto; en el móvil se despliega con «Menú».
const menu=document.getElementById('menu'); if(menu&&matchMedia('(min-width:900px)').matches) menu.open=true;
if(menu){ const a=document.createElement('a'); a.className='marca'; a.href='/admin';
  a.innerHTML='<svg width=22 height=22 viewBox="0 0 24 24" aria-hidden=true><path fill="#0a8a3a" d="M12 1.5l2.9 7.1 7.6.4-5.9 4.8 2 7.4L12 17l-6.6 4.2 2-7.4-5.9-4.8 7.6-.4z"/></svg><span>canarias.africa</span>';
  menu.querySelector('nav').prepend(a); }
// Buscador en las listas: filtra las filas por cualquier texto (título, municipio, fase, estado…).
document.querySelectorAll('table.filtrable').forEach(t=>{ const filas=[...t.rows].slice(1); if(filas.length<2) return;
  const i=document.createElement('input'); i.type='search'; i.placeholder='Buscar en '+filas.length+' filas…'; i.style.cssText='width:100%;margin:0 0 10px';
  const n=s=>s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g,'');
  i.oninput=()=>{ const q=n(i.value.trim()); filas.forEach(f=>f.hidden=!!q&&!n(f.textContent).includes(q)); };
  (t.closest('.tabla')||t).before(i); });
// Confirmación escribiendo el nombre: el botón se activa solo cuando coincide.
document.querySelectorAll('[data-confirmar]').forEach(f=>{ const esperado=f.dataset.confirmar.replace(/\s+/g,' ').trim();
  const i=f.querySelector('[name=confirmar]'), b=f.querySelector('button.peligro, button[data-tras-confirmar]');
  i.addEventListener('input',()=>{ b.disabled=i.value.replace(/\s+/g,' ').trim()!==esperado; }); });
</script></body></html>
HTML;
  exit;
}
