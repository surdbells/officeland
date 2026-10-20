#!/bin/sh
# Publishes the Angular storefront into the shared static volume that Caddy
# serves from, then serves the SSR bundle for on-demand bot rendering.
#
# ── The build happens at IMAGE-BUILD time, not here ─────────────────────────
# The production image builds the storefront in its build stage and COPYs the
# finished dist/ in, so a running container already has a complete, known-good
# bundle. This entrypoint therefore only PUBLISHES and SERVES it.
#
# It used to run `npm run build` on every start. `ng build` deletes dist/web as
# its first step, so any restart - a crash, a host reboot, an OOM, an
# interrupted deploy - wiped the only bundle the container had and brought it
# back serving nothing (or crash-looping if the build failed). Publishing a
# pre-built bundle is instant and cannot be interrupted into an empty state.
#
# A runtime build still happens when there is NO baked bundle (a dev/bind-mount
# checkout) or when RENDER_REBUILD=1 is set explicitly.
set -eu

log() { printf '{"level":"info","service":"render-worker","message":"%s"}\n' "$1"; }
err() { printf '{"level":"error","service":"render-worker","message":"%s"}\n' "$1" >&2; }

STATIC_ROOT="${STATIC_ROOT:-/static}"
APP_ROOT="${APP_ROOT:-/repo/apps/web}"
BUILD_OUTPUT="${BUILD_OUTPUT:-$APP_ROOT/dist/web/browser}"
SERVER_ENTRY="$APP_ROOT/dist/web/server/server.mjs"

cd "$APP_ROOT"

if [ ! -d node_modules ] || [ -z "$(ls -A node_modules 2>/dev/null)" ]; then
    log "installing dependencies"
    npm ci --no-audit --fund=false
fi

# Build only when the image did not bake a bundle (dev / bind-mount), in watch
# mode, or when forced. See the header: a runtime rebuild in production is both
# redundant and the thing that let a restart leave the container with no bundle.
if [ "${RENDER_REBUILD:-0}" = "1" ] || [ "${RENDER_WATCH:-0}" = "1" ] || [ ! -f "$SERVER_ENTRY" ]; then
    log "building storefront"
    npm run build
else
    log "serving the storefront built into the image (set RENDER_REBUILD=1 to rebuild)"
fi

if [ ! -d "$BUILD_OUTPUT" ] || [ ! -f "$SERVER_ENTRY" ]; then
    err "build output missing (expected $BUILD_OUTPUT and $SERVER_ENTRY)"
    ls -R "$APP_ROOT/dist" 2>/dev/null || true
    exit 1
fi

mkdir -p "$STATIC_ROOT"

# Publish with rsync into the shared volume Caddy serves. --delete keeps it an
# exact mirror of the bundle. This is a fast copy of already-built files (no
# build in the path), and because the SOURCE bundle is never deleted any more,
# an interrupted publish simply re-runs and completes on the next start rather
# than leaving nothing to serve.
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
