#!/bin/sh
# Builds the Angular storefront from the (bind-mounted) source, publishes it into
# the shared static volume Caddy serves, and serves the SSR bundle for on-demand
# bot rendering.
#
# ── Why it builds at runtime ────────────────────────────────────────────────
# compose.yaml bind-mounts ./apps/web over /repo/apps/web, so the running
# container builds from the host's current source (the latest `git pull`), not
# from the dist baked into the image. The build therefore happens here.
#
# ── Hardened against a failed or interrupted build ──────────────────────────
# Two failure modes took the site down before:
#   1. "ng: not found" - the named node_modules volume outlived an image that
#      had the Angular CLI, so the build crash-looped. We now reinstall whenever
#      `ng` is absent, not only when node_modules is empty.
#   2. `ng build` deletes its output as its first step, so a build that failed or
#      was interrupted (a crash, an OOM, a killed deploy) left the live bundle
#      gone and the container serving nothing. We now build into a STAGING
#      directory and swap it into place by rename only once it is complete, so
#      the live bundle survives any failure untouched, and a failed build keeps
#      serving the previous one instead of crash-looping into an empty state.
set -eu

log() { printf '{"level":"info","service":"render-worker","message":"%s"}\n' "$1"; }
err() { printf '{"level":"error","service":"render-worker","message":"%s"}\n' "$1" >&2; }

STATIC_ROOT="${STATIC_ROOT:-/static}"
APP_ROOT="${APP_ROOT:-/repo/apps/web}"
BUILD_OUTPUT="${BUILD_OUTPUT:-$APP_ROOT/dist/web/browser}"
SERVER_ENTRY="$APP_ROOT/dist/web/server/server.mjs"

cd "$APP_ROOT"

# Ensure the toolchain. The node_modules volume persists independently of the
# image, so a stale or prod-only volume can lack the Angular CLI; reinstall when
# `ng` is missing so the build cannot fail with "ng: not found".
if ! [ -x node_modules/.bin/ng ]; then
    log "installing dependencies"
    npm ci --no-audit --fund=false
fi

# Build beside the live tree, then swap by rename once it is proven complete.
log "building storefront"
rm -rf dist.staging
# Clear the Angular/esbuild build cache before every build. The cache lives on
# the bind-mounted source tree, and on 2026-10-28 a warm-but-stale cache emitted
# a byte-identical bundle after a real source change, so a deploy silently
# republished the previous build. Correctness is worth the few seconds a cold
# cache costs on an infrequent deploy.
rm -rf .angular/cache
if npm run build -- --output-path dist.staging/web && [ -f dist.staging/web/server/server.mjs ]; then
    # The live bundle is replaced only now, by rename, so it is never the
    # half-written output of an in-progress build.
    rm -rf dist.prev
    [ -d dist ] && mv dist dist.prev
    mv dist.staging dist
    rm -rf dist.prev
    log "build complete"
else
    rm -rf dist.staging
    if [ -f "$SERVER_ENTRY" ]; then
        err "build failed; continuing to serve the previous bundle"
    else
        err "build failed and there is no previous bundle to serve"
        exit 1
    fi
fi

mkdir -p "$STATIC_ROOT"

# Publish with rsync into the shared volume Caddy serves. A copy of already-built
# files; the source bundle is never deleted before it is complete, so an
# interrupted publish simply re-runs on the next start.
log "publishing to ${STATIC_ROOT}"
rsync -a --delete "$BUILD_OUTPUT"/ "$STATIC_ROOT"/
log "published $(find "$STATIC_ROOT" -type f | wc -l) files"

if [ "${RENDER_WATCH:-0}" = "1" ]; then
    log "watch mode enabled"
    exec npm run watch
fi

# Serve the Angular server bundle so the API can ask it for one route at a time
# (Phase 8c). NOT a public surface: Caddy never proxies to it and nothing
# outside the compose network can reach it.
log "serving the render worker on :${PORT:-4000}"
exec node "$SERVER_ENTRY"
