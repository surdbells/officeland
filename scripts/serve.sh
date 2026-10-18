#!/usr/bin/env sh
#
# Bring the whole application up and serve it.
#
# ── Why a script and not "docker compose up" ──────────────────────────────
# `docker compose up` starts the containers and leaves you with a storefront
# that is empty or stale, because under D-009 nothing is rendered at request
# time: the site Caddy serves is a directory of static files that only exists
# after an Angular build has been published into it. Starting the stack and
# publishing the storefront are two different things, and forgetting the
# second is how you end up debugging a "missing" page that was simply never
# built.
#
# This does both, in the right order, and tells you which URLs are live.
#
# ── Usage ─────────────────────────────────────────────────────────────────
#   scripts/serve.sh                start; build only if nothing is published
#   scripts/serve.sh --rebuild      start and rebuild the storefront
#   scripts/serve.sh --no-build     start only; leave whatever is published
#   scripts/serve.sh --detach       do not tail logs; return to the prompt
#
# Safe to run repeatedly. Containers already up are left alone.
set -eu

cd "$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)"

BUILD=auto
TAIL=1

for arg in "$@"; do
    case "$arg" in
        --rebuild)  BUILD=force ;;
        --no-build) BUILD=never ;;
        --detach|-d) TAIL=0 ;;
        --help|-h)
            sed -n '3,25p' "$0" | sed 's/^# \{0,1\}//'
            exit 0
            ;;
        *)
            echo "Unknown option: $arg" >&2
            echo "Try --help." >&2
            exit 2
            ;;
    esac
done

log() { printf '\033[1;34m==>\033[0m %s\n' "$1"; }
warn() { printf '\033[1;33m!!\033[0m %s\n' "$1" >&2; }
die() { printf '\033[1;31mxx\033[0m %s\n' "$1" >&2; exit 1; }

# The HTTP status of a path on the running site, or 000 if nothing answers.
#
# Asked from inside the api container rather than the host: it is the same
# server either way, and it does not depend on the host having curl.
probe() {
    docker compose exec -T api sh -lc \
        "curl -s -o /dev/null -w '%{http_code}' http://localhost$1" 2>/dev/null || true
}

# Poll until a path answers 200, or give up.
#
# This exists because Caddy reloads its config on restart and takes a second
# or two to bind. Checking once, immediately after the restart, reported 000
# on a stack that was about to be perfectly healthy — the script's own first
# run said the site was down while it was serving.
wait_for_http() {
    attempts=0

    while [ "$attempts" -lt 30 ]; do
        if [ "$(probe "$1")" = "200" ]; then
            return 0
        fi

        attempts=$((attempts + 1))
        sleep 1
    done

    return 1
}

# ---------------------------------------------------------------------------
# Preconditions
# ---------------------------------------------------------------------------

command -v docker >/dev/null 2>&1 || die 'Docker is not on PATH.'

if [ ! -f .env ]; then
    # Secrets live in .env and .env is never committed, so a fresh clone has
    # no credentials at all. Copying the example gets the stack up with
    # development defaults; the third-party keys stay empty until somebody
    # fills them in, which is the intended state for a local machine.
    warn 'No .env — copying .env.example. Third-party keys will be empty.'
    cp .env.example .env
fi

HTTP_PORT="$(sed -n 's/^HTTP_PORT=//p' .env | tail -1)"
HTTP_PORT="${HTTP_PORT:-8095}"

# ---------------------------------------------------------------------------
# Containers
# ---------------------------------------------------------------------------

# Keep PgBouncer's userlist in step with DB_PASSWORD before anything starts, so
# a custom password does not fail auth against the committed dev-default hash.
log 'Syncing the PgBouncer userlist to DB_PASSWORD'
sh scripts/gen-userlist.sh

log 'Starting containers'

# --wait blocks until every service with a healthcheck reports healthy, which
# is what stops the migration step below racing Postgres. Without it the first
# run of the day fails on a connection refused roughly half the time.
docker compose up -d --wait

log 'Running migrations'
docker compose exec -T api php artisan migrate --force --no-interaction >/dev/null

# ---------------------------------------------------------------------------
# The storefront
# ---------------------------------------------------------------------------

published="$(docker compose exec -T render sh -lc 'ls /static/index.html 2>/dev/null | wc -l' | tr -d '[:space:]')"

if [ "$BUILD" = auto ] && [ "$published" = "0" ]; then
    log 'Nothing published yet — building the storefront'
    BUILD=force
fi

if [ "$BUILD" = force ]; then
    # Roughly two minutes for the full catalogue. The build reports the route
    # count; a sudden drop in it is the signal that something failed to
    # prerender rather than that the catalogue shrank.
    log 'Building (this takes a couple of minutes)'
    docker compose exec -T render sh -lc 'npm run build' | grep -E 'Prerendered|ERROR' || true

    # The build writes to dist/. Caddy serves /static. They are different
    # volumes on purpose — the swap has to be atomic, so a half-written build
    # is never served — which means publishing is an explicit step and not a
    # side effect of building.
    log 'Publishing to the static root'
    docker compose exec -T render sh -lc 'rsync -a --delete "$BUILD_OUTPUT"/ "$STATIC_ROOT"/'

    # The CSP names the hashes of the inline scripts Angular emits. Those
    # change with the build, and a stale hash serves a policy that blanks
    # every page — silently, because the HTML is still correct.
    log 'Regenerating the Content-Security-Policy'
    docker compose exec -T render sh -lc 'node tools/csp-hashes.mjs --write' >/dev/null

    # Caddy reads the snippet at startup, so the new policy needs a restart.
    docker compose restart api >/dev/null
    wait_for_http / || warn 'The site did not come back after the restart.'

    log 'Regenerating sitemaps'
    docker compose exec -T api php artisan render:sitemaps 2>&1 | tail -1
else
    log 'Serving what is already published (--rebuild to refresh)'
fi

# ---------------------------------------------------------------------------
# Ready
# ---------------------------------------------------------------------------

if ! wait_for_http /; then
    warn "The storefront answered $(probe /), not 200. Check: docker compose logs api"
fi

printf '\n'
log "Storefront   http://localhost:${HTTP_PORT}/"
log "Admin        http://localhost:${HTTP_PORT}/admin"
log "API          http://localhost:${HTTP_PORT}/api/v1/navigation"
log "Health       http://localhost:${HTTP_PORT}/health/ready"
printf '\n'

if [ "$TAIL" = "0" ]; then
    log 'Running in the background. Stop with: docker compose down'
    exit 0
fi

log 'Tailing logs — Ctrl-C detaches, the stack keeps running.'
printf '\n'

exec docker compose logs -f --tail=20 api render
