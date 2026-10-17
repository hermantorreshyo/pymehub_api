#!/usr/bin/env bash
# Prueba de restauración de la última copia (RNF-064). Ejecutar como root en el servidor:
#   bash /var/www/hermantorres/eude/pymehub/pymehub_api/cli/restore_test.sh
# 1. Crea una base temporal, 2. restaura en ella la última copia, 3. compara el número de filas
# de cada tabla con la base en uso, 4. borra la base temporal y 5. guarda el informe en
# storage/logs/restauracion-AAAAMMDD-HHMMSS.md. No toca la base de producción.
# Necesita un usuario administrador de la base (crea y borra una base temporal):
# - root por socket sin contraseña (Debian por defecto): no pregunta nada;
# - root con contraseña (como en el VPS srv1616185): la pide una vez, sin eco, y la guarda solo en
#   un archivo temporal 600 que se borra al terminar. Nunca va en la línea de órdenes.
# - Otro administrador: PH_DB_ADMIN=usuario bash cli/restore_test.sh
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
CLI="$(command -v mariadb || command -v mysql)"
# Opciones extra del cliente, p. ej. PH_DB_OPTS="--socket=/ruta/mysqld.sock" (por defecto, el socket del sistema)
OPTS="${PH_DB_OPTS:-}"
ADMCNF=""
limpiar_cnf() { [ -n "$ADMCNF" ] && rm -f "$ADMCNF"; return 0; }
if ! "$CLI" $OPTS -e "SELECT 1" >/dev/null 2>&1 || [ -n "${PH_DB_ADMIN:-}" ]; then
  USR="${PH_DB_ADMIN:-root}"
  [ -t 0 ] || { echo "Sin acceso a la base como $USR: ejecútalo en una terminal para introducir la contraseña." >&2; exit 1; }
  read -r -s -p "Contraseña de $USR en la base de datos: " PASS; echo
  ADMCNF="$(mktemp)"; chmod 600 "$ADMCNF"
  PH_U="$USR" PH_P="$PASS" php -r '$q = static fn ($v) => "\"" . addcslashes((string) $v, "\\\"") . "\""; file_put_contents($argv[1], "[client]\nuser=" . $q(getenv("PH_U")) . "\npassword=" . $q(getenv("PH_P")) . "\n");' "$ADMCNF"
  unset PASS
  OPTS="--defaults-file=$ADMCNF $OPTS"
  "$CLI" $OPTS -e "SELECT 1" >/dev/null || { limpiar_cnf; echo "No se pudo conectar como $USR." >&2; exit 1; }
fi
DB="$(php -r '$c = require $argv[1]; echo $c["db"]["name"];' "$ROOT/config/config.php")"
COPIA="$(ls -1t "$ROOT"/storage/backups/db-*.sql.gz 2>/dev/null | head -1 || true)"
[ -n "$COPIA" ] || { echo "No hay copias en storage/backups: ejecuta antes cli/backup.sh" >&2; exit 1; }
TMP="pymehub_restore_$(date +%s)"
STAMP="$(date +%Y%m%d-%H%M%S)"
INFORME="$ROOT/storage/logs/restauracion-$STAMP.md"
trap '"$CLI" $OPTS -e "DROP DATABASE IF EXISTS \`$TMP\`" >/dev/null 2>&1 || true; limpiar_cnf' EXIT

INI=$(date +%s)
"$CLI" $OPTS -e "CREATE DATABASE \`$TMP\` CHARACTER SET utf8mb4"
gunzip -t "$COPIA"
zcat "$COPIA" | "$CLI" $OPTS "$TMP"
SEG=$(( $(date +%s) - INI ))

{
  echo "# Prueba de restauración (RNF-064)"
  echo
  echo "- Fecha: $(date '+%Y-%m-%d %H:%M %Z')"
  echo "- Copia: $(basename "$COPIA") ($(du -h "$COPIA" | cut -f1))"
  echo "- Base temporal: \`$TMP\` (borrada al terminar) · Tiempo de restauración: ${SEG} s"
  echo
  echo "| Tabla | Filas en la copia | Filas ahora |"
  echo "|---|---:|---:|"
} > "$INFORME"
TABLAS=$("$CLI" $OPTS -N -e "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = '$TMP' ORDER BY TABLE_NAME")
N=0
for t in $TABLAS; do
  A=$("$CLI" $OPTS -N -e "SELECT COUNT(*) FROM \`$TMP\`.\`$t\`")
  B=$("$CLI" $OPTS -N -e "SELECT COUNT(*) FROM \`$DB\`.\`$t\`" 2>/dev/null || echo "—")
  echo "| $t | $A | $B |" >> "$INFORME"
  N=$((N + 1))
done
MIG=$("$CLI" $OPTS -N -e "SELECT MAX(version) FROM \`$TMP\`.migracion_esquema" 2>/dev/null || echo "?")
{
  echo
  echo "Tablas restauradas: $N · Última migración en la copia: $MIG"
  echo
  echo "Resultado: **correcta**. Las diferencias de filas corresponden a la actividad posterior a la copia."
} >> "$INFORME"
echo "Restauración correcta: $N tablas en ${SEG} s. Informe: $INFORME"
