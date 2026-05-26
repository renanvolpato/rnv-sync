#!/usr/bin/env bash
# Install the RNV Sync system-tray indicator (icon next to the clock,
# animated while syncing). Autostarts on login like AnyDesk. No root.
set -euo pipefail

cd "$(dirname "$0")/../.."
APP_DIR="$(pwd)"

ICON_DIR="${HOME}/.local/share/icons/hicolor/scalable/apps"
AUTOSTART="${HOME}/.config/autostart"

if ! python3 -c "import gi; gi.require_version('Gtk','3.0')" 2>/dev/null; then
  echo "python3-gi / GTK3 missing — tray skipped. Install python3-gi."
  exit 0
fi

mkdir -p "${ICON_DIR}" "${AUTOSTART}"
cp install/tray/icons/rnv-sync.svg "${ICON_DIR}/"
cp install/tray/icons/rnv-sync-sync-*.svg "${ICON_DIR}/"
if [ ! -f "${HOME}/.local/share/icons/hicolor/index.theme" ] \
   && [ -f /usr/share/icons/hicolor/index.theme ]; then
  cp /usr/share/icons/hicolor/index.theme "${HOME}/.local/share/icons/hicolor/index.theme"
fi
gtk-update-icon-cache -f -t "${HOME}/.local/share/icons/hicolor" 2>/dev/null || true

sed "s|@APP_DIR@|${APP_DIR}|g" install/tray/rnv-sync-tray.desktop \
  > "${AUTOSTART}/rnv-sync-tray.desktop"

# Start it now too (best-effort). The one-click updater runs detached from the
# web service, which may lack the graphical env — pull it from systemd --user
# (GNOME imports DISPLAY/WAYLAND there on login) so the icon comes back right
# after an update instead of only on next login.
if [ -z "${DISPLAY:-}${WAYLAND_DISPLAY:-}" ] && command -v systemctl >/dev/null 2>&1; then
  for _var in DISPLAY WAYLAND_DISPLAY DBUS_SESSION_BUS_ADDRESS XDG_RUNTIME_DIR; do
    _val="$(systemctl --user show-environment 2>/dev/null | sed -n "s/^${_var}=//p")"
    [ -n "${_val}" ] && export "${_var}=${_val}"
  done
fi

if [ -n "${DISPLAY:-}${WAYLAND_DISPLAY:-}" ]; then
  pkill -f "rnv-sync-tray.py" 2>/dev/null || true
  (python3 "${APP_DIR}/install/tray/rnv-sync-tray.py" >/dev/null 2>&1 &) || true
  echo "Tray (re)started — look next to the clock."
else
  echo "No graphical session detected — the tray appears on next login."
fi
