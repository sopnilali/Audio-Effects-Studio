# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# Front-end assets
# ---------------------------------------------------------------------------
FROM node:22-alpine AS assets

WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY . .
RUN npm run build

# ---------------------------------------------------------------------------
# Composer dependencies
# ---------------------------------------------------------------------------
FROM composer:2 AS vendor

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    --no-scripts \
    --no-autoloader \
    --ignore-platform-req=php

COPY . .
RUN composer dump-autoload \
    --no-dev \
    --optimize \
    --no-interaction \
    --no-scripts \
    --ignore-platform-req=php

# ---------------------------------------------------------------------------
# Runtime: PHP-FPM + Nginx + FFmpeg
# ---------------------------------------------------------------------------
FROM php:8.3-fpm-bookworm

ENV DEBIAN_FRONTEND=noninteractive \
    LARAVEL_STORAGE_PATH=/tmp/laravel/storage \
    APP_CONFIG_CACHE=/tmp/laravel/bootstrap/cache/config.php \
    APP_ROUTES_CACHE=/tmp/laravel/bootstrap/cache/routes-v7.php \
    APP_EVENTS_CACHE=/tmp/laravel/bootstrap/cache/events.php \
    APP_PACKAGES_CACHE=/tmp/laravel/bootstrap/cache/packages.php \
    APP_SERVICES_CACHE=/tmp/laravel/bootstrap/cache/services.php \
    VIEW_COMPILED_PATH=/tmp/laravel/storage/framework/views \
    LOG_CHANNEL=stderr

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        ffmpeg \
        git \
        libcurl4-openssl-dev \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        libpq-dev \
        libxml2-dev \
        libzip-dev \
        nginx \
        unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        curl \
        gd \
        intl \
        mbstring \
        opcache \
        pcntl \
        pdo_mysql \
        pdo_pgsql \
        xml \
        zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY --from=vendor /app/vendor ./vendor
COPY . .
COPY --from=assets /app/public/build ./public/build

COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/99-vercel.ini
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-vercel.conf
COPY docker/php-fpm-global.conf /usr/local/etc/php-fpm-global.conf
COPY docker/entrypoint.sh /usr/local/bin/vercel-entrypoint

RUN chmod +x /usr/local/bin/vercel-entrypoint \
    && mkdir -p \
        /tmp/laravel/bootstrap/cache \
        bootstrap/cache \
        storage/app/private/temporary \
        storage/framework/cache \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
    && php artisan package:discover --ansi \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/vercel-entrypoint"]
