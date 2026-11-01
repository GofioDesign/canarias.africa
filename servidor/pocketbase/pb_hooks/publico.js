// Funciones compartidas por los hooks. PocketBase ejecuta cada hook aislado,
// así que se cargan con require(`${__hooks}/publico.js`) dentro de cada uno.

// Paso de la cuadrícula para obras con sensibilidad «difusa» (≈ 2 km en Canarias).
const PASO_DIFUSA = 0.02;

// Coordenadas que se pueden publicar según la sensibilidad de la obra.
// abierta → exactas · difusa → centro de su celda de la cuadrícula · reservada → ninguna (solo municipio).
function coordenadasPublicas(lat, lon, sensibilidad) {
  if (sensibilidad === "abierta") return { lat: lat, lon: lon, precision: "exacta" };
  if (sensibilidad === "difusa") {
    const centro = (v) => Math.floor(v / PASO_DIFUSA) * PASO_DIFUSA + PASO_DIFUSA / 2;
    return { lat: +centro(lat).toFixed(4), lon: +centro(lon).toFixed(4), precision: "zona" };
  }
  return null;
}

// Convierte un registro de la colección «nodos» de tipo obra en un Feature GeoJSON público.
function obraPublica(r) {
  const sens = r.get("sensibilidad") || "reservada";
  const c = coordenadasPublicas(r.get("lat"), r.get("lon"), sens);
  return {
    type: "Feature",
    geometry: c ? { type: "Point", coordinates: [c.lon, c.lat] } : null,
    properties: {
      id: r.get("codigo"),
      name: r.get("titulo"),
      fase: r.get("fase"),
      municipio: r.get("municipio"),
      sensibilidad: sens,
      precision: c ? c.precision : "municipio",
    },
  };
}

module.exports = { coordenadasPublicas, obraPublica };
