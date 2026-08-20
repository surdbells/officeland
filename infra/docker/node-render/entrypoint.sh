#!/bin/sh
# Builds the Angular storefront and publishes the prerendered output into the
# shared static volume that Caddy serves from.
#
# Phase 1: full build on start, then idle.
# Phase 8: replaced by a queue-driven incremental renderer that regenerates
#          only the routes affected by a publish (docs/ARCHITECTURE.md 4).
set -eu

log() { printf '{"level":"info","service":"render-worker","message":"%s"}\n' "$1"; }

STATIC_ROOT="${STATIC_ROOT:-/static}"
APP_ROOT="${APP_ROOT:-/repo/apps/web}"
BUILD_OUTPUT="${BUILD_OUTPUT:-$APP_ROOT/dist/web/browser}"

if [ ! -d "$APP_ROOT/node_modules" ] || [ -z "$(ls -A "$APP_ROOT/node_modules" 2>/dev/null)" ]; then
    log "installing dependencies"
    npm ci --no-audit --fund=false
fi

log "building storefront"
npm run build

if [ ! -d "$BUILD_OUTPUT" ]; then
    printf '{"level":"error","service":"render-worker","message":"build output missing at %s"}\n' "$BUILD_OUTPUT" >&2
    ls -R "$APP_ROOT/dist" 2>/dev/null || true
    exit 1
fi

mkdir -p "$STATIC_ROOT"

# rsync rather than cp: the swap must not leave Caddy serving a half-written
# tree. --delete keeps the static root an exact mirror of the latest build.
log "publishing to ${STATIC_ROOT}"
rsync -a --delete "$BUILD_OUTPUT"/ "$STATIC_ROOT"/

log "published $(find "$STATIC_ROOT" -type f | wc -l) files"

if [ "${RENDER_WATCH:-0}" = "1" ]; then
    log "watch mode enabled"
    exec npm run watch
fi

log "build complete; idling"
exec tail -f /dev/null
