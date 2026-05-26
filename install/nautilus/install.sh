#!/usr/bin/env bash
# Install the RNV Sync file-manager extension (emblems + right-click menu) for
# every supported GTK file manager present: Nautilus (GNOME), Nemo (Cinnamon/
# Mint) and Caja (MATE). They share the nautilus-python API, so ONE extension
# serves all three. Needs the matching python binding:
#   Ubuntu/Debian/Pop/Mint: python3-nautilus / python3-nemo / python3-caja
#   Fedora:                  nautilus-python / nemo-python / caja-python
#   Arch:                    python-nautilus / python-nemo / python-caja
# COSMIC Files (Pop) and KDE Dolphin don't support this API.
set -euo pipefail

cd "$(dirname "$0")/../.."

fm_ns()   { case "$1" in nautilus) echo Nautilus;; nemo) echo Nemo;; caja) echo Caja;; esac; }
fm_vers() { case "$1" in nautilus) echo "4.0 3.0";; nemo) echo "3.0";; caja) echo "2.0";; esac; }

binding_ok() {
  local ns v
  ns="$(fm_ns "$1")"
  for v in $(fm_vers "$1"); do
    python3 -c "import gi; gi.require_version('${ns}','${v}')" 2>/dev/null && return 0
  done
  return 1
}

installed=()
for fm in nautilus nemo caja; do
  if binding_ok "$fm"; then
    dir="${HOME}/.local/share/${fm}-python/extensions"
    mkdir -p "${dir}"
    cp install/nautilus/rnv-sync.py "${dir}/rnv-sync.py"
    installed+=("$fm")
  fi
done

if [ ${#installed[@]} -eq 0 ]; then
  echo "No supported file-manager python binding found. Install one for your desktop:"
  echo "  GNOME    → python3-nautilus (apt) / nautilus-python (dnf) / python-nautilus (pacman)"
  echo "  Cinnamon → python3-nemo / nemo-python / python-nemo"
  echo "  MATE     → python3-caja / caja-python / python-caja"
  exit 1
fi

# Custom emblem icons (blue cloud / green check / sync arrows). All GTK file
# managers read the freedesktop hicolor theme, so one copy serves all three.
ensure_emblems_in() {
  # $1 = base icons dir (e.g., ~/.local/share/icons/hicolor OR ~/.icons/hicolor)
  local base="$1"
  local emblems="${base}/scalable/emblems"
  mkdir -p "${emblems}"
  cp install/nautilus/icons/emblem-rnvsync-*.svg "${emblems}/"

  # Bring (or seed) the hicolor index.theme so GTK actually scans this dir.
  local theme="${base}/index.theme"
  if [ ! -f "${theme}" ] && [ -f /usr/share/icons/hicolor/index.theme ]; then
    cp /usr/share/icons/hicolor/index.theme "${theme}"
  fi

  # GTK only scans directories listed in [scalable/emblems]; a truncated/older
  # index.theme without it silently hides our custom emblems even when present
  # (a common cross-distro/theme cause on Pop!_OS). Add the section if missing.
  if [ -f "${theme}" ] && ! grep -q '^\[scalable/emblems\]' "${theme}"; then
    if grep -q '^Directories=' "${theme}"; then
      grep -q '^Directories=.*scalable/emblems' "${theme}" \
        || sed -i 's|^\(Directories=.*\)|\1,scalable/emblems|' "${theme}"
    else
      printf '\nDirectories=scalable/emblems\n' >> "${theme}"
    fi
    cat >> "${theme}" <<'EOF'

[scalable/emblems]
Size=48
MinSize=8
MaxSize=512
Type=Scalable
Context=Emblems
EOF
  fi

  gtk-update-icon-cache -f -t "${base}" 2>/dev/null || true
}

ensure_emblems_in "${HOME}/.local/share/icons/hicolor"
ensure_emblems_in "${HOME}/.icons/hicolor"     # legacy fallback some setups read

# Extension config (CLI path + account base dirs) — shared by all file managers.
php artisan rnvsync:nautilus-config

# Reload each installed file manager so the extension + icons are picked up.
for fm in "${installed[@]}"; do "$fm" -q 2>/dev/null || true; done

echo "Installed for: ${installed[*]}"

# --- self-check: will emblems actually work here? (Pop!_OS is the trouble spot) ---
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
if printf '%s' "$desktop" | grep -qiE 'cosmic'; then
  echo "⚠  COSMIC desktop detected. Its file manager (COSMIC Files) can't show"
  echo "   extension emblems — install/use GNOME Files (Nautilus) to see ☁/✓."
elif printf '%s' "$desktop" | grep -qiE 'kde|plasma'; then
  echo "⚠  KDE detected. Dolphin doesn't use this emblem API — use a GTK file"
  echo "   manager (Nautilus/Nemo/Caja) to see ☁/✓."
fi

if emblems_resolve; then
  echo "✓  Emblem icons resolve in your icon theme."
else
  echo "⚠  Your icon theme can't resolve the RNV Sync emblems yet. Log out and"
  echo "   back in once (refreshes the icon cache) — that fixes it on most"
  echo "   setups, including Pop!_OS."
fi

echo "Done. Emblems appear in your RNV Sync folder; right-click for Manter Local /"
echo "Manter Online. If they don't show right away, log out and back in once."
echo "Run 'php artisan rnvsync:nautilus-config' again after adding accounts."
