#!/bin/sh
set -eu

PROJECT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
PHP_BIN=${NUKARI_PHP_BIN:-/Applications/MAMP/bin/php/php8.2.0/bin/php}

cd "$PROJECT_DIR"

find . -path './vendor' -prune -o -name '*.php' -type f -print0 | xargs -0 -n1 "$PHP_BIN" -l >/dev/null
"$PHP_BIN" scripts/check_code_regressions.php
"$PHP_BIN" scripts/test_authorization_rules.php
"$PHP_BIN" scripts/test_attendance_rules.php
"$PHP_BIN" scripts/test_attendance_review_tracking.php
"$PHP_BIN" scripts/test_write_transactions.php
"$PHP_BIN" scripts/audit_attendance_consistency.php
"$PHP_BIN" scripts/audit_operational_integrity.php
"$PHP_BIN" scripts/audit_report_reconciliation.php
"$PHP_BIN" scripts/smoke_read_pages.php

echo 'Seluruh pemeriksaan kesehatan Nukari lulus.'
