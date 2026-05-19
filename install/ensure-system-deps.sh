#!/usr/bin/env bash
# System-level dependencies for RNV Sync (PHP SQLite, inotify-tools,
# tray indicator libs, file-manager bridge). Sourced/called by BOTH
# bootstrap.sh (first install) and update.sh (so a click on
# "Atualizar agora" can install any NEW dependency a release added
# — at most one pkexec password prompt).
#
# Idempotent: each install_* function checks first and returns early
# if the dep is already present. Safe to run on every update.
set -euo pipefail

say()  { printf '\033[1;36m==>\033[0m %s\n' "$1"; }
warn() { printf '\033[1;33m!  \033[0m %s\n' "$1"; }

DISTRO="unknown"
[ -r /etc/os-release ] && DISTRO="$(. /etc/os-release; echo "${ID:-unknown}")"

# Graphical password prompt (pkexec) so no terminal is required.
SUDO=""
if [ "$(id -u)" -ne 0 ]; then
  if command -v pkexec >/dev/null 2>&1 && [ -n "${DISPLAY:-}${WAYLAND_DISPLAY:-}" ]; then
    SUDO="pkexec"
  else
    SUDO="sudo"
  fi
fi

install_sqlite_ext() {
  php -m 2>/dev/null | grep -qi '^pdo_sqlite$' && return 0
  say "Installing the PHP SQLite extension (distro: ${DISTRO})"
  case "${DISTRO}" in
    ubuntu|debian|pop|linuxmint) ${SUDO} apt-get update -y; ${SUDO} apt-get install -y php8.3-sqlite3 ;;
    fedora|rhel|centos) ${SUDO} dnf install -y php-pdo ;;
    arch|manjaro) ${SUDO} pacman -S --noconfirm php-sqlite ;;
    alpine) ${SUDO} apk add --no-cache php83-pdo_sqlite php83-sqlite3 ;;
    *) warn "Unknown distro. Install the PHP pdo_sqlite extension manually." ;;
  esac
}

install_inotify() {
  command -v inotifywait >/dev/null 2>&1 && return 0
  say "Installing inotify-tools (real-time upload on file change)"
  case "${DISTRO}" in
    ubuntu|debian|pop|linuxmint) ${SUDO} apt-get install -y inotify-tools ;;
    fedora|rhel|centos) ${SUDO} dnf install -y inotify-tools ;;
    arch|manjaro) ${SUDO} pacman -S --noconfirm inotify-tools ;;
    alpine) ${SUDO} apk add --no-cache inotify-tools ;;
    *) warn "Unknown distro. Install 'inotify-tools' for real-time sync." ;;
  esac
}

install_tray_deps() {
  if python3 -c "import gi; gi.require_version('AyatanaAppIndicator3','0.1')" 2>/dev/null \
     || python3 -c "import gi; gi.require_version('AppIndicator3','0.1')" 2>/dev/null; then
    return 0
  fi
  say "Installing the tray indicator deps (status icon next to the clock)"
  case "${DISTRO}" in
    ubuntu|debian|pop|linuxmint) ${SUDO} apt-get install -y python3-gi gir1.2-gtk-3.0 gir1.2-ayatanaappindicator3-0.1 ;;
    fedora|rhel|centos) ${SUDO} dnf install -y python3-gobject gtk3 libayatana-appindicator-gtk3 ;;
    arch|manjaro) ${SUDO} pacman -S --noconfirm python-gobject gtk3 libayatana-appindicator ;;
    alpine) ${SUDO} apk add --no-cache py3-gobject3 gtk+3.0 libayatana-appindicator ;;
    *) warn "Unknown distro. Install python3-gi + ayatana-appindicator for the tray." ;;
  esac
}

install_nautilus_python() {
  if python3 -c "import gi; gi.require_version('Nautilus','4.0')" 2>/dev/null \
     || python3 -c "import gi; gi.require_version('Nautilus','3.0')" 2>/dev/null; then
    return 0
  fi
  say "Installing python3-nautilus (file-manager emblems)"
  case "${DISTRO}" in
    ubuntu|debian|pop|linuxmint) ${SUDO} apt-get install -y python3-nautilus ;;
    fedora|rhel|centos) ${SUDO} dnf install -y nautilus-python ;;
    arch|manjaro) ${SUDO} pacman -S --noconfirm python-nautilus ;;
    *) warn "Unknown distro. Install python3-nautilus / nautilus-python manually." ;;
  esac
}

# If sourced, the caller picks which to run. If executed directly,
# run them all (this is what update.sh uses).
if [ "${BASH_SOURCE[0]:-$0}" = "$0" ]; then
  install_sqlite_ext
  install_inotify
  install_tray_deps
  install_nautilus_python
fi
