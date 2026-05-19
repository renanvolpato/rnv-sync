#!/usr/bin/env bash
# RNV Sync one-command bootstrap. Idempotent: safe to re-run.
# Installs system prerequisites (incl. the PHP SQLite extension),
# PHP/JS deps, generates .env + APP_KEY, downloads rclone, migrates and
# builds assets. This is the command the web "requirements" screen and
# `php artisan rnvsync:doctor` point users to.
set -euo pipefail

cd "$(dirname "$0")/.."
RCLONE_VERSION="1.67.0"

# System dependencies live in their own script so update.sh can call
# the same logic — one source of truth, one pkexec prompt at most.
# shellcheck disable=SC1091
. "$(dirname "$0")/ensure-system-deps.sh"

require() { command -v "$1" >/dev/null 2>&1 || { warn "$1 is required but not found."; MISSING=1; }; }

MISSING=0
require php
require composer
[ "${MISSING}" -eq 1 ] && { echo "Install the missing tools above and re-run."; exit 1; }

install_sqlite_ext
install_inotify
install_tray_deps
install_nautilus_python

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
# Pin an absolute DB path in .env: an empty/relative DB_DATABASE makes
# Laravel's sqlite driver fail with "unable to open database file".
if grep -q '^DB_DATABASE=' .env; then
  sed -i "s#^DB_DATABASE=.*#DB_DATABASE=${DB_PATH}#" .env
else
  printf '\nDB_DATABASE=%s\n' "${DB_PATH}" >> .env
fi

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

say "Done. Start the app with:  php artisan serve --port=8770"
say "Then open http://localhost:8770"
