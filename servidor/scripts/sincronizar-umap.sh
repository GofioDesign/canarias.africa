#!/bin/sh
# Descarga las capas públicas de uMap y las deja en /datos/umap/ de la web,
# para que la web no dependa de que uMap responda en cada visita.
# Variables (servidor.env): UMAP_SERVIDOR, UMAP_MAPA, UMAP_CAPA_OBRAS, UMAP_CAPA_QR, UMAP_CAPA_RUTAS
# Si una descarga falla se conserva la copia anterior.
set -eu
DESTINO=/srv/canarias.africa/web/datos/umap
mkdir -p "$DESTINO"
for par in "obras:${UMAP_CAPA_OBRAS:-}" "qr:${UMAP_CAPA_QR:-}" "rutas:${UMAP_CAPA_RUTAS:-}"; do
  nombre=${par%%:*}; capa=${par#*:}
  [ -n "$capa" ] || continue
  tmp=$(mktemp)
  if curl -fsS --max-time 30 -A "canarias.africa umap sync" \
       "$UMAP_SERVIDOR/datalayer/$UMAP_MAPA/$capa/" -o "$tmp" \
     && jq -e '.type == "FeatureCollection"' "$tmp" >/dev/null; then
    chmod 644 "$tmp"; mv "$tmp" "$DESTINO/$nombre.geojson"
  else
    rm -f "$tmp"; echo "uMap: no se pudo actualizar la capa $nombre" >&2
  fi
done
