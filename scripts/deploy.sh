#!/usr/bin/env sh
#
# Deploy the current checkout.
#
# Run on the server, from the repository root, after `git pull`.
#
#   scripts/deploy.sh              full deploy (build, gate, publish)
#   scripts/deploy.sh --no-build   skip the storefront build (config-only change)
#   scripts/deploy.sh --no-gate    build and publish WITHOUT running the gates
#
# ── --no-gate, and when to use it ──────────────────────────────────────────
# The gate crawls the whole catalogue through the SSR renderer, so on a real
# catalogue (thousands of products) it takes the better part of an hour. That
# is right for a release you want proven end to end, and impractical as the
# tax on every routine deploy. --no-gate publishes without it; run the gate on
# its own when it matters: docker compose exec render sh -lc \
#   'GATE_BASE="http://localhost:${PORT:-4000}" npm run gate'
#
# ── The order matters ────────────────────────────────────────────────────
# Migrate before starting the render container, because its entrypoint builds
# the storefront by reading the catalogue through the API, and a build against
# an un-migrated schema either fails or — worse — succeeds and prerenders pages
# from a half-updated database.
#
# ── The render entrypoint owns the storefront build ────────────────────────
# `docker compose up -d --wait` recreates the render container, whose entrypoint
# builds, verifies and publishes the storefront to the static root, and only
# then serves and reports healthy. This script does NOT build the storefront a
# second time: doing so inside the already-serving container (as it once did)
# let an interrupted `ng build` delete the live bundle and take the site down.
# The gate step here verifies the build that is already serving.
set -eu

cd "$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)"

BUILD=1
GATE=1

for arg in "$@"; do
    case "$arg" in
        --no-build) BUILD=0 ;;
        --no-gate) GATE=0 ;;
        *) echo "Unknown option: $arg" >&2; exit 2 ;;
    esac
done

log() { printf '\033[1;34m==>\033[0m %s\n' "$1"; }
warn() { printf '\033[1;33m!!\033[0m %s\n' "$1" >&2; }
die() { printf '\033[1;31mxx\033[0m %s\n' "$1" >&2; exit 1; }

[ -f .env ] || die 'No .env. Copy .env.production.example and fill it in.'

# tr -d '\r': a .env saved with Windows line endings leaves a trailing carriage
# return on every value, so BUILD_TARGET reads as "prod\r" and the guard below
# rejects a correct file. .gitattributes keeps the tracked examples LF; this
# makes the read itself robust to a hand-edited .env.
APP_ENV="$(sed -n 's/^APP_ENV=//p' .env | tail -1 | tr -d '\r')"
BUILD_TARGET="$(sed -n 's/^BUILD_TARGET=//p' .env | tail -1 | tr -d '\r')"

log "Deploying as APP_ENV=${APP_ENV:-unset}, BUILD_TARGET=${BUILD_TARGET:-unset}"

if [ "${BUILD_TARGET:-}" != "prod" ]; then
    # The dev image bind-mounts the source and runs Octane with debug on. On a
    # public host that ships stack traces to strangers.
    die "BUILD_TARGET must be 'prod' for a deploy. It is '${BUILD_TARGET:-unset}'."
fi

log 'Syncing the PgBouncer userlist to DB_PASSWORD'
sh scripts/gen-userlist.sh

log 'Building images'
docker compose build

log 'Starting containers'
docker compose up -d --wait

log 'Running migrations'
docker compose exec -T api php artisan migrate --force --no-interaction

log 'Caching config, routes and views'
docker compose exec -T api sh -lc 'php artisan config:cache && php artisan route:cache && php artisan view:cache'

if [ "$BUILD" = "1" ]; then
    # ── The storefront is built ONCE, by the render entrypoint ──────────────
    # When `docker compose up -d --wait` (above) recreated the render container
    # from the freshly built image, its entrypoint built the storefront to
    # completion, verified the output existed, published it to the static root
    # and only THEN started serving and reported healthy. So by this point the
    # new bundle is already built, published and live.
    #
    # This block therefore does NOT build again. The previous version ran a
    # SECOND `npm run build` here, inside the already-serving container. `ng
    # build` deletes dist/web as its first step, so a build interrupted at this
    # point — a closed SSH session, a timed-out command — left the live SSR
    # server with no bundle to serve and every page 500ing until the container
    # was manually recreated. Making the entrypoint the single builder removes
    # that failure mode entirely (and about three minutes of redundant work).
    #
    # The gates below are a post-build verification of what is already serving;
    # a failure stops the deploy for investigation rather than rolling back, and
    # the honest fix for a bad build is to correct it and redeploy.

    # Guard: the entrypoint must have produced the browser bundle that the CSP
    # generator and the gates read. An empty output here means the entrypoint
    # build did not finish — stop before touching CSP or restarting the edge.
    docker compose exec -T render sh -lc \
        '[ -n "$(ls -A "${BUILD_OUTPUT:-/repo/apps/web/dist/web/browser}" 2>/dev/null)" ]' \
        || die 'The render container has no build output. Check: docker compose logs render'

    log 'Regenerating the Content-Security-Policy'
    docker compose exec -T render sh -lc 'node tools/csp-hashes.mjs --write' >/dev/null

    # GATE_BASE points at the SSR server INSIDE the render container, where this
    # runs. The default (localhost:8095) is the host-published edge, which is
    # not reachable from in here - a sibling container - so the snapshot crawl
    # rendered nothing and every gate after it failed. localhost:$PORT is the
    # render worker's own SSR, which returns the same full HTML a crawler is
    # served, so the raw-HTML, crawl-depth, link and a11y gates read real pages.
    if [ "$GATE" = "1" ]; then
        log 'Running gates (crawls the catalogue via the SSR renderer; can take ~an hour at scale — skip with --no-gate)'
        docker compose exec -T render sh -lc 'GATE_BASE="http://localhost:${PORT:-4000}" npm run gate' \
            || die 'A gate failed against the live build. Fix and redeploy; investigate: docker compose logs render'
    else
        warn 'Skipping gates (--no-gate): the live build is unverified.'
    fi
fi

log 'Publishing redirects'
docker compose exec -T api sh -lc 'php artisan redirects:import --apply && php artisan redirects:publish --output=/tmp/redirects.caddy' >/dev/null
docker compose exec -T api sh -lc 'cat /tmp/redirects.caddy' > infra/caddy/redirects.caddy

log 'Reloading the edge'
docker compose restart api >/dev/null

# Caddy rebinds in a second or two; checking once here reported a healthy
# stack as down on the first run of this script.
attempts=0
while [ "$attempts" -lt 30 ]; do
    status="$(docker compose exec -T api sh -lc "curl -s -o /dev/null -w '%{http_code}' http://localhost/" 2>/dev/null || true)"
    [ "$status" = "200" ] && break
    attempts=$((attempts + 1))
    sleep 1
done

[ "${status:-}" = "200" ] || die "The site answered ${status:-000}. Check: docker compose logs api"

log 'Regenerating sitemaps'
docker compose exec -T api php artisan render:sitemaps 2>&1 | tail -1

log 'Verifying redirects'
docker compose exec -T api php artisan redirects:verify --base=http://localhost 2>&1 | tail -1

printf '\n'
log 'Deployed.'
