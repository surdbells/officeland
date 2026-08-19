# syntax=docker/dockerfile:1
# OfficeLand API — Laravel 12 + Octane on FrankenPHP (worker mode)
# See docs/ARCHITECTURE.md section 1 and 9.

FROM dunglas/frankenphp:php8.3 AS base

# pdo_pgsql/pgsql : Postgres 16 via PgBouncer
# redis           : cache, queue, session, locks
# intl            : locale-correct sorting/formatting for en_NG
# opcache/pcntl   : required for Octane worker mode
# gd, exif        : interim image handling; libvips is added in Phase 4
RUN install-php-extensions \
        pdo_pgsql \
        pgsql \
        redis \
        intl \
        zip \
        opcache \
        pcntl \
        bcmath \
        gd \
        exif \
    && apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY infra/docker/frankenphp/entrypoint.sh /usr/local/bin/officeland-entrypoint
RUN chmod +x /usr/local/bin/officeland-entrypoint

WORKDIR /app

# Octane's FrankenPHP worker keeps the framework booted between requests.
ENV OCTANE_SERVER=frankenphp

# ---------------------------------------------------------------------------
# dev — source is bind-mounted; dependencies installed at build time so the
# image is usable before the first `composer install` on the host.
# ---------------------------------------------------------------------------
FROM base AS dev

COPY apps/api/composer.json apps/api/composer.lock ./
RUN composer install --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY apps/api/ ./
RUN composer dump-autoload --optimize --no-interaction

RUN mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache

EXPOSE 80 443
ENTRYPOINT ["/usr/local/bin/officeland-entrypoint"]

# ---------------------------------------------------------------------------
# prod — no dev dependencies, optimised autoloader, config cached at deploy.
# ---------------------------------------------------------------------------
FROM base AS prod

COPY apps/api/composer.json apps/api/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY apps/api/ ./
RUN composer dump-autoload --optimize --classmap-authoritative --no-interaction \
    && mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache

EXPOSE 80 443
ENTRYPOINT ["/usr/local/bin/officeland-entrypoint"]
