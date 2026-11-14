#!/usr/bin/env bash
# Daily compressed MySQL backup with 14-day retention for Linux / cPanel cron
# Example crontab (02:00 daily):
#   0 2 * * * /path/to/repo/cron/backup_db.sh >> /path/to/repo/logs/backup.log 2>&1

set -euo pipefail

cd "$(dirname "$0")/.."
REPO_ROOT="$(pwd)"
BACKUP_DIR="${BACKUP_DIR:-$REPO_ROOT/backups}"
RETENTION_DAYS="${RETENTION_DAYS:-14}"
PHP_BIN="${PHP_BIN:-php}"
MYSQLDUMP_BIN="${MYSQLDUMP_BIN:-mysqldump}"

mkdir -p "$BACKUP_DIR"

if ! command -v "$PHP_BIN" >/dev/null 2>&1; then
  echo "[ERROR] php CLI tidak ditemukan." >&2
  exit 1
fi
if ! command -v "$MYSQLDUMP_BIN" >/dev/null 2>&1; then
  echo "[ERROR] mysqldump tidak ditemukan." >&2
  exit 1
fi

read -r DB_HOST DB_PORT DB_USER DB_NAME DB_PASS < <(
  "$PHP_BIN" -r '
    require "config/database.php";
    echo base64_encode(DB_HOST), " ",
         base64_encode((string)DB_PORT), " ",
         base64_encode(DB_USER), " ",
         base64_encode(DB_NAME), " ",
         base64_encode(DB_PASS), "\n";
  '
)

decode_b64() {
  printf '%s' "$1" | "$PHP_BIN" -r 'echo base64_decode(stream_get_contents(STDIN));'
}

HOST="$(decode_b64 "$DB_HOST")"
PORT="$(decode_b64 "$DB_PORT")"
USER="$(decode_b64 "$DB_USER")"
NAME="$(decode_b64 "$DB_NAME")"
PASS="$(decode_b64 "$DB_PASS")"

TIMESTAMP="$(date '+%Y%m%d_%H%M%S')"
OUT_FILE="$BACKUP_DIR/${NAME}_${TIMESTAMP}.sql.gz"
TMP_FILE="${OUT_FILE}.tmp"

cleanup() {
  rm -f "$TMP_FILE"
  unset MYSQL_PWD || true
}
trap cleanup EXIT

if [ -n "$PASS" ]; then
  export MYSQL_PWD="$PASS"
fi

"$MYSQLDUMP_BIN" \
  --host="$HOST" \
  --port="$PORT" \
  --user="$USER" \
  --single-transaction \
  --routines \
  --triggers \
  --hex-blob \
  --default-character-set=utf8mb4 \
  "$NAME" | gzip -c > "$TMP_FILE"

if [ ! -s "$TMP_FILE" ]; then
  echo "[ERROR] File backup kosong: $OUT_FILE" >&2
  exit 1
fi

mv "$TMP_FILE" "$OUT_FILE"
find "$BACKUP_DIR" -maxdepth 1 -type f \( -name '*.sql.gz' -o -name '*.sql' -o -name '*.zip' \) -mtime +"$RETENTION_DAYS" -delete

echo "[OK] $(date '+%Y-%m-%d %H:%M:%S') Backup tersimpan: $OUT_FILE ($(du -h "$OUT_FILE" | cut -f1))"
