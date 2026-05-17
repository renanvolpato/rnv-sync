#!/usr/bin/env bash
set -e

cd /app

# Generate APP_KEY on first run (persisted to mounted storage/.env-key).
if [ ! -f storage/.env-key ]; then
    php artisan key:generate --show > storage/.env-key
fi
export APP_KEY="$(cat storage/.env-key)"

# Ensure SQLite database exists with strict permissions (SPEC §12).
touch "${DB_DATABASE:-/app/storage/database.sqlite}"
chmod 600 "${DB_DATABASE:-/app/storage/database.sqlite}"

php artisan migrate --force
php artisan config:cache
php artisan route:cache

chown -R www-data:www-data storage

# Hand off to supervisord (php-fpm + nginx + queue + reverb).
exec supervisord -c /etc/supervisord.conf
