# syntax=docker/dockerfile:1.7

# ------------------------------------------------------------------------------
# Stage 1: Install production PHP dependencies & generate Wayfinder routes
# Runs natively on $BUILDPLATFORM (zero QEMU overhead for multi-arch builds).
# ------------------------------------------------------------------------------
FROM --platform=$BUILDPLATFORM serversideup/php:8.5-cli AS wayfinder

USER root
ENV COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader \
    --no-scripts

COPY artisan ./
COPY app ./app
COPY bootstrap ./bootstrap
COPY config ./config
COPY database ./database
COPY public ./public
COPY resources ./resources
COPY routes ./routes
COPY storage ./storage

RUN mkdir -p \
        bootstrap/cache \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
    && APP_ENV=production APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= \
        composer dump-autoload --no-dev --optimize --no-interaction \
    && APP_ENV=production APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= \
        php artisan wayfinder:generate --with-form --skip-database

# ------------------------------------------------------------------------------
# Stage 2: Build frontend assets natively on $BUILDPLATFORM
# Uses Wayfinder files generated in Stage 1 so Node needs no PHP runtime.
# ------------------------------------------------------------------------------
FROM --platform=$BUILDPLATFORM node:26-alpine AS assets

WORKDIR /app

COPY package.json package-lock.json .npmrc ./
RUN npm ci

COPY vite.config.ts tsconfig.json components.json ./
COPY public ./public
COPY resources ./resources
COPY --from=wayfinder /app/resources/js/actions ./resources/js/actions
COPY --from=wayfinder /app/resources/js/routes ./resources/js/routes
COPY --from=wayfinder /app/resources/js/wayfinder ./resources/js/wayfinder
COPY --from=wayfinder /app/vendor/laravel/framework/src/Illuminate/Pagination/resources/views ./vendor/laravel/framework/src/Illuminate/Pagination/resources/views

RUN SKIP_WAYFINDER=true npm run build

# ------------------------------------------------------------------------------
# Stage 3: Final production runtime (Nginx + PHP-FPM + s6 queue & scheduler)
# ------------------------------------------------------------------------------
FROM serversideup/php:8.5-fpm-nginx AS runtime

LABEL org.opencontainers.image.title="Splitline" \
      org.opencontainers.image.description="Local-first, self-hosted personal endurance and health telemetry platform." \
      org.opencontainers.image.source="https://github.com/jpgnz/splitline" \
      org.opencontainers.image.licenses="MIT"

USER root

RUN install-php-extensions zip intl bcmath pcntl pdo_sqlite \
    && apt-get update \
    && apt-get install -y --no-install-recommends sqlite3 \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY --chown=www-data:www-data artisan composer.json composer.lock ./
COPY --chown=www-data:www-data app ./app
COPY --chown=www-data:www-data bootstrap ./bootstrap
COPY --chown=www-data:www-data config ./config
COPY --chown=www-data:www-data database ./database
COPY --chown=www-data:www-data public ./public
COPY --chown=www-data:www-data resources ./resources
COPY --chown=www-data:www-data routes ./routes
COPY --chown=www-data:www-data storage ./storage

COPY --from=wayfinder --chown=www-data:www-data /app/vendor ./vendor
COPY --from=assets --chown=www-data:www-data /app/public/build ./public/build

RUN mkdir -p \
        /data \
        /var/www/html/bootstrap/cache \
        /var/www/html/storage/app/private \
        /var/www/html/storage/app/public \
        /var/www/html/storage/framework/cache/data \
        /var/www/html/storage/framework/sessions \
        /var/www/html/storage/framework/views \
        /var/www/html/storage/logs \
    && chown -R www-data:www-data /data /var/www/html/bootstrap/cache /var/www/html/storage

COPY --chmod=755 deploy/docker/entrypoint.d/99-splitline-init.sh /etc/entrypoint.d/99-splitline-init.sh
COPY deploy/docker/s6-rc.d/queue-worker /etc/s6-overlay/s6-rc.d/queue-worker
COPY deploy/docker/s6-rc.d/scheduler /etc/s6-overlay/s6-rc.d/scheduler
RUN chmod 755 \
        /etc/s6-overlay/s6-rc.d/queue-worker/run \
        /etc/s6-overlay/s6-rc.d/scheduler/run \
    && mkdir -p /etc/s6-overlay/user-bundles.d/user/contents.d \
    && touch \
        /etc/s6-overlay/user-bundles.d/user/contents.d/queue-worker \
        /etc/s6-overlay/user-bundles.d/user/contents.d/scheduler

USER www-data

RUN APP_ENV=production APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= \
    php artisan package:discover --ansi

ENV APP_NAME=Splitline \
    APP_ENV=production \
    APP_DEBUG=false \
    PHP_OPCACHE_ENABLE=1 \
    DB_CONNECTION=sqlite \
    DB_DATABASE=/data/database.sqlite \
    DB_SQLITE_JOURNAL_MODE=WAL \
    DB_SQLITE_BUSY_TIMEOUT=5000 \
    SESSION_DRIVER=database \
    CACHE_STORE=database \
    QUEUE_CONNECTION=database \
    LOG_CHANNEL=stderr

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD curl -fsS http://127.0.0.1:8080/up > /dev/null || exit 1
