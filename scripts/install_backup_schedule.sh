#!/bin/sh
set -eu

PROJECT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
LABEL=com.nukari.management.backup
SOURCE_PLIST="$PROJECT_DIR/ops/$LABEL.plist"
LAUNCH_AGENT_DIR="$HOME/Library/LaunchAgents"
TARGET_PLIST="$LAUNCH_AGENT_DIR/$LABEL.plist"
USER_ID=$(id -u)

mkdir -p "$LAUNCH_AGENT_DIR" "$PROJECT_DIR/backups/logs"
cp "$SOURCE_PLIST" "$TARGET_PLIST"
chmod 0644 "$TARGET_PLIST"
launchctl bootout "gui/$USER_ID/$LABEL" >/dev/null 2>&1 || true
launchctl bootstrap "gui/$USER_ID" "$TARGET_PLIST"
launchctl enable "gui/$USER_ID/$LABEL"

echo "Backup harian aktif pukul 18:00 waktu lokal."
echo "LaunchAgent: $TARGET_PLIST"
