# Handover — end of Phase 2

**Date:** 2026-08-20
**Branch:** `phase-0-discovery` (contains Phases 0, 1 and 2 — not yet merged to `main`)
**Commits:** `e1d70ce` (Phase 0) · `c5e1eb6` (Phase 1) · `0f348fd` (Phase 2)

Written so a session with no prior context can continue without loss.

---

## 1. Where the project stands

| Phase | State |
|---|---|
| 0 — Discovery, IA, docs | ✅ Complete (0j keyword research still blocked) |
| 1 — Foundation, Docker, CI | ✅ Complete |
| 2 — Data layer | ✅ Complete |
| 3 — API core | ⬜ Next |

**Nothing is deployed anywhere.** This is a local development stack only.

## 2. Resume in three commands

```bash
cd C:/Users/Administrator/Documents/GitHub/officeland
cp .env.example .env          # if .env is absent
docker compose up -d --build
```

Then verify:

```bash
curl -s http://127.0.0.1:8095/health/ready     # expect 200, five checks passing
curl -s http://127.0.0.1:8095/ | grep ng-server-context   # prerendered storefront
docker compose exec api php artisan test        # expect 54 passed
```

**Port is 8095, not 8080** — 8080 was already occupied on this machine.

## 3. Things that will bite you

These cost real time to find. Each is now guarded by a test, but know them before changing anything nearby.

**`PDO::ATTR_EMULATE_PREPARES` must stay `false`.** §10.1 offers two ways to survive transaction pooling; we use PgBouncer's `max_prepared_statements = 200`. Turning emulation on interpolates bindings into SQL, which renders a PHP boolean as bare `1` — PostgreSQL will not coerce integer to boolean, so *every* insert touching a boolean column fails with SQLSTATE[42804]. Guarded by `PgBouncerIntegrationTest`.

**All migrations must declare `protected $connection = 'pgsql_direct'`.** DDL through a transaction pooler aborts mid-run with SQLSTATE[25P02] and every later statement in the transaction fails. This includes the three stock Laravel migrations. New migrations must do the same — there is no global setting enforcing it.

**Tests must never resolve to the `officeland` database.** PHPUnit's `<env force="true">` writes `$_ENV` and calls `putenv()` but does *not* touch `$_SERVER` — which is where Docker Compose injects `DB_DATABASE` and where Laravel's environment repository looks first. The suite silently ran against the development database, and `RefreshDatabase` would have dropped every table in it. Pinned in `tests/bootstrap.php`; asserted permanently by `TestDatabaseIsolationTest`. **Do not delete those assertions.**

**`RefreshDatabase` is deliberately absent from `SchemaConstraintsTest` and `PgBouncerIntegrationTest`.** It wraps each test in one long transaction. PostgreSQL aborts an entire transaction on a constraint violation, so a test that deliberately triggers one poisons everything after it — and savepoints do not fully contain this through a pooler. Those two classes run in autocommit and clean up explicitly. Adding `RefreshDatabase` back will break them in confusing, order-dependent ways.

**Caddy's content-hash matcher must match Angular's format.** Angular 22 emits `main-U44D4EQK.js` — dash-separated, uppercase base32 — not dot-separated lowercase hex. Get this wrong and every hashed asset silently falls back to `must-revalidate` instead of `immutable`. No test fails; it is purely a performance regression.

**Git Bash mangles container paths.** Prefix `docker compose exec` calls that contain absolute paths with `MSYS_NO_PATHCONV=1`, or `/etc/caddy/...` becomes `C:/Program Files/Git/etc/caddy/...`.

## 4. Stack deviations from the original specification

All recorded in `docs/DECISIONS.md` with rationale.

| Specified | Built | Why |
|---|---|---|
| Laravel 11 | **Laravel 12.67.0** | Every 11.x release is blocked by Composer's security-advisory policy (D-019). Client acknowledged. |
| Angular 21 | **Angular 22.1.0** | Client chose current (D-020) |
| Filament v4 | **Filament v5** | v4 is now the previous major (D-020) |
| Dompdf | **removed** | Nothing generates a PDF (D-006) |
| Read replica at launch | **deferred to Phase 13** | Static prerendering collapses request-path DB load (D-018) |
| SSR | **static prerender** | No prices, no live stock, so the catalogue is static (D-009) |

