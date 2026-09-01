#!/bin/sh
set -eu

PROJECT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
BACKUP_DIR="$PROJECT_DIR/backups/database"
MYSQLDUMP_BIN=${NUKARI_MYSQLDUMP_BIN:-/Applications/MAMP/Library/bin/mysqldump}
DB_HOST=${NUKARI_DB_HOST:-localhost}
DB_PORT=${NUKARI_DB_PORT:-3306}
DB_NAME=${NUKARI_DB_NAME:-nukari_management}
DB_USER=${NUKARI_DB_USER:-root}
DB_PASS=${NUKARI_DB_PASS:-root}
STAMP=$(date '+%Y%m%d_%H%M%S')

mkdir -p "$BACKUP_DIR"
"$MYSQLDUMP_BIN" --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER" --password="$DB_PASS" --single-transaction --routines --triggers "$DB_NAME" | gzip > "$BACKUP_DIR/${DB_NAME}_${STAMP}.sql.gz"
find "$BACKUP_DIR" -type f -name '*.sql.gz' -mtime +30 -delete
echo "Backup tersimpan: $BACKUP_DIR/${DB_NAME}_${STAMP}.sql.gz"
