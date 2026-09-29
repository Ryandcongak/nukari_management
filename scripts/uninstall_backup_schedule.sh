#!/bin/sh
set -eu

LABEL=com.nukari.management.backup
TARGET_PLIST="$HOME/Library/LaunchAgents/$LABEL.plist"
USER_ID=$(id -u)

launchctl bootout "gui/$USER_ID/$LABEL" >/dev/null 2>&1 || true
rm -f "$TARGET_PLIST"
echo 'Jadwal backup harian dinonaktifkan. File backup yang sudah ada tidak dihapus.'