## 5. Phase 3 — what to build next

**Gate:** OpenAPI contract published and TS types generated; feature tests green on every endpoint; the render-route invalidation resolver proven end to end.

Start here:

1. **Eloquent models** for the Phase 2 schema. None exist yet — Phase 2 built tables and constraints only.
2. **Catalogue read endpoints** (§8.1): navigation, categories, products, brands, search, pages.
3. **ETag / `If-None-Match` / 304** and the cache headers in `ARCHITECTURE.md` §10.
4. **The render-route resolver** — gap G7, and the single most important piece of Phase 3. Under D-009 a publish must enqueue exactly the routes that need regenerating, including the bounded reverse-related cascade described in `ARCHITECTURE.md` §4.3. Get this wrong and either the site serves stale pages or a single edit regenerates hundreds.
5. **`swagger-php` → OpenAPI 3.1 → generated TS types** into `packages/contracts` (which does not exist yet — deliberately, since an empty package would be a stub).
6. **The `redirects` slug-change trigger**, deferred from Phase 2 to sit with the application layer that owns slugs.

Do **not** expose internal integer IDs in API payloads — UUIDs and slugs only (§8.3).

## 6. Open questions blocking later phases

| # | Blocks | Question |
|---|---|---|
| Q-B | Phase 12, and the launch date | Who rewrites 15,000 product descriptions, at what throughput? |
| Q7 | Phase 13 gate | Server spec (cores/RAM/disk) for production PgBouncer pool arithmetic |
| Q17 | Phase 7 | Brand assets: logo files, exact hex values, licensed fonts, corrected social URLs |
| Q18 | Phase 10 | ZeptoMail region, sending domain, SPF/DKIM/DMARC status, and what `hostinger-reach` currently sends |
| Q14 | Phase 7 | Dubai: indexable UAE section (and hreflang) or contact page only? |
| Q16 | Phase 9 | Captcha provider |
| Q20 | Phase 9 | Delivery: quoted per enquiry, or a zone/rate structure? |
| Q21 | Phase 13 | RPO/RTO targets and offsite backup destination (50 GB+ of media) |
| Q23 | Phase 8 | "Best for" merchandising taxonomy approval (`TAXONOMY.md` §7) |

**Icecat credentials are still needed** before Phase 5b can run. Open Icecat tier confirmed (D-022). The client enters credentials on their own server; the development team never needs to see them. Access is IP-whitelisted or uses dynamic tokens — note that a non-whitelisted request returns **404, not 403**, which looks like a missing product.

## 7. Known gaps and deliberate omissions

- **No `packages/contracts`** until an OpenAPI spec exists (Phase 3).
- **No Eloquent models** — Phase 2 was schema only.
- **Query plans are measured against synthetic data.** The generator produced only two product-name patterns, so "toner" matches a third of the catalogue. Re-measure at Phase 5b against real data. `docs/QUERY_PLANS.md` documents this honestly rather than gaming the gate.
- **PgBouncer pool sizing uses development defaults.** The arithmetic in `infra/pgbouncer/pgbouncer.ini` needs the server spec (Q7) before go-live.
- **20,000 synthetic products remain in the `officeland` development database** from query-plan work. They are not in `officeland_test`. Truncate when real data arrives.
- **`.gitattributes` normalises line endings to CRLF on checkout.** Shell scripts in `infra/` must stay LF or the container entrypoints fail with an unhelpful "no such file or directory" on the shebang.

## 8. Where to read what

| Document | Read it when |
|---|---|
| `IMPLEMENTATION_PLAN.md` | Before starting any phase. §2.3 requires it. |
| `DECISIONS.md` | Before questioning why something differs from the brief |
| `ARCHITECTURE.md` | Before touching pooling, prerendering or ingestion |
| `SEO_SPEC.md` | Before touching URLs, metadata or structured data |
| `ICECAT_MAPPING.md` | Before Phase 5b |
| `QUERY_PLANS.md` | Before adding an index or changing a hot query |
| `TAXONOMY.md` | Before changing the category tree — the redirect map depends on it |
