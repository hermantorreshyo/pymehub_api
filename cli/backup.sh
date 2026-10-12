#!/usr/bin/env bash
# Copia diaria de Pyme Hub API (RNF-064): base de datos + uploads, rotación de 14.
# Funciona con MariaDB (mariadb-dump) y MySQL (mysqldump) (DEC-05).
# Uso (cron del usuario del proyecto): 0 2 * * * /var/www/hermantorres/eude/pymehub/pymehub_api/cli/backup.sh
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DEST="$ROOT/storage/backups"
STAMP="$(date -u +%Y%m%d-%H%M%S)"
mkdir -p "$DEST"

# Primer binario de volcado disponible: mariadb-dump (Debian 13 / MariaDB) o mysqldump (MySQL)
DUMP="$(command -v mariadb-dump || command -v mysqldump || true)"
if [ -z "$DUMP" ]; then
  echo "Error: no se encuentra mariadb-dump ni mysqldump. Instala el cliente de la base de datos (p. ej. apt install mariadb-client)." >&2
  exit 1
fi

# Credenciales en un archivo temporal 600 (nunca en la línea de comandos); PHP lo escribe
# directamente para admitir contraseñas con espacios o caracteres especiales.
CNF="$(mktemp)"; chmod 600 "$CNF"
trap 'rm -f "$CNF"' EXIT
NAME="$(php -r '
  $c = require $argv[1];
  $d = $c["db"];
  $q = static fn ($v) => "\"" . addcslashes((string) $v, "\\\"") . "\"";
  $lines = ["[client]", "user=" . $q($d["user"]), "password=" . $q($d["pass"])];
  if (!empty($d["socket"])) { $lines[] = "socket=" . $q($d["socket"]); }
  else { $lines[] = "host=" . $q($d["host"]); $lines[] = "port=" . (int) ($d["port"] ?? 3306); }
  file_put_contents($argv[2], implode("\n", $lines) . "\n");
  echo $d["name"];' "$ROOT/config/config.php" "$CNF")"

"$DUMP" --defaults-extra-file="$CNF" --single-transaction --routines --no-tablespaces "$NAME" | gzip -9 > "$DEST/db-$STAMP.sql.gz"
tar -czf "$DEST/uploads-$STAMP.tar.gz" -C "$ROOT/storage" uploads
chmod 600 "$DEST"/*-"$STAMP".*
ls -1t "$DEST"/db-*.sql.gz | tail -n +15 | xargs -r rm -f
ls -1t "$DEST"/uploads-*.tar.gz | tail -n +15 | xargs -r rm -f
date -u +%s > "$DEST/ultima_copia.txt"
echo "Copia completada con $(basename "$DUMP"): $STAMP"
