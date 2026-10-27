// Worker de canarias.africa: API pública, QR, formularios, moderación, caché de teselas OSM y copia de uMap.
// Todo cabe en el plan gratuito de Cloudflare (Workers + D1 + R2 + KV).
import { coordenadasPublicas } from "./publico.js";
import { usuarioAdmin } from "./acceso.js";

const FORMULARIOS = {
  observaciones: "observacion",
  contexto: "contexto",
  propuestas_obra: "obra_nueva",
  propuestas_ruta: "ruta",
  inscripciones: "inscripcion",
};
const MAX_FOTO = 10 * 1024 * 1024;

export default {
  async fetch(request, env, ctx) {
    const url = new URL(request.url);
    const p = url.pathname;
    let m;
    try {
      if (request.method === "GET" && (m = p.match(/^\/teselas\/osm\/(\d+)\/(\d+)\/(\d+)\.png$/))) return teselas(request, env, ctx, m);
      if (request.method === "GET" && (m = p.match(/^\/datos\/umap\/(qr|rutas|obras)\.geojson$/))) return capaUMap(env, m[1]);
      if (request.method === "GET" && p === "/api/publico/obras") return obrasPublicas(env);
      if (request.method === "GET" && (m = p.match(/^\/api\/publico\/obras\/([\w-]+)$/))) return fichaPublica(env, m[1]);
      if (request.method === "GET" && (m = p.match(/^\/q\/([\w-]+)$/))) return qrRedirige(env, url, m[1]);
      if (request.method === "POST" && (m = p.match(/^\/api\/collections\/(\w+)\/records$/))) return recibirEnvio(request, env, m[1]);
      if (p.startsWith("/api/admin/")) return admin(request, env, p);
      return env.ASSETS.fetch(request);
    } catch (err) {
      if (err instanceof ErrorPublico) return json({ error: err.message }, err.status);
      console.error(err);
      return json({ error: "Error interno." }, 500);
    }
  },

  // Copia horaria de las capas públicas de uMap. Si una falla, se conserva la anterior.
  async scheduled(_evento, env) {
    for (const [nombre, capa] of [["qr", env.UMAP_CAPA_QR], ["rutas", env.UMAP_CAPA_RUTAS]]) {
      if (!env.UMAP_MAPA || !capa) continue;
      try {
        const r = await fetch(`${env.UMAP_SERVIDOR}/datalayer/${env.UMAP_MAPA}/${capa}/`, {
          headers: { "User-Agent": `canarias.africa umap sync (${env.CONTACTO})` },
        });
        const gj = await r.json();
        if (r.ok && gj.type === "FeatureCollection") await env.UMAP.put(nombre, JSON.stringify(gj));
      } catch (err) {
        console.error(`uMap: no se pudo actualizar la capa ${nombre}`, err);
      }
    }
  },
};

class ErrorPublico extends Error {
  constructor(message, status = 400) { super(message); this.status = status; }
}

function json(datos, status = 200, cabeceras = {}) {
  return new Response(JSON.stringify(datos), {
    status,
    headers: { "Content-Type": "application/json; charset=utf-8", ...cabeceras },
  });
}

// Caché de teselas: se llena con las visitas reales, nunca con descargas en bloque
// (https://operations.osmfoundation.org/policies/tiles/).
async function teselas(request, env, ctx, [, z, x, y]) {
  if (+z > 19) return new Response("Zoom no válido", { status: 400 });
  const cache = caches.default;
  const clave = new Request(new URL(request.url).toString());
  const guardada = await cache.match(clave);
  if (guardada) return guardada;
  const r = await fetch(`https://tile.openstreetmap.org/${z}/${x}/${y}.png`, {
    headers: { "User-Agent": `canarias.africa tile cache (contacto: ${env.CONTACTO})` },
  });
  if (!r.ok) return new Response(null, { status: r.status });
  const resp = new Response(r.body, {
    headers: { "Content-Type": "image/png", "Cache-Control": "public, max-age=2592000" },
  });
  ctx.waitUntil(cache.put(clave, resp.clone()));
  return resp;
}

async function capaUMap(env, nombre) {
  const gj = await env.UMAP.get(nombre);
  if (!gj) return json({ type: "FeatureCollection", features: [] });
  return new Response(gj, { headers: { "Content-Type": "application/geo+json", "Cache-Control": "no-cache" } });
}

function obraPublica(o) {
  const c = coordenadasPublicas(o.lat, o.lon, o.sensibilidad);
  return {
    type: "Feature",
    geometry: c ? { type: "Point", coordinates: [c.lon, c.lat] } : null,
    properties: {
      id: o.codigo, name: o.titulo, fase: o.fase, municipio: o.municipio,
      sensibilidad: o.sensibilidad, precision: c ? c.precision : "municipio",
    },
  };
}

async function obrasPublicas(env) {
  const { results } = await env.DB.prepare(
    "SELECT codigo, titulo, fase, municipio, sensibilidad, lat, lon FROM nodos WHERE tipo = 'obra' AND publicado = 1 ORDER BY codigo"
  ).all();
  return json({ type: "FeatureCollection", features: results.map(obraPublica) }, 200, { "Cache-Control": "public, max-age=300" });
}

