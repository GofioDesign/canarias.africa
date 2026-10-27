// Comprueba el token de Cloudflare Access (gratuito hasta 50 personas) que protege /admin y /api/admin.
// Variables: ACCESS_EQUIPO (p. ej. «canarias» para canarias.cloudflareaccess.com), ACCESS_AUD (Application Audience)
// y ADMINS (correos separados por comas).
const b64url = (s) => Uint8Array.from(atob(s.replace(/-/g, "+").replace(/_/g, "/")), (c) => c.charCodeAt(0));
let claves = null;

export async function usuarioAdmin(request, env) {
  const token = request.headers.get("Cf-Access-Jwt-Assertion");
  if (!token || !env.ACCESS_EQUIPO || !env.ACCESS_AUD) return null;
  const [h, c, firma] = token.split(".");
  const cabecera = JSON.parse(new TextDecoder().decode(b64url(h)));
  if (!claves) {
    const r = await fetch(`https://${env.ACCESS_EQUIPO}.cloudflareaccess.com/cdn-cgi/access/certs`);
    claves = (await r.json()).keys;
  }
  const jwk = claves.find((k) => k.kid === cabecera.kid);
  if (!jwk) { claves = null; return null; }
  const clave = await crypto.subtle.importKey("jwk", jwk, { name: "RSASSA-PKCS1-v1_5", hash: "SHA-256" }, false, ["verify"]);
  const valida = await crypto.subtle.verify("RSASSA-PKCS1-v1_5", clave, b64url(firma), new TextEncoder().encode(`${h}.${c}`));
  if (!valida) return null;
  const datos = JSON.parse(new TextDecoder().decode(b64url(c)));
  const aud = Array.isArray(datos.aud) ? datos.aud : [datos.aud];
  if (!aud.includes(env.ACCESS_AUD) || datos.exp * 1000 < Date.now()) return null;
  const admins = (env.ADMINS || "").split(",").map((s) => s.trim().toLowerCase());
  return admins.includes(String(datos.email).toLowerCase()) ? datos.email : null;
}
