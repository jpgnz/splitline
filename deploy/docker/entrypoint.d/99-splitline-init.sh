#!/bin/sh
set -eu

DATA_DIR="${SPLITLINE_DATA_DIR:-/data}"
DB_PATH="${DB_DATABASE:-${DATA_DIR}/database.sqlite}"
APP_KEY_FILE="${DATA_DIR}/.app_key"

for volume_dir in "$DATA_DIR" /var/www/html/storage/app/private; do
    if [ -d "$volume_dir" ] && [ "$(id -u)" -ne 0 ] && [ ! -w "$volume_dir" ]; then
        echo "[splitline] ERROR: ${volume_dir} is not writable by $(id -un) (uid $(id -u))." >&2
        echo "[splitline] Volumes created by Splitline v0.1.x (Debian image, uid 33) are incompatible with this Alpine-based image (uid 82)." >&2
        echo "[splitline] Recreate your Docker volumes and re-import your data. See the README section 'Upgrading from v0.1.x'." >&2
        exit 1
    fi
done

mkdir -p \
    "$DATA_DIR" \
    "$(dirname "$DB_PATH")" \
    /var/www/html/bootstrap/cache \
    /var/www/html/storage/app/private \
    /var/www/html/storage/app/public \
    /var/www/html/storage/framework/cache/data \
    /var/www/html/storage/framework/sessions \
    /var/www/html/storage/framework/views \
    /var/www/html/storage/logs

if [ ! -f "$DB_PATH" ]; then
    touch "$DB_PATH"
fi

if [ "$(id -u)" -eq 0 ]; then
    chown -R www-data:www-data \
        "$DATA_DIR" \
        /var/www/html/bootstrap/cache \
        /var/www/html/storage
fi

if [ -z "${APP_KEY:-}" ]; then
    if [ -s "$APP_KEY_FILE" ]; then
        APP_KEY="$(tr -d '\r\n' < "$APP_KEY_FILE")"
    else
        APP_KEY="$(php -r "echo 'base64:'.base64_encode(random_bytes(32));")"
        (
            umask 077
            printf '%s\n' "$APP_KEY" > "$APP_KEY_FILE"
        )
        chmod 600 "$APP_KEY_FILE"
        if [ "$(id -u)" -eq 0 ]; then
            chown www-data:www-data "$APP_KEY_FILE"
        fi
        echo "[splitline] Generated and persisted APP_KEY in ${APP_KEY_FILE}"
    fi
fi

export APP_KEY

if [ -d /run/s6/container_environment ] && [ -w /run/s6/container_environment ]; then
    printf '%s' "$APP_KEY" > /run/s6/container_environment/APP_KEY
fi

run_artisan() {
    if [ "$(id -u)" -eq 0 ]; then
        s6-setuidgid www-data php /var/www/html/artisan "$@"
    else
        php /var/www/html/artisan "$@"
    fi
}

run_artisan migrate --force --no-interaction
run_artisan config:cache --no-interaction
run_artisan route:cache --no-interaction
run_artisan view:cache --no-interaction
run_artisan routes:match --queue --no-interaction
