#!/usr/bin/env bash
# Install the RNV Sync Nautilus extension (emblems + right-click menu).
# Requires the GNOME Files Python binding: python3-nautilus
#   Ubuntu/Debian/Pop: sudo apt-get install -y python3-nautilus
#   Fedora:            sudo dnf install -y nautilus-python
#   Arch:              sudo pacman -S --noconfirm python-nautilus
set -euo pipefail

cd "$(dirname "$0")/../.."
EXT_DIR="${HOME}/.local/share/nautilus-python/extensions"

if ! python3 -c "import gi; gi.require_version('Nautilus','4.0')" 2>/dev/null \
   && ! python3 -c "import gi; gi.require_version('Nautilus','3.0')" 2>/dev/null; then
  echo "python3-nautilus not found. Install it (see header) and re-run."
  exit 1
fi

mkdir -p "${EXT_DIR}"
cp install/nautilus/rnv-sync.py "${EXT_DIR}/rnv-sync.py"

# Install our custom emblem icons (blue cloud / green check / sync).
ICON_DIR="${HOME}/.local/share/icons/hicolor/scalable/emblems"
mkdir -p "${ICON_DIR}"
cp install/nautilus/icons/emblem-rnvsync-*.svg "${ICON_DIR}/"
if [ ! -f "${HOME}/.local/share/icons/hicolor/index.theme" ] \
   && [ -f /usr/share/icons/hicolor/index.theme ]; then
  cp /usr/share/icons/hicolor/index.theme "${HOME}/.local/share/icons/hicolor/index.theme"
fi
gtk-update-icon-cache -f -t "${HOME}/.local/share/icons/hicolor" 2>/dev/null || true

# Write the extension config (CLI path + account base dirs).
php artisan rnvsync:nautilus-config

# Reload Nautilus so the extension + icons are picked up.
nautilus -q 2>/dev/null || true

# --- self-check: tell the user whether emblems will actually work here ---
# (Pop!_OS is the usual trouble spot: COSMIC Files isn't Nautilus, and a
#  stale icon cache can hide custom emblems until the next login.)
emblems_resolve() {
  python3 - <<'PY' 2>/dev/null
import sys
import gi
gi.require_version("Gtk", "3.0")
from gi.repository import Gtk
it = Gtk.IconTheme.get_default()
names = ("emblem-rnvsync-cloud", "emblem-rnvsync-synced", "emblem-rnvsync-syncing")
sys.exit(0 if all(it.has_icon(n) for n in names) else 1)
PY
}

desktop="${XDG_CURRENT_DESKTOP:-}:${XDG_SESSION_DESKTOP:-}:${DESKTOP_SESSION:-}"
if printf '%s' "$desktop" | grep -qi cosmic; then
  echo "⚠  COSMIC desktop detected. The ☁/✓ emblems need GNOME Files (Nautilus);"
  echo "   the COSMIC file manager can't show extension emblems. Open your RNV Sync"
  echo "   folder in Nautilus (install it if needed) to see them."
elif emblems_resolve; then
  echo "✓  Emblem icons resolve in your icon theme."
else
  echo "⚠  Your icon theme can't resolve the RNV Sync emblems yet. Log out and back"
  echo "   in once (refreshes the icon cache) — that fixes it on most setups,"
  echo "   including Pop!_OS. If they still don't appear, your theme may not inherit"
  echo "   hicolor emblems."
fi

echo "Installed. Emblems appear in your RNV Sync folder; right-click for"
echo "Manter Local / Manter Online. If they don't show right away, log out and back"
echo "in once. Run 'php artisan rnvsync:nautilus-config' again after adding accounts."
