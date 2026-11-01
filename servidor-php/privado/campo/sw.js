// Service worker de /admin: guarda la página «En campo» para abrirla sin cobertura y sube la cola cuando vuelve la red.
// El servidor pone en CACHE una huella de los archivos: si cambian, los móviles descargan la versión nueva.
importScripts('/admin/campo/cola.js');
const CACHE = 'campo-__VERSION__';
const BASE = ['/admin/campo', '/admin/campo/cola.js', '/admin/manifest.webmanifest', '/admin/campo/icono-192.png', '/admin/campo/icono-512.png'];

self.addEventListener('install', e => e.waitUntil(caches.open(CACHE).then(c => c.addAll(BASE)).then(() => self.skipWaiting())));
self.addEventListener('activate', e => e.waitUntil(
  caches.keys().then(l => Promise.all(l.filter(k => k.startsWith('campo-') && k !== CACHE).map(k => caches.delete(k)))).then(() => self.clients.claim())));

self.addEventListener('fetch', e => {
  const u = new URL(e.request.url);
  if (e.request.method !== 'GET' || u.origin !== location.origin) return;
  // La página de campo: primero la red (para estar al día) y, si no hay, la copia guardada.
  if (u.pathname === '/admin/campo') {
    e.respondWith(fetch(e.request).then(r => { if (r.ok) { const c = r.clone(); caches.open(CACHE).then(k => k.put('/admin/campo', c)); } return r; })
      .catch(() => caches.match('/admin/campo')));
    return;
  }
  if (BASE.includes(u.pathname)) { e.respondWith(caches.match(u.pathname).then(r => r || fetch(e.request))); return; }
  // Cualquier otra página de /admin sin red: se ofrece la de campo, que es la que funciona sin conexión.
  if (e.request.mode === 'navigate' && u.pathname.startsWith('/admin')) {
    e.respondWith(fetch(e.request).catch(() => caches.match('/admin/campo')));
  }
});

// Sincronización en segundo plano (Chrome/Android): sube la cola aunque la página esté cerrada.
// Si no hay red, se rechaza para que el navegador lo vuelva a intentar más tarde.
self.addEventListener('sync', e => { if (e.tag === 'subir-obras') e.waitUntil(Cola.subirTodas().then(async r => { await avisar(r); if (r.sinRed) throw new Error('sin red'); })); });
self.addEventListener('message', e => { if (e.data === 'subir') e.waitUntil(Cola.subirTodas().then(avisar)); });
async function avisar(res) { (await self.clients.matchAll()).forEach(c => c.postMessage({ tipo: 'subida', res })); }
