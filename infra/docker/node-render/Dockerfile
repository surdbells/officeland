# syntax=docker/dockerfile:1
# OfficeLand render worker — Angular 22 build + prerender.
#
# This container never serves traffic. It renders routes to static HTML which
# Caddy then serves from disk (docs/DECISIONS.md D-009). In Phase 1 it performs
# the full build; the incremental, publish-triggered renderer is Phase 8
# (docs/ARCHITECTURE.md section 4).
#
# Angular CLI 22 requires Node >= 24.15.0.
#
# ── On the /repo layout ─────────────────────────────────────────────────────
# The image mirrors the monorepo rather than flattening apps/web to the root.
# apps/web/tsconfig.json aliases @contracts/api to
# ../../packages/contracts/api.d.ts, so the build only resolves if packages/ is
# two levels above the app — which a flattened /app layout cannot express.
# Section 15.1 requires the storefront to consume the generated contract, so
# the contract has to be inside the build context.

FROM node:24-alpine AS base
WORKDIR /repo/apps/web
RUN apk add --no-cache rsync

FROM base AS deps
COPY apps/web/package.json apps/web/package-lock.json* ./
RUN npm ci --no-audit --fund=false

FROM base AS dev
COPY --from=deps /repo/apps/web/node_modules ./node_modules
# Contract first: it changes far less often than application code, so it stays
# a cached layer across storefront edits.
COPY packages/ /repo/packages/
COPY apps/web/ ./
COPY infra/docker/node-render/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh
ENTRYPOINT ["/entrypoint.sh"]

FROM base AS build
COPY --from=deps /repo/apps/web/node_modules ./node_modules
COPY packages/ /repo/packages/
COPY apps/web/ ./
# The build fetches the prerender route manifest from the API. There is no API
# during `docker build`, so a build-stage image renders only static routes.
# Production images are built by the deploy pipeline with the API reachable;
# see docs/ARCHITECTURE.md section 4.
RUN npm run build

FROM base AS prod
COPY --from=build /repo/apps/web/dist ./dist
COPY --from=deps /repo/apps/web/node_modules ./node_modules
COPY infra/docker/node-render/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh
ENTRYPOINT ["/entrypoint.sh"]
