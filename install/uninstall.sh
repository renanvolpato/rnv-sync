#!/usr/bin/env bash
# Clean removal of a native RNV Sync install. Keeps your synced files.
set -euo pipefail

APP_DIR="${HOME}/.local/share/rnv-sync"

read -r -p "Remove RNV Sync services and app at ${APP_DIR}? [y/N] " ans
[ "${ans:-n}" = "y" ] || { echo "Aborted."; exit 0; }

systemctl --user disable --now rnv-sync-web rnv-sync-queue rnv-sync-reverb 2>/dev/null || true
rm -f "${HOME}"/.config/systemd/user/rnv-sync-*.service
systemctl --user daemon-reload 2>/dev/null || true

rm -rf "${APP_DIR}"

echo "Removed. Your synced files under ~/RnvSync were left untouched."
