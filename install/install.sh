#!/usr/bin/env bash
# RNV Sync native installer (no root; systemd user services). SPEC §11.
set -euo pipefail

RCLONE_VERSION="1.67.0"
APP_DIR="${HOME}/.local/share/rnv-sync"
REPO="${RNV_SYNC_REPO:-https://github.com/<owner>/rnv-sync.git}"

say() { printf '\033[1;36m==>\033[0m %s\n' "$1"; }

command -v php >/dev/null || { echo "PHP 8.3+ is required."; exit 1; }
command -v composer >/dev/null || { echo "Composer is required."; exit 1; }
php -m | grep -qi pdo_sqlite || { echo "PHP pdo_sqlite extension is required."; exit 1; }

say "Cloning into ${APP_DIR}"
mkdir -p "${APP_DIR}"
if [ -d "${APP_DIR}/.git" ]; then
  git -C "${APP_DIR}" pull --ff-only
else
  git clone --depth 1 "${REPO}" "${APP_DIR}"
fi
cd "${APP_DIR}"

say "Installing PHP dependencies"
composer install --no-dev --no-interaction --prefer-dist

if command -v npm >/dev/null; then
  say "Building assets"
  npm ci && npm run build
fi

say "Downloading rclone ${RCLONE_VERSION}"
ARCH=$(uname -m | sed 's/x86_64/amd64/' | sed 's/aarch64/arm64/')
mkdir -p rclone
curl -fL "https://downloads.rclone.org/v${RCLONE_VERSION}/rclone-v${RCLONE_VERSION}-linux-${ARCH}.zip" -o /tmp/rclone.zip
unzip -o /tmp/rclone.zip -d /tmp >/dev/null
mv /tmp/rclone-*/rclone rclone/rclone
chmod +x rclone/rclone
rm -rf /tmp/rclone*

if [ ! -f .env ]; then
  say "Generating .env"
  cp .env.example .env
  php artisan key:generate
fi

touch storage/database.sqlite
chmod 600 storage/database.sqlite
php artisan migrate --force

say "Installing systemd user services"
mkdir -p "${HOME}/.config/systemd/user"
for unit in rnv-sync-web rnv-sync-queue rnv-sync-scheduler rnv-sync-reverb rnv-sync-watch; do
  sed "s|@APP_DIR@|${APP_DIR}|g; s|@PHP@|$(command -v php)|g" \
    "install/systemd/${unit}.service" > "${HOME}/.config/systemd/user/${unit}.service"
done
systemctl --user daemon-reload
systemctl --user enable --now \
  rnv-sync-web rnv-sync-queue rnv-sync-scheduler rnv-sync-reverb rnv-sync-watch

# Keep the services running after logout / across reboots, with no
# open browser or terminal. enable-linger for one's own user is
# allowed without root on logind systems; ignore failure otherwise.
say "Enabling background autostart (linger)"
loginctl enable-linger "$(whoami)" 2>/dev/null \
  || say "Could not enable linger automatically — services still run while logged in."

# GNOME Files integration (emblems + right-click Keep local / online).
# Best-effort: a missing python3-nautilus must not abort the install.
say "Installing the file-manager integration (optional)"
bash install/nautilus/install.sh \
  || say "File-manager integration skipped (install python3-nautilus, then re-run install/nautilus/install.sh)."

say "Installing the system-tray indicator (optional)"
bash install/tray/install.sh \
  || say "Tray indicator skipped (install python3-gi + gir1.2-ayatanaappindicator3-0.1, then re-run install/tray/install.sh)."

say "Done. Open http://localhost:8080"
# Give the web service a moment to bind before opening the browser.
for _ in 1 2 3 4 5 6 7 8 9 10; do
  curl -fsS -o /dev/null http://127.0.0.1:8080 2>/dev/null && break || sleep 1
done
command -v xdg-open >/dev/null && xdg-open http://localhost:8080 || true
