#!/usr/bin/env bash
# RNV Sync one-command bootstrap. Idempotent: safe to re-run.
# Installs system prerequisites (incl. the PHP SQLite extension),
# PHP/JS deps, generates .env + APP_KEY, downloads rclone, migrates and
# builds assets. This is the command the web "requirements" screen and
# `php artisan rnvsync:doctor` point users to.
set -euo pipefail

cd "$(dirname "$0")/.."
RCLONE_VERSION="1.67.0"

say()  { printf '\033[1;36m==>\033[0m %s\n' "$1"; }
warn() { printf '\033[1;33m!  \033[0m %s\n' "$1"; }

# --- detect distro & package manager -------------------------------------
DISTRO="unknown"
[ -r /etc/os-release ] && DISTRO="$(. /etc/os-release; echo "${ID:-unknown}")"

# Privilege escalation: prefer a graphical prompt (pkexec) so the user
# just types their password in a dialog — no terminal needed. Fall back
# to sudo, then to nothing if already root.
SUDO=""
if [ "$(id -u)" -ne 0 ]; then
  if command -v pkexec >/dev/null 2>&1 && [ -n "${DISPLAY:-}${WAYLAND_DISPLAY:-}" ]; then
    SUDO="pkexec"
  else
    SUDO="sudo"
  fi
fi

install_sqlite_ext() {
  if php -m 2>/dev/null | grep -qi '^pdo_sqlite$'; then
    return 0
  fi
  say "Installing the PHP SQLite extension (distro: ${DISTRO})"
  case "${DISTRO}" in
    ubuntu|debian|pop|linuxmint)
      ${SUDO} apt-get update -y
      ${SUDO} apt-get install -y php8.3-sqlite3 ;;
    fedora|rhel|centos)
      ${SUDO} dnf install -y php-pdo ;;
    arch|manjaro)
      ${SUDO} pacman -S --noconfirm php-sqlite ;;
    alpine)
      ${SUDO} apk add --no-cache php83-pdo_sqlite php83-sqlite3 ;;
    *)
      warn "Unknown distro. Install the PHP pdo_sqlite extension manually." ;;
  esac
}

install_inotify() {
  if command -v inotifywait >/dev/null 2>&1; then
    return 0
  fi
  say "Installing inotify-tools (real-time upload on file change)"
  case "${DISTRO}" in
    ubuntu|debian|pop|linuxmint)
      ${SUDO} apt-get install -y inotify-tools ;;
    fedora|rhel|centos)
      ${SUDO} dnf install -y inotify-tools ;;
    arch|manjaro)
      ${SUDO} pacman -S --noconfirm inotify-tools ;;
    alpine)
      ${SUDO} apk add --no-cache inotify-tools ;;
    *)
      warn "Unknown distro. Install 'inotify-tools' for real-time sync." ;;
  esac
}

install_tray_deps() {
  if python3 -c "import gi; gi.require_version('AyatanaAppIndicator3','0.1')" 2>/dev/null \
     || python3 -c "import gi; gi.require_version('AppIndicator3','0.1')" 2>/dev/null; then
    return 0
  fi
  say "Installing the tray indicator deps (status icon next to the clock)"
  case "${DISTRO}" in
    ubuntu|debian|pop|linuxmint)
      ${SUDO} apt-get install -y python3-gi gir1.2-gtk-3.0 gir1.2-ayatanaappindicator3-0.1 ;;
    fedora|rhel|centos)
      ${SUDO} dnf install -y python3-gobject gtk3 libayatana-appindicator-gtk3 ;;
    arch|manjaro)
      ${SUDO} pacman -S --noconfirm python-gobject gtk3 libayatana-appindicator ;;
    alpine)
      ${SUDO} apk add --no-cache py3-gobject3 gtk+3.0 libayatana-appindicator ;;
    *)
      warn "Unknown distro. Install python3-gi + ayatana-appindicator for the tray." ;;
  esac
}

require() { command -v "$1" >/dev/null 2>&1 || { warn "$1 is required but not found."; MISSING=1; }; }

MISSING=0
require php
require composer
[ "${MISSING}" -eq 1 ] && { echo "Install the missing tools above and re-run."; exit 1; }

install_sqlite_ext
install_inotify
install_tray_deps

if ! php -m 2>/dev/null | grep -qi '^pdo_sqlite$'; then
  warn "pdo_sqlite still not loaded. You may need to enable it in php.ini."
fi

say "Installing PHP dependencies"
composer install --no-interaction --prefer-dist

if command -v npm >/dev/null 2>&1; then
  say "Installing & building frontend assets"
  npm install
  npm run build
else
  warn "npm not found — skipping asset build (install Node 20+ to build the UI)."
fi

if [ ! -f .env ]; then
  say "Creating .env"
  cp .env.example .env
fi

if ! grep -q '^APP_KEY=base64:' .env; then
  say "Generating APP_KEY"
  php artisan key:generate --force
fi

say "Preparing the SQLite database"
DB_PATH="$(pwd)/database/database.sqlite"
mkdir -p "$(dirname "${DB_PATH}")"
touch "${DB_PATH}"
chmod 600 "${DB_PATH}"

say "Running migrations"
php artisan migrate --force

if [ ! -x rclone/rclone ]; then
  say "Downloading rclone ${RCLONE_VERSION}"
  ARCH=$(uname -m | sed 's/x86_64/amd64/' | sed 's/aarch64/arm64/')
  mkdir -p rclone
  curl -fL "https://downloads.rclone.org/v${RCLONE_VERSION}/rclone-v${RCLONE_VERSION}-linux-${ARCH}.zip" -o /tmp/rnv-rclone.zip
  unzip -o /tmp/rnv-rclone.zip -d /tmp >/dev/null
  mv /tmp/rclone-*/rclone rclone/rclone
  chmod +x rclone/rclone
  rm -rf /tmp/rnv-rclone.zip /tmp/rclone-*
fi

php artisan config:clear >/dev/null 2>&1 || true

say "Done. Start the app with:  php artisan serve --port=8080"
say "Then open http://localhost:8080"
