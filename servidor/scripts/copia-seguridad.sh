#!/bin/sh
# Copia diaria cifrada con restic: base de datos de PocketBase, ficheros subidos,
# configuración y, si está instalado, la copia que genera el propio Discourse.
# Variables (servidor.env): RESTIC_REPOSITORY, RESTIC_PASSWORD, AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY
set -eu
export RESTIC_REPOSITORY RESTIC_PASSWORD AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY
PB=/srv/canarias.africa/pocketbase
INSTANTANEA=$PB/copias/data.db
mkdir -p "$PB/copias"
# Copia coherente de SQLite aunque PocketBase esté funcionando.
sqlite3 "$PB/pb_data/data.db" ".backup '$INSTANTANEA'"
restic backup --tag diaria \
  "$INSTANTANEA" "$PB/pb_data/storage" "$PB/pb_hooks" \
  /srv/canarias.africa/servidor.env /etc/nginx/sites-available \
  $( [ -d /var/discourse/shared/standalone/backups ] && echo /var/discourse/shared/standalone/backups )
restic forget --prune --keep-daily 7 --keep-weekly 5 --keep-monthly 12
