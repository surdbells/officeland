# OfficeLand NG

Quote-first B2B/B2G commerce platform for OfficeLand Limited (Lagos, Nigeria),
replacing the legacy WordPress + WooCommerce site at `officelandng.com`.

There is no cart, no checkout and no payment. Customers browse a catalogue and
submit a quote request; sales prices and responds internally.

## Quick start

```bash
sh scripts/serve.sh
```

Starts the containers, waits for every healthcheck, migrates, publishes the
storefront if nothing is published yet, and prints the URLs. Copies
`.env.example` to `.env` on a fresh clone. Safe to run repeatedly.

`--rebuild` forces a fresh storefront build (about three minutes), `--no-build`
skips it, `--detach` returns to the prompt instead of tailing logs. Stop with
`docker compose down`.

Plain `docker compose up` starts the containers but does **not** publish the
storefront, and under D-009 there is nothing to serve until it has been — see
docs/RUNBOOK.md §0.

| URL | What |
|---|---|
| http://localhost:8095/ | Storefront (prerendered static HTML) |
| http://localhost:8095/shop/ | The whole catalogue, faceted |
| http://localhost:8095/admin | Filament admin |
| http://localhost:8095/health/live | Liveness probe |
| http://localhost:8095/health/ready | Readiness probe — DB, Redis, disk, queue |

Non-production environments serve a blocking `robots.txt` and
`X-Robots-Tag: noindex` at the edge. This is enforced in Caddy, not in
application templates, and is asserted in CI.

## Stack

| Layer | Choice |
|---|---|
| Storefront | Angular 22, standalone + signals, **prerendered to static HTML** |
| API | Laravel 12 + Octane on FrankenPHP (worker mode), PHP 8.3 |
| Admin | Filament v5 |
| Database | PostgreSQL 16 behind PgBouncer (transaction pooling) |
| Cache / queue / session / locks | Redis 7 |
| Edge | Caddy (bundled with FrankenPHP) — HTTP/3, Brotli |
| Product data | Icecat (Open tier) |
| Email | ZeptoMail HTTP API — SMTP is forbidden |

Two stack choices differ from the original specification, both recorded with
rationale in [docs/DECISIONS.md](docs/DECISIONS.md): **Laravel 12** replaces
Laravel 11 (every 11.x release is blocked by security advisories — D-019), and
**Angular 22 / Filament v5** replace Angular 21 / Filament v4 (D-020).

## Architecture in one paragraph

The storefront is **prerendered to static HTML at build time** and served from
disk by Caddy — no Node process in production, no PHP in the catalogue request
path. This is viable because there are no prices and no live stock, so product
pages change only on editorial action. A publish in the admin enqueues the
affected routes for regeneration. The API serves only genuinely dynamic
surfaces: search, typeahead, facet filtering and quote submission. See
[docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).

## Repository layout

```
apps/api/          Laravel 12 + Octane + Filament
apps/web/          Angular 22 storefront
infra/caddy/       Edge config — routing, cache policy, staging guards
infra/docker/      frankenphp and node-render images
infra/pgbouncer/   Pooler config and sizing arithmetic
infra/postgres/    Extension bootstrap
docs/              Plan, architecture, SEO spec, decisions, taxonomy
```

## Documentation

| Document | Contents |
|---|---|
| [BACKLOG.md](docs/BACKLOG.md) | **Everything outstanding** — what is blocked, on what, and what unblocks it |
| [DEPLOYMENT.md](docs/DEPLOYMENT.md) | Deploying to Debian 13 + aaPanel, and what must be true first |
| [IMPLEMENTATION_PLAN.md](docs/IMPLEMENTATION_PLAN.md) | 15 phases with gates, dependencies and estimates |
| [ARCHITECTURE.md](docs/ARCHITECTURE.md) | Topology, prerender pipeline, pooling, Icecat ingestion |
| [SEO_SPEC.md](docs/SEO_SPEC.md) | URL architecture, metadata, structured data, CI gates |
| [DECISIONS.md](docs/DECISIONS.md) | Every decision and departure, with rationale |
| [TAXONOMY.md](docs/TAXONOMY.md) | The 170-category tree and its design rules |
| [REDIRECT_MAP.csv](docs/REDIRECT_MAP.csv) | Legacy URL mapping — 161/161 categories covered |

## Development

```bash
# API — lint, static analysis at level 8, tests
cd apps/api
vendor/bin/pint --test
vendor/bin/phpstan analyse
php artisan test

# Storefront — the toolchain runs in Docker (Angular CLI 22 needs Node >= 24.15)
docker compose run --rm render npm run build
```

Run the API test suite inside the container so it has Postgres and Redis:

```bash
docker compose exec api php artisan test
```

## Notes

Secrets live in `.env` and are never committed. Third-party credentials
(Icecat, ZeptoMail) are entered by the client on their own server — the
development team does not need to see them.
