// Cola de obras tomadas en campo, guardada en el propio móvil (IndexedDB) hasta que haya cobertura.
// La usan la página /admin/campo y el service worker (sincronización en segundo plano), así que no toca el DOM.
// Cada obra lleva un UUID creado en el móvil: si la subida se corta a medias y se repite, el servidor no la duplica.
(function (g) {
  const BD = 'canarias-campo', ALMACEN = 'obras';
  let abierta = null;
  const abrir = () => abierta ??= new Promise((ok, mal) => {
    const r = indexedDB.open(BD, 1);
    r.onupgradeneeded = () => r.result.createObjectStore(ALMACEN, { keyPath: 'id' });
    r.onsuccess = () => ok(r.result);
    r.onerror = () => { abierta = null; mal(r.error); };
  });
  const tx = async (modo, fn) => {
    const db = await abrir();
    return new Promise((ok, mal) => {
      const t = db.transaction(ALMACEN, modo), s = t.objectStore(ALMACEN);
      const r = fn(s);
      t.oncomplete = () => ok(r && 'result' in r ? r.result : undefined);
      t.onerror = t.onabort = () => mal(t.error);
    });
  };

  const Cola = {
    todas: () => tx('readonly', s => s.getAll()).then(l => l.sort((a, b) => a.creada < b.creada ? 1 : -1)),
    una: id => tx('readonly', s => s.get(id)),
    guardar: item => tx('readwrite', s => s.put(item)),
    borrar: id => tx('readwrite', s => s.delete(id)),
    // Sube lo pendiente. Devuelve { sesion, subidas, fallos, sinRed }.
    // - Sin red: se para y todo sigue pendiente.
    // - Sesión caducada: no sube nada; hay que volver a entrar en /admin.
    // - El servidor rechaza los datos (falta el título, coordenadas fuera de Canarias…): queda «revisar» con el motivo.
    subirTodas: async () => {
      const res = { sesion: true, subidas: 0, fallos: 0, sinRed: false };
      if (Cola.subiendo) return res;
      Cola.subiendo = true;
      try {
        const pendientes = (await Cola.todas()).filter(i => i.estado === 'pendiente');
        if (!pendientes.length) return res;
        let token;
        try {
          token = await (await fetch('/admin/campo/token', { credentials: 'same-origin', cache: 'no-store' })).json();
        } catch (e) { res.sinRed = true; return res; }
        if (!token.sesion) { res.sesion = false; return res; }
        for (const item of pendientes.reverse()) {  // de la más antigua a la más nueva
          const f = new FormData();
          f.append('csrf', token.csrf);
          f.append('uuid', item.id);
          f.append('creada', item.creada);
          for (const [k, v] of Object.entries(item.datos)) f.append(k, Array.isArray(v) ? v.join(',') : v ?? '');
          if (item.foto) f.append('foto_archivo', item.foto, 'foto.jpg');
          let r, j = {};
          try {
            r = await fetch('/admin/campo/subir', { method: 'POST', body: f, credentials: 'same-origin' });
            try { j = await r.json(); } catch (e) {}
          } catch (e) { res.sinRed = true; break; }
          if (r.ok && j.ok) {
            Object.assign(item, { estado: 'subida', obra: j.id, subida: new Date().toISOString(), error: '' });
            delete item.foto;  // la foto ya está en el servidor: libera espacio en el móvil
            res.subidas++;
          } else if (r.status === 403) { res.sesion = false; break; }
          else if (r.status >= 500 || !r.status) { res.sinRed = true; break; }
          else { Object.assign(item, { estado: 'revisar', error: j.error || ('Error ' + r.status) }); res.fallos++; }
          await Cola.guardar(item);
        }
        return res;
      } finally { Cola.subiendo = false; }
    },
  };
  g.Cola = Cola;
})(self);
