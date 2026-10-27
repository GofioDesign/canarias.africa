/// <reference path="../pb_data/types.d.ts" />
// Rutas públicas de canarias.africa. Borrador sin probar: revisar con la versión de PocketBase instalada.
//
// Regla clave: las coordenadas reales de obras sensibles nunca salen del servidor.
// La web pública solo lee /api/publico/obras; la colección «nodos» no tiene reglas de lectura públicas.

// Obras publicadas, con coordenadas ofuscadas según su sensibilidad.
routerAdd("GET", "/api/publico/obras", (e) => {
  const { obraPublica } = require(`${__hooks}/publico.js`);
  const obras = $app.findRecordsByFilter("nodos", "tipo = 'obra' && publicado = true", "codigo", 0, 0);
  return e.json(200, { type: "FeatureCollection", features: obras.map(obraPublica) });
});

// Ficha pública de una obra: datos publicables, relaciones con autoría y fuentes,
// y observaciones ya revisadas. Nunca incluye coordenadas sin ofuscar ni datos de contacto.
routerAdd("GET", "/api/publico/obras/{codigo}", (e) => {
  const { obraPublica } = require(`${__hooks}/publico.js`);
  let obra;
  try {
    obra = $app.findFirstRecordByFilter("nodos", "tipo = 'obra' && publicado = true && codigo = {:c}", { c: e.request.pathValue("codigo") });
  } catch (_) {
    return e.json(404, { error: "No existe esa obra." });
  }
  const relaciones = $app.findRecordsByFilter("relaciones", "origen = {:id} || destino = {:id}", "fecha", 0, 0, { id: obra.id });
  const observaciones = $app.findRecordsByFilter("observaciones", "obra = {:id} && estado_revision = 'aprobada'", "-created", 0, 0, { id: obra.id });
  const ficha = obraPublica(obra);
  ficha.properties.descripcion = obra.get("descripcion");
  ficha.properties.relaciones = relaciones.map((r) => ({
    tipo: r.get("tipo"), origen: r.get("origen"), destino: r.get("destino"),
    certeza: r.get("certeza"), fecha: r.get("fecha"), fuente: r.get("fuente"),
  }));
  ficha.properties.observaciones = observaciones.map((o) => ({
    fecha: o.get("created"), fase: o.get("fase"), texto: o.get("texto"), alias: o.get("alias"),
  }));
  return e.json(200, ficha);
});

// Un QR impreso apunta a https://canarias.africa/q/<código>. El código se puede rotar
// (por ejemplo, si una foto del QR circula fuera de la obra): basta con generar uno nuevo
// en la colección «qr» y reimprimir. Los códigos retirados dejan de aceptar observaciones.
routerAdd("GET", "/q/{codigo}", (e) => {
  const codigo = e.request.pathValue("codigo");
  let qr;
  try {
    qr = $app.findFirstRecordByFilter("qr", "codigo = {:c} && activo = true", { c: codigo });
  } catch (_) {
    return e.redirect(302, "/#/qr-no-valido");
  }
  const obra = $app.findRecordById("nodos", qr.get("obra"));
  return e.redirect(302, `/#/observar/${obra.get("codigo")}?qr=${encodeURIComponent(codigo)}`);
});

// Observaciones de estado: solo con un código de QR activo que corresponda a la obra.
onRecordCreateRequest((e) => {
  const codigo = e.record.get("qr_codigo");
  let qr;
  try {
    qr = $app.findFirstRecordByFilter("qr", "codigo = {:c} && activo = true", { c: codigo });
  } catch (_) {
    throw new BadRequestError("El código QR no es válido o ya no está activo.");
  }
  if (qr.get("obra") !== e.record.get("obra")) {
    throw new BadRequestError("El código QR no corresponde a esta obra.");
  }
  e.record.set("estado_revision", "pendiente");
  e.next();
}, "observaciones");

// Toda aportación ciudadana entra como pendiente de revisión y exige aceptar el aviso de privacidad.
onRecordCreateRequest((e) => {
  if (!e.record.get("acepta_privacidad")) {
    throw new BadRequestError("Hay que aceptar el aviso de privacidad para enviar el formulario.");
  }
  e.record.set("estado_revision", "pendiente");
  e.next();
}, "observaciones", "contexto", "propuestas_obra", "propuestas_ruta", "inscripciones");
