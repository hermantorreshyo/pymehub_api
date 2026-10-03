#!/usr/bin/env bash
# Copia diaria de Pyme Hub API (RNF-064): base de datos + uploads, rotación de 14.
# Uso (cron del usuario del proyecto): 0 2 * * * /var/www/pymehub-api/cli/backup.sh
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DEST="$ROOT/storage/backups"
STAMP="$(date -u +%Y%m%d-%H%M%S)"
mkdir -p "$DEST"
read -r HOST PORT NAME USER PASS < <(php -r '
  $c = require "'"$ROOT"'/config/config.php";
  echo $c["db"]["host"]," ",$c["db"]["port"]," ",$c["db"]["name"]," ",$c["db"]["user"]," ",$c["db"]["pass"];')
CNF="$(mktemp)"; chmod 600 "$CNF"
printf '[client]\nhost=%s\nport=%s\nuser=%s\npassword=%s\n' "$HOST" "$PORT" "$USER" "$PASS" > "$CNF"
trap 'rm -f "$CNF"' EXIT
mysqldump --defaults-extra-file="$CNF" --single-transaction --routines --no-tablespaces "$NAME" | gzip -9 > "$DEST/db-$STAMP.sql.gz"
tar -czf "$DEST/uploads-$STAMP.tar.gz" -C "$ROOT/storage" uploads
chmod 600 "$DEST"/*-"$STAMP".*
ls -1t "$DEST"/db-*.sql.gz | tail -n +15 | xargs -r rm -f
ls -1t "$DEST"/uploads-*.tar.gz | tail -n +15 | xargs -r rm -f
date -u +%s > "$DEST/ultima_copia.txt"
echo "Copia completada: $STAMP"
