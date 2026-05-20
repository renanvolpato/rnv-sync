# syntax=docker/dockerfile:1
# RNV Sync — multi-stage image (frontend assets + PHP runtime + rclone).

# ---- Stage 1: composer dependencies ----
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

# ---- Stage 2: frontend assets ----
FROM node:20-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json* ./
RUN npm install
COPY resources resources
COPY vite.config.js ./
COPY tailwind.config.js* ./
COPY artisan ./
COPY app app
# resources/css/app.css both @imports vendor/livewire/flux/dist/flux.css
# and @sources blade stubs in vendor/livewire/flux/stubs — Tailwind v4
# needs both at build time. Copy the whole (no-dev, lean) vendor tree
# in before Vite builds.
COPY --from=vendor /app/vendor ./vendor
RUN npm run build

# ---- Stage 3: rclone binary (pinned) ----
FROM alpine:3.20 AS rclone
ARG RCLONE_VERSION=1.67.0
RUN apk add --no-cache curl unzip && \
    ARCH=$(uname -m | sed 's/x86_64/amd64/' | sed 's/aarch64/arm64/') && \
    curl -fL "https://downloads.rclone.org/v${RCLONE_VERSION}/rclone-v${RCLONE_VERSION}-linux-${ARCH}.zip" -o /tmp/rclone.zip && \
    unzip /tmp/rclone.zip -d /tmp && \
    mv /tmp/rclone-*/rclone /usr/local/bin/rnvsync-rclone && \
    chmod +x /usr/local/bin/rnvsync-rclone && \
    rm -rf /tmp/rclone*

# ---- Stage 4: runtime ----
FROM php:8.3-fpm-alpine AS runtime

RUN apk add --no-cache \
        nginx supervisor fuse3 bash sqlite-libs \
        $PHPIZE_DEPS sqlite-dev && \
    docker-php-ext-install pdo pdo_sqlite pcntl && \
    apk del $PHPIZE_DEPS

WORKDIR /app

COPY . .
COPY --from=vendor /app/vendor ./vendor
COPY --from=assets /app/public/build ./public/build
COPY --from=rclone /usr/local/bin/rnvsync-rclone ./rclone/rclone
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/entrypoint.sh /entrypoint.sh

RUN composer dump-autoload --optimize --no-dev && \
    chmod +x /entrypoint.sh ./rclone/rclone && \
    mkdir -p storage/rclone/cache storage/framework/{cache,sessions,views} && \
    chown -R www-data:www-data storage bootstrap/cache

ENV APP_ENV=production \
    RCLONE_BINARY_PATH=/app/rclone/rclone \
    DB_DATABASE=/app/storage/database.sqlite

EXPOSE 8770
ENTRYPOINT ["/entrypoint.sh"]
