#!/bin/sh
set -eu

mkdir -p \
    /tmp/laravel/storage/framework/cache \
    /tmp/laravel/storage/framework/sessions \
    /tmp/laravel/storage/framework/views \
    /tmp/laravel/storage/logs \
    /tmp/laravel/storage/app/private/temporary \
    /tmp/laravel/storage/app/private/audio/impulse \
    /tmp/laravel/bootstrap/cache

chown -R www-data:www-data /tmp/laravel

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    attempt=1
    until php artisan migrate --force; do
        if [ "$attempt" -ge 10 ]; then
            echo "Database migrations failed after ${attempt} attempts." >&2
            exit 1
        fi

        attempt=$((attempt + 1))
        sleep 3
    done
fi

php artisan package:discover --ansi
php artisan config:cache
php artisan view:cache

php-fpm -D -y /usr/local/etc/php-fpm-global.conf

exec nginx -g 'daemon off;'
