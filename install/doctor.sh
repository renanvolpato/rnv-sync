#!/usr/bin/env bash
# RNV Sync — desktop-integration doctor.
# Diagnoses why the tray icon and/or file-manager emblems aren't showing.
# Run it IN YOUR GRAPHICAL SESSION (a normal terminal), NOT over plain SSH:
#   bash install/doctor.sh
# Then paste the whole output.

echo "===== RNV Sync doctor ====="

echo
echo "-- Desktop / session --"
echo "XDG_CURRENT_DESKTOP = ${XDG_CURRENT_DESKTOP:-(unset)}"
echo "XDG_SESSION_DESKTOP = ${XDG_SESSION_DESKTOP:-(unset)}"
echo "DESKTOP_SESSION     = ${DESKTOP_SESSION:-(unset)}"
echo "XDG_SESSION_TYPE    = ${XDG_SESSION_TYPE:-(unset)}"
echo "DISPLAY=${DISPLAY:-(unset)}  WAYLAND_DISPLAY=${WAYLAND_DISPLAY:-(unset)}"

echo
echo "-- File managers installed --"
found_fm=""
for fm in nautilus nemo caja cosmic-files dolphin thunar pcmanfm pcmanfm-qt nemo-desktop; do
  if command -v "$fm" >/dev/null 2>&1; then
    echo "  $fm  ($(command -v "$fm"))"
    found_fm="${found_fm} ${fm}"
  fi
done
[ -z "$found_fm" ] && echo "  (none of the known managers found in PATH)"

echo
echo "-- Extension API (python bindings) --"
for pair in "Nautilus 4.0" "Nautilus 3.0" "Nemo 3.0" "Caja 2.0"; do
  set -- $pair
  if python3 -c "import gi; gi.require_version('$1','$2')" 2>/dev/null; then
    echo "  $1 $2: OK"
  else
    echo "  $1 $2: missing"
  fi
done

echo
echo "-- Extension installed? --"
for fm in nautilus nemo caja; do
  f="${HOME}/.local/share/${fm}-python/extensions/rnv-sync.py"
  if [ -f "$f" ]; then echo "  $fm: installed ($f)"; else echo "  $fm: not installed"; fi
done

echo
echo "-- Emblem icons resolve in the theme? --"
python3 - <<'PY' 2>/dev/null || echo "  (GTK icon check could not run — python3-gi/GTK3 missing?)"
import gi
gi.require_version("Gtk", "3.0")
from gi.repository import Gtk
it = Gtk.IconTheme.get_default()
print("  active icon theme:", Gtk.Settings.get_default().get_property("gtk-icon-theme-name"))
for n in ("emblem-rnvsync-cloud", "emblem-rnvsync-synced", "emblem-rnvsync-syncing"):
    print(f"  {n}: {'OK' if it.has_icon(n) else 'NOT FOUND'}")
PY

echo
echo "-- Tray --"
if pgrep -af "rnv-sync-tray.py" >/dev/null 2>&1; then
  echo "  tray process: RUNNING"
else
  echo "  tray process: not running"
fi
if python3 -c "import gi; gi.require_version('AyatanaAppIndicator3','0.1')" 2>/dev/null; then
  echo "  AppIndicator binding: AyatanaAppIndicator3 OK"
elif python3 -c "import gi; gi.require_version('AppIndicator3','0.1')" 2>/dev/null; then
  echo "  AppIndicator binding: AppIndicator3 OK"
else
  echo "  AppIndicator binding: MISSING (install gir1.2-ayatanaappindicator3-0.1)"
fi
# Vanilla GNOME hides tray icons unless a shell extension is enabled.
if printf '%s' "${XDG_CURRENT_DESKTOP:-}" | grep -qi gnome; then
  if command -v gnome-extensions >/dev/null 2>&1; then
    if gnome-extensions list --enabled 2>/dev/null | grep -qiE 'appindicator'; then
      echo "  GNOME appindicator shell extension: enabled"
    else
      echo "  GNOME appindicator shell extension: NOT enabled"
      echo "    → On vanilla GNOME the tray icon won't show without it. Install"
      echo "      'gnome-shell-extension-appindicator' and enable it (log out/in)."
    fi
  fi
fi

echo
echo "===== end — paste everything above ====="
