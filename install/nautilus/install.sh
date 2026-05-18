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

echo "Installed. Emblems appear in your RNV Sync folder; right-click for"
echo "Baixar / Liberar espaço. Run 'php artisan rnvsync:nautilus-config'"
echo "again after adding accounts."
