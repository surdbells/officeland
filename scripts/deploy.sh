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
# The render container is bind-mounted from the host's apps/web, so its
# entrypoint builds the storefront from the current source when it starts. This
# script does NOT build it a second time: doing so inside the already-serving
# container (as it once did) let an interrupted `ng build` delete the live
# bundle and take the site down. That entrypoint build runs asynchronously and
# render has no build-gated healthcheck, so the BUILD block below waits for the
# build output to appear before regenerating CSP and running the gates against
# it.
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
    # ── The storefront is built by the render entrypoint ────────────────────
    # The render container is bind-mounted from the host's apps/web, so its
    # entrypoint builds the storefront from the current source at start (to a
    # staging dir, swapped in atomically — see infra/docker/node-render/
    # entrypoint.sh). This script does NOT build it a second time: doing so in
    # the already-serving container (as it once did) let an interrupted `ng
    # build` delete the live bundle and take the site down.
    #
    # That build runs ASYNCHRONOUSLY, though: render has no build-gated
    # healthcheck, so `up -d --wait` returns the moment the container is running
    # (seconds), not when the build finishes (minutes). So wait here for the
    # build output to appear before using it. On a cold node_modules volume the
    # entrypoint also runs `npm ci` first, hence the generous ceiling.
    log 'Waiting for the render build to finish (npm ci on a cold volume + build — a few minutes)'
    attempts=0
    until docker compose exec -T render sh -lc \
        '[ -n "$(ls -A "${BUILD_OUTPUT:-/repo/apps/web/dist/web/browser}" 2>/dev/null)" ]' 2>/dev/null; do
        attempts=$((attempts + 1))
        if [ "$attempts" -ge 90 ]; then
            die 'Render build did not finish within ~15 minutes. Check: docker compose logs render'
        fi
        sleep 10
    done
    log 'Render build is ready'

    # Build output existing is not the same as the SSR server listening: the
    # entrypoint starts `node` only after the freshly built bundle is swapped in,
    # so there is a window where the files are there but nothing answers on the
    # port. The gates crawl that port (localhost:$PORT); firing into it too early
    # renders nothing and fails the snapshot gate at once (0 pages in ~0.2s). So
    # wait until the server actually answers before gating.
    log 'Waiting for the SSR renderer to start serving'
    attempts=0
    until docker compose exec -T render sh -lc \
        'node -e "require(\"http\").get(\"http://localhost:\"+(process.env.PORT||4000)+\"/\",r=>process.exit(r.statusCode<500?0:1)).on(\"error\",()=>process.exit(1))"' 2>/dev/null; do
        attempts=$((attempts + 1))
        if [ "$attempts" -ge 60 ]; then
            die 'The SSR renderer did not start serving within ~10 minutes. Check: docker compose logs render'
        fi
        sleep 10
    done
    log 'SSR renderer is serving'

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
