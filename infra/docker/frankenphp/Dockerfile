# syntax=docker/dockerfile:1
# OfficeLand API — Laravel 12 + Octane on FrankenPHP (worker mode)
# See docs/ARCHITECTURE.md section 1 and 9.

FROM dunglas/frankenphp:php8.3 AS base

# pdo_pgsql/pgsql : Postgres 16 via PgBouncer
# redis           : cache, queue, session, locks
# intl            : locale-correct sorting/formatting for en_NG
# opcache/pcntl   : required for Octane worker mode
# exif            : orientation, read before the pipeline strips metadata
# ffi             : php-vips binds libvips through FFI
#
# Imaging uses libvips, not GD or ImageMagick. Measured on a real 1024x1536
# product photo producing the full 5-variant x 3-format derivative set:
#
#   Imagick   4977 ms   1,414 KB
#   GD        1585 ms   1,306 KB
#   libvips   3371 ms     808 KB   (incl. 15 process startups)
#
# libvips output is ~40% smaller at equivalent quality settings. Generation is
# a one-time build cost; bytes are paid by every visitor on metered mobile data
# forever, and the PDP page-weight budget is 800 KB total (section 14.9).
# See docs/ARCHITECTURE.md section 8.
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
        ffi \
    && apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        # Media backups (Phase 13d). The media tree is content-addressed, so
        # rsync --ignore-existing turns a 50 GB+ nightly into a directory
        # comparison. Without it scripts/backup/media-backup.sh cannot run
        # where the volume is actually mounted.
        rsync \
        libvips42 \
        libheif1 \
        libheif-plugin-aomenc \
        libaom3 \
        # Generated brand-logo monograms (catalogue:brand-logos) render text
        # with GD's imagettftext, which needs a real TTF; pin one so it is there
        # whatever the base image ships.
        fonts-dejavu-core \
    && rm -rf /var/lib/apt/lists/*

# libvips42 alone can READ avif but not WRITE it: heifsave fails with
# "Unsupported compression" unless an AV1 encoder is present. AVIF is the first
# entry in the <picture> fallback chain and roughly 3.5x smaller than JPEG at
# equivalent quality, so losing it silently costs most of the 800 KB PDP
# page-weight budget while every test that only checks "a file was written"
# still passes. Hence libheif-plugin-aomenc.

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