async function fichaPublica(env, codigo) {
  const obra = await env.DB.prepare(
    "SELECT * FROM nodos WHERE tipo = 'obra' AND publicado = 1 AND codigo = ?"
  ).bind(codigo).first();
  if (!obra) throw new ErrorPublico("No existe esa obra.", 404);
  const [rel, obs] = await env.DB.batch([
    env.DB.prepare("SELECT tipo, origen, destino, certeza, fecha, fuente FROM relaciones WHERE origen = ?1 OR destino = ?1 ORDER BY fecha").bind(codigo),
    env.DB.prepare("SELECT creado, datos FROM envios WHERE formulario = 'observacion' AND obra = ? AND estado = 'aprobada' ORDER BY creado DESC").bind(codigo),
  ]);
  const ficha = obraPublica(obra);
  ficha.properties.descripcion = obra.descripcion;
  ficha.properties.relaciones = rel.results;
  ficha.properties.observaciones = obs.results.map((o) => {
    const d = JSON.parse(o.datos);
    return { fecha: o.creado, fase: d.fase, texto: d.texto, alias: d.alias };
  });
  return json(ficha);
}

async function qrActivo(env, codigo) {
  return env.DB.prepare("SELECT obra FROM qr WHERE codigo = ? AND activo = 1").bind(codigo).first();
}

async function qrRedirige(env, url, codigo) {
  const qr = await qrActivo(env, codigo);
  const destino = qr ? `/#/observar/${qr.obra}?qr=${encodeURIComponent(codigo)}` : "/#/qr-no-valido";
  return Response.redirect(new URL(destino, url.origin).toString(), 302);
}

// Formularios públicos. Todo entra como pendiente y exige aceptar el aviso de privacidad.
async function recibirEnvio(request, env, coleccion) {
  const formulario = FORMULARIOS[coleccion];
  if (!formulario) throw new ErrorPublico("Formulario desconocido.", 404);
  const fd = await request.formData();
  if (!["true", "on", "1"].includes(String(fd.get("acepta_privacidad")))) {
    throw new ErrorPublico("Hay que aceptar el aviso de privacidad para enviar el formulario.");
  }
  const obra = fd.get("obra") ? String(fd.get("obra")) : null;
  if (formulario === "observacion") {
    const qr = await qrActivo(env, String(fd.get("qr_codigo") || ""));
    if (!qr) throw new ErrorPublico("El código QR no es válido o ya no está activo.");
    if (qr.obra !== obra) throw new ErrorPublico("El código QR no corresponde a esta obra.");
  }
  const datos = {}, fotos = [];
  for (const [k, v] of fd.entries()) {
    if (typeof v === "string") { datos[k] = v.slice(0, 5000); continue; }
    if (!v.size) continue;
    if (v.size > MAX_FOTO || !/^image\/(jpeg|png|webp|heic)$/.test(v.type)) throw new ErrorPublico("La foto debe ser una imagen de menos de 10 MB.");
    const clave = `${formulario}/${crypto.randomUUID()}`;
    await env.FOTOS.put(clave, v.stream(), { httpMetadata: { contentType: v.type } });
    fotos.push(clave);
  }
  await env.DB.prepare("INSERT INTO envios (formulario, obra, datos, fotos) VALUES (?, ?, ?, ?)")
    .bind(formulario, obra, JSON.stringify(datos), JSON.stringify(fotos)).run();
  return json({ ok: true, estado: "pendiente" }, 201);
}

// Moderación: solo para cuentas autorizadas en Cloudflare Access (ver acceso.js).
async function admin(request, env, p) {
  const quien = await usuarioAdmin(request, env);
  if (!quien) throw new ErrorPublico("No autorizado.", 403);
  let m;
  if (request.method === "GET" && p === "/api/admin/envios") {
    const estado = new URL(request.url).searchParams.get("estado") || "pendiente";
    const { results } = await env.DB.prepare("SELECT * FROM envios WHERE estado = ? ORDER BY creado").bind(estado).all();
    return json(results);
  }
  if (request.method === "POST" && (m = p.match(/^\/api\/admin\/envios\/(\d+)$/))) {
    const { estado } = await request.json();
    if (!["aprobada", "rechazada", "pendiente"].includes(estado)) throw new ErrorPublico("Estado no válido.");
    await env.DB.prepare("UPDATE envios SET estado = ? WHERE id = ?").bind(estado, +m[1]).run();
    return json({ ok: true });
  }
  if (request.method === "GET" && (m = p.match(/^\/api\/admin\/fotos\/(.+)$/))) {
    const obj = await env.FOTOS.get(decodeURIComponent(m[1]));
    if (!obj) throw new ErrorPublico("No existe esa foto.", 404);
    return new Response(obj.body, { headers: { "Content-Type": obj.httpMetadata?.contentType || "application/octet-stream" } });
  }
  throw new ErrorPublico("Ruta desconocida.", 404);
}
