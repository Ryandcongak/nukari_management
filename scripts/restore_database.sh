#!/bin/sh
set -eu
if [ "$#" -ne 1 ] || [ ! -f "$1" ]; then echo "Pemakaian: $0 backups/database/nama_file.sql.gz"; exit 1; fi
MYSQL_BIN=${NUKARI_MYSQL_BIN:-/Applications/MAMP/Library/bin/mysql}
DB_HOST=${NUKARI_DB_HOST:-localhost}; DB_PORT=${NUKARI_DB_PORT:-8889}; DB_NAME=${NUKARI_DB_NAME:-nukari_management}; DB_USER=${NUKARI_DB_USER:-root}; DB_PASS=${NUKARI_DB_PASS:-root}
gzip -t "$1" || { echo 'Backup rusak atau bukan gzip yang valid.' >&2; exit 1; }
if [ -f "$1.sha256" ]; then
    BACKUP_HASH=$(shasum -a 256 "$1" | awk '{print $1}')
    EXPECTED_HASH=$(awk 'NR==1 {print $1}' "$1.sha256")
    [ "$BACKUP_HASH" = "$EXPECTED_HASH" ] || { echo 'Checksum backup tidak cocok.' >&2; exit 1; }
fi
echo "PERINGATAN: isi database $DB_NAME akan diperbarui dari $1"
printf 'Ketik RESTORE untuk melanjutkan: '; read answer
[ "$answer" = 'RESTORE' ] || { echo 'Dibatalkan.'; exit 1; }
gzip -dc "$1" | MYSQL_PWD="$DB_PASS" "$MYSQL_BIN" --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER" "$DB_NAME"
echo 'Restore selesai.'
