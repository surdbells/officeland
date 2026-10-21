#!/bin/sh
# OfficeLand API container entrypoint.
# Boots Laravel under the FrankenPHP worker defined in infra/caddy/Caddyfile.
set -eu

log() { printf '{"level":"info","service":"api-entrypoint","message":"%s"}\n' "$1"; }
err() { printf '{"level":"error","service":"api-entrypoint","message":"%s"}\n' "$1" >&2; }

# ---------------------------------------------------------------------------
# Wait for PgBouncer. Migrations connect direct to Postgres (docs/ARCHITECTURE
# section 5), but the application always goes through the pooler, so the
# pooler is what readiness depends on.
# ---------------------------------------------------------------------------
# The probe uses PHP rather than nc: the FrankenPHP base image is Debian and
# ships no netcat, and `until nc -z ...` fails open — a missing binary returns
# non-zero forever, so the loop times out and the cause looks like an unreachable
# dependency rather than a missing tool. PHP is guaranteed present here.
wait_for() {
    host="$1"; port="$2"; name="$3"; tries=0
    until php -r 'exit(@fsockopen($argv[1], (int) $argv[2], $e, $s, 1) ? 0 : 1);' "$host" "$port" 2>/dev/null; do
        tries=$((tries + 1))
        if [ "$tries" -ge 60 ]; then
            err "timed out waiting for ${name} at ${host}:${port}"
            exit 1
        fi
        sleep 1
    done
    log "${name} reachable at ${host}:${port}"
}

wait_for "${DB_HOST:-pgbouncer}" "${DB_PORT:-6432}" "pgbouncer"
wait_for "${REDIS_HOST:-redis}" "${REDIS_PORT:-6379}" "redis"

if [ ! -f /app/.env ]; then
    log ".env absent, seeding from .env.example"
    cp /app/.env.example /app/.env
fi

if ! grep -q '^APP_KEY=base64:' /app/.env 2>/dev/null; then
    log "generating APP_KEY"
    php artisan key:generate --force --no-interaction
fi

# Refresh the autoloader against the current source.
#
# Code lives on the bind mount (./apps/api), but vendor/ — and with it
# Composer's classmap — is a NAMED VOLUME that survives deploys and shadows the
# classmap the image build dumped. So a class added since that volume was last
# dumped (a new Filament resource, say) is absent from the classmap, and under
# an optimized/authoritative autoloader it never loads: the admin panel simply
# does not show it, and the route:cache below bakes that omission in. Dumping
# here, before anything boots the framework, keeps the classmap honest on every
# deploy without anyone having to remember.
if command -v composer >/dev/null 2>&1; then
    log "refreshing the autoloader"
    composer dump-autoload --optimize --no-interaction --working-dir=/app >/dev/null 2>&1 \
        || err "composer dump-autoload failed; new classes may be missing"
fi

# Octane holds the framework in memory, so stale cached config survives a
# deploy. Clearing on boot is cheap and removes a whole class of confusion.
php artisan config:clear --no-interaction >/dev/null 2>&1 || true

if [ "${APP_ENV:-local}" = "production" ]; then
    log "caching config, routes and events"
    php artisan config:cache --no-interaction
    php artisan route:cache --no-interaction
    php artisan event:cache --no-interaction
fi

log "starting frankenphp (workers=${OCTANE_WORKERS:-4}, env=${APP_ENV:-local})"
exec frankenphp run --config /etc/caddy/Caddyfile
