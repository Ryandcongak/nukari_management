#!/bin/sh
set -eu

PROJECT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
BACKUP_DIR="$PROJECT_DIR/backups/database"
MYSQLDUMP_BIN=${NUKARI_MYSQLDUMP_BIN:-/Applications/MAMP/Library/bin/mysqldump}
DB_HOST=${NUKARI_DB_HOST:-localhost}
DB_PORT=${NUKARI_DB_PORT:-8889}
DB_NAME=${NUKARI_DB_NAME:-nukari_management}
DB_USER=${NUKARI_DB_USER:-root}
DB_PASS=${NUKARI_DB_PASS:-root}
STAMP=$(date '+%Y%m%d_%H%M%S')
BACKUP_FILE="$BACKUP_DIR/${DB_NAME}_${STAMP}.sql.gz"
CHECKSUM_FILE="$BACKUP_FILE.sha256"
TEMP_FILE="$BACKUP_DIR/.${DB_NAME}_${STAMP}.sql"
BACKUP_OK=0

mkdir -p "$BACKUP_DIR"
cleanup() {
    rm -f "$TEMP_FILE"
    if [ "$BACKUP_OK" -ne 1 ]; then
        rm -f "$BACKUP_FILE" "$CHECKSUM_FILE"
    fi
}
trap cleanup EXIT HUP INT TERM

MYSQL_PWD="$DB_PASS" "$MYSQLDUMP_BIN" \
    --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER" \
    --single-transaction --routines --triggers --events --hex-blob \
    "$DB_NAME" > "$TEMP_FILE"

[ -s "$TEMP_FILE" ] || { echo 'Backup gagal: hasil dump kosong.' >&2; exit 1; }
gzip -c "$TEMP_FILE" > "$BACKUP_FILE"
gzip -t "$BACKUP_FILE"
BACKUP_HASH=$(shasum -a 256 "$BACKUP_FILE" | awk '{print $1}')
printf '%s  %s\n' "$BACKUP_HASH" "$(basename "$BACKUP_FILE")" > "$CHECKSUM_FILE"
BACKUP_OK=1
cleanup
trap - EXIT HUP INT TERM
find "$BACKUP_DIR" -type f -name '*.sql.gz' -mtime +30 -delete
find "$BACKUP_DIR" -type f -name '*.sql.gz.sha256' -mtime +30 -delete
echo "Backup tersimpan: $BACKUP_FILE"
echo "Checksum tersimpan: $CHECKSUM_FILE"
