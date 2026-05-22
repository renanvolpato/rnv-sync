#!/usr/bin/env bash
# RNV Sync native installer (no root; systemd user services). SPEC §11.
set -euo pipefail

RCLONE_VERSION="1.67.0"
APP_DIR="${HOME}/.local/share/rnv-sync"
# Source of truth, in order of preference:
#  1. RNV_SYNC_REPO  — an explicit git URL (the public repo)
#  2. the local checkout this script lives in (offline / private use)
REPO="${RNV_SYNC_REPO:-}"
SRC="$(cd "$(dirname "$0")/.." && pwd)"

# If not told explicitly, inherit the upstream URL from the checkout
# this script came from, so the installed copy tracks the real remote
# (GitHub) and `update.sh` can `git pull` future releases. Only fall
# back to cloning the local path when there is no real remote.
if [ -z "${REPO}" ] && command -v git >/dev/null \
   && git -C "${SRC}" remote get-url origin >/dev/null 2>&1; then
  _u="$(git -C "${SRC}" remote get-url origin)"
  case "${_u}" in
    https://*|git@*|git://*|ssh://*) REPO="${_u}" ;;
  esac
fi

say() { printf '\033[1;36m==>\033[0m %s\n' "$1"; }

command -v php >/dev/null || { echo "PHP 8.3+ is required."; exit 1; }
command -v composer >/dev/null || { echo "Composer is required."; exit 1; }
command -v git >/dev/null || { echo "git is required."; exit 1; }
php -m | grep -qi pdo_sqlite || { echo "PHP pdo_sqlite extension is required."; exit 1; }

if [ "${SRC}" = "${APP_DIR}" ]; then
  say "Updating in place at ${APP_DIR}"
  cd "${APP_DIR}"
  git pull --ff-only 2>/dev/null || say "Not a tracked clone — skipping git pull."
elif [ -d "${APP_DIR}/.git" ]; then
  say "Updating existing install at ${APP_DIR}"
  git -C "${APP_DIR}" pull --ff-only 2>/dev/null || true
  cd "${APP_DIR}"
elif [ -n "${REPO}" ]; then
  say "Cloning ${REPO} into ${APP_DIR}"
  git clone "${REPO}" "${APP_DIR}"
  cd "${APP_DIR}"
else
  say "Installing from local checkout ${SRC} into ${APP_DIR}"
  git clone "${SRC}" "${APP_DIR}"
  cd "${APP_DIR}"
fi

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

say "Preparing the SQLite database"
DB_PATH="${APP_DIR}/database/database.sqlite"
mkdir -p "$(dirname "${DB_PATH}")"
touch "${DB_PATH}"
chmod 600 "${DB_PATH}"
# Absolute DB path: systemd runs artisan with an arbitrary CWD, and an
# empty/relative DB_DATABASE breaks the sqlite driver.
if grep -q '^DB_DATABASE=' .env; then
  sed -i "s#^DB_DATABASE=.*#DB_DATABASE=${DB_PATH}#" .env
else
  printf '\nDB_DATABASE=%s\n' "${DB_PATH}" >> .env
fi
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

# Make the layout obvious: the running app is the HIDDEN copy under
# ~/.local; the folder the user cloned to run this script is just the
# download. Leaving that visible next to ~/RnvSync is a common "why are
# there two folders?" confusion — point it out so they can remove it.
if [ "${SRC}" != "${APP_DIR}" ]; then
  say "App installed (hidden) at: ${APP_DIR}"
  case "${SRC}" in
    /tmp/*) : ;; # temp checkout — the install one-liner removes it
    *) say "This download folder is no longer needed — you can delete it: ${SRC}" ;;
  esac
fi

say "Done. Open http://localhost:8770"
# Give the web service a moment to bind before opening the browser.
for _ in 1 2 3 4 5 6 7 8 9 10; do
  curl -fsS -o /dev/null http://127.0.0.1:8770 2>/dev/null && break || sleep 1
done
command -v xdg-open >/dev/null && xdg-open http://localhost:8770 || true
