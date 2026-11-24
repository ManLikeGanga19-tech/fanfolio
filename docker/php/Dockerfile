# syntax=docker/dockerfile:1.7
# One image, several process types: web (Octane), horizon, scheduler, reverb, media-worker.

FROM php:8.4-cli-bookworm AS base

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_pgsql redis swoole pcntl posix intl bcmath zip opcache sodium exif gd \
    && apt-get update \
    && apt-get install -y --no-install-recommends postgresql-client unzip git \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-fanfolio.ini

WORKDIR /var/www/html
RUN useradd --create-home --uid 1000 app

# ---- local development: code is bind-mounted; pcov for coverage gates ----
FROM base AS dev
RUN install-php-extensions pcov
# Node runs Octane's file watcher (--watch, chokidar from the project's node_modules).
RUN apt-get update \
    && apt-get install -y --no-install-recommends nodejs \
    && rm -rf /var/lib/apt/lists/*
COPY docker/php/entrypoint.sh /usr/local/bin/fanfolio-entrypoint
RUN chmod +x /usr/local/bin/fanfolio-entrypoint
ENTRYPOINT ["fanfolio-entrypoint"]
# --watch reloads the workers when PHP, config, routes or .env change.
CMD ["php", "artisan", "octane:start", "--server=swoole", "--host=0.0.0.0", "--port=8000", "--watch"]

# ---- PHP packages the asset build reads (the admin theme imports Filament's CSS) ----
FROM composer:2 AS vendor
WORKDIR /build
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist --ignore-platform-reqs

# ---- front-end assets (Vite + Tailwind), built once for the production image ----
FROM node:22-alpine AS assets
WORKDIR /build
COPY package.json package-lock.json ./
RUN npm ci --ignore-scripts
COPY vite.config.js ./
COPY --from=vendor /build/vendor ./vendor
COPY resources ./resources
COPY app ./app
RUN npm run build

# ---- production: code baked in, no dev dependencies ----
FROM base AS prod
ENV APP_ENV=production
COPY --chown=app:app . .
COPY --from=assets --chown=app:app /build/public/build ./public/build
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --classmap-authoritative \
    && rm -rf tests docs infra .github
USER app
EXPOSE 8000
CMD ["php", "artisan", "octane:start", "--server=swoole", "--host=0.0.0.0", "--port=8000"]
