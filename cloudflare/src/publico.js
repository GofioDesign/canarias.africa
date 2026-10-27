// Paso de la cuadrícula para obras con sensibilidad «difusa» (≈ 2 km en Canarias).
const PASO_DIFUSA = 0.02;

// Coordenadas que se pueden publicar según la sensibilidad de la obra.
// abierta → exactas · difusa → centro de su celda de la cuadrícula · reservada → ninguna (solo municipio).
export function coordenadasPublicas(lat, lon, sensibilidad) {
  if (lat == null || lon == null) return null;
  if (sensibilidad === "abierta") return { lat, lon, precision: "exacta" };
  if (sensibilidad === "difusa") {
    const centro = (v) => +(Math.floor(v / PASO_DIFUSA) * PASO_DIFUSA + PASO_DIFUSA / 2).toFixed(4);
    return { lat: centro(lat), lon: centro(lon), precision: "zona" };
  }
  return null;
}
