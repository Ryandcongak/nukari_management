#!/bin/sh
set -eu

if [ "$#" -ne 1 ] || [ ! -f "$1" ]; then
    echo "Pemakaian: $0 backups/database/nama_file.sql.gz" >&2
    exit 1
fi

PROJECT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
BACKUP_FILE=$(CDPATH= cd -- "$(dirname -- "$1")" && pwd)/$(basename "$1")
MYSQL_BIN=${NUKARI_MYSQL_BIN:-/Applications/MAMP/Library/bin/mysql}
PHP_BIN=${NUKARI_PHP_BIN:-/Applications/MAMP/bin/php/php8.2.0/bin/php}
DB_HOST=${NUKARI_DB_HOST:-localhost}
DB_PORT=${NUKARI_DB_PORT:-8889}
DB_USER=${NUKARI_DB_USER:-root}
DB_PASS=${NUKARI_DB_PASS:-root}
VERIFY_DB="nukari_verify_$(date '+%Y%m%d_%H%M%S')_$$"

gzip -t "$BACKUP_FILE"
if [ -f "$BACKUP_FILE.sha256" ]; then
    BACKUP_HASH=$(shasum -a 256 "$BACKUP_FILE" | awk '{print $1}')
    EXPECTED_HASH=$(awk 'NR==1 {print $1}' "$BACKUP_FILE.sha256")
    [ "$BACKUP_HASH" = "$EXPECTED_HASH" ] || { echo 'Checksum backup tidak cocok.' >&2; exit 1; }
fi

cleanup() {
    MYSQL_PWD="$DB_PASS" "$MYSQL_BIN" --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER" \
        -e "DROP DATABASE IF EXISTS \`$VERIFY_DB\`" >/dev/null 2>&1 || true
}
trap cleanup EXIT HUP INT TERM

MYSQL_PWD="$DB_PASS" "$MYSQL_BIN" --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER" \
    -e "CREATE DATABASE \`$VERIFY_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
gzip -dc "$BACKUP_FILE" | MYSQL_PWD="$DB_PASS" "$MYSQL_BIN" \
    --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER" "$VERIFY_DB"

TABLE_COUNT=$(MYSQL_PWD="$DB_PASS" "$MYSQL_BIN" --batch --skip-column-names \
    --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER" \
    -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$VERIFY_DB' AND table_type='BASE TABLE'")
[ "$TABLE_COUNT" -gt 0 ] || { echo 'Verifikasi gagal: database hasil restore tidak memiliki tabel.' >&2; exit 1; }

NUKARI_DB_NAME="$VERIFY_DB" "$PHP_BIN" "$PROJECT_DIR/scripts/audit_attendance_consistency.php" >/dev/null
NUKARI_DB_NAME="$VERIFY_DB" "$PHP_BIN" "$PROJECT_DIR/scripts/audit_operational_integrity.php" >/dev/null

cleanup
trap - EXIT HUP INT TERM
echo "Verifikasi restore berhasil: $TABLE_COUNT tabel diuji pada database sementara dan sudah dibersihkan."
