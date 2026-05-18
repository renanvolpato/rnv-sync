#!/usr/bin/env bash
# Clean removal of a native RNV Sync install.
# Your synced files on disk are NEVER touched — only the app, its
# background services and the file-manager integration are removed.
set -euo pipefail

APP_DIR="${HOME}/.local/share/rnv-sync"
EXT_DIR="${HOME}/.local/share/nautilus-python/extensions"
ICON_DIR="${HOME}/.local/share/icons/hicolor/scalable/emblems"
CONF_DIR="${HOME}/.config/rnv-sync"

say() { printf '\033[1;36m==>\033[0m %s\n' "$1"; }

read -r -p "Remove RNV Sync (services, app, file-manager integration)? Your synced files are kept. [y/N] " ans
[ "${ans:-n}" = "y" ] || { echo "Aborted."; exit 0; }

say "Stopping and removing background services"
systemctl --user disable --now \
  rnv-sync-web rnv-sync-queue rnv-sync-scheduler rnv-sync-reverb rnv-sync-watch 2>/dev/null || true
rm -f "${HOME}"/.config/systemd/user/rnv-sync-*.service
rm -f "${HOME}"/.config/systemd/user/default.target.wants/rnv-sync-*.service
systemctl --user daemon-reload 2>/dev/null || true
systemctl --user reset-failed 2>/dev/null || true

# Drop linger if nothing else needs it (best-effort; harmless if denied).
say "Disabling background autostart (linger)"
loginctl disable-linger "$(whoami)" 2>/dev/null || true

say "Stopping any leftover rclone processes"
pkill -f "${APP_DIR}/rclone/rclone" 2>/dev/null || true

say "Removing the GNOME Files integration"
rm -f "${EXT_DIR}/rnv-sync.py"
rm -f "${EXT_DIR}"/__pycache__/rnv-sync.*.pyc
rm -f "${ICON_DIR}"/emblem-rnvsync-*.svg
gtk-update-icon-cache -f -t "${HOME}/.local/share/icons/hicolor" 2>/dev/null || true
nautilus -q 2>/dev/null || true

say "Removing the app and its local state"
rm -rf "${APP_DIR}" "${CONF_DIR}"

echo
echo "Removed. Your synced files on disk were left untouched."
echo "(If you no longer want them, delete that folder manually.)"
