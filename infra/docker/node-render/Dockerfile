# syntax=docker/dockerfile:1
# OfficeLand render worker — Angular 22 build + prerender.
#
# This container never serves traffic. It renders routes to static HTML which
# Caddy then serves from disk (docs/DECISIONS.md D-009). In Phase 1 it performs
# the full build; the incremental, publish-triggered renderer is Phase 8
# (docs/ARCHITECTURE.md section 4).
#
# Angular CLI 22 requires Node >= 24.15.0.

FROM node:24-alpine AS base
WORKDIR /app
RUN apk add --no-cache rsync

FROM base AS deps
COPY apps/web/package.json apps/web/package-lock.json* ./
RUN npm ci --no-audit --fund=false

FROM base AS dev
COPY --from=deps /app/node_modules ./node_modules
COPY apps/web/ ./
COPY infra/docker/node-render/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh
ENTRYPOINT ["/entrypoint.sh"]

FROM base AS build
COPY --from=deps /app/node_modules ./node_modules
COPY apps/web/ ./
RUN npm run build

FROM base AS prod
COPY --from=build /app/dist ./dist
COPY --from=deps /app/node_modules ./node_modules
COPY infra/docker/node-render/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh
ENTRYPOINT ["/entrypoint.sh"]
