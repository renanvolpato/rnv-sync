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

# Write the extension config (CLI path + account base dirs).
php artisan rnvsync:nautilus-config

# Reload Nautilus so the extension is picked up.
nautilus -q 2>/dev/null || true

echo "Installed. Emblems appear in your RNV Sync folder; right-click for"
echo "Baixar / Liberar espaço. Run 'php artisan rnvsync:nautilus-config'"
echo "again after adding accounts."
