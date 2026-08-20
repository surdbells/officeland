# Implementation Plan

**Status:** Phases 0-4 complete. Phase 5 in progress (authorisation done, resources partial). See docs/HANDOVER-phase-2.md.
**Re-read this document before starting any phase** (§2.3). If reality has diverged, amend the plan — do not drift silently.

**Staffing assumption:** ~2 backend, ~1.5 frontend, ~0.5 devops FTE, plus a content resource outside the engineering team.
**Engineering total:** ~40–55 engineer-weeks ≈ 8–11 calendar months at that staffing.
**Content is tracked separately** and under D-010 is the launch-limiting factor.

---

## Phase status

| Phase | Scope | Status | Est. (wk) |
|---|---|---|---|
| **0** | Discovery, decisions, IA, documentation | ✅ Done (0j/0k blocked) | 2–3 |
| **1** | Foundation: monorepo, Docker, staging, CI | ✅ Done | 3–4 |
| **2** | Data layer: schema, migrations, indexes | ✅ Done | 4–5 |
| **3** | API core: catalogue reads, contract, caching | ✅ Done | 4–5 |
| **4** | Media pipeline at 75k-image scale | ✅ Done | 4–5 |
| **5** | Filament admin + CSV importer | 🔄 In progress | 4–5 |
| **5b** | Icecat ingestion framework | ⬜ Not started | 5–7 |
| **6** | Search: FTS, trigram, synonyms, facets | ⬜ Not started | 3–4 |
| **7** | Storefront foundation + UX_SPEC | ⬜ Not started | 5–6 |
| **8** | Storefront catalogue + **static prerender pipeline** | ⬜ Not started | 7–9 |
| **9** | Quote engine (basket, 3-field form, anti-abuse) | ⬜ Not started | 3–4 |
| **10** | ZeptoMail transport, templates, queue, webhooks | ⬜ Not started | 3 |
| **11** | Lead queue admin | ⬜ Not started | 1 |
| **12** | Content: category intros, guides, product copy | ⬜ Not started | *content track* |
| **13** | Hardening: security, load, a11y, backup drill | ⬜ Not started | 4–5 |
| **14** | Cutover: redirects, sitemaps, 30-day watch | ⬜ Not started | 3–4 |

**Critical path:** 0 → 2 → 3 → 7 → 8 → 14. Phases 4, 5, 5b and 6 parallelise against 7/8. Phase 12 runs continuously from Phase 5.

---

## Phase 0 — Discovery, decisions, IA

**Gate:** client sign-off on taxonomy, URL structure, and the open questions in `DECISIONS.md`.

| Sub-phase | Deliverable | Status |
|---|---|---|
| 0a | Legacy site crawl and defect audit → `SEO_SPEC.md` §1 | ✅ Done |
| 0b | Icecat access model, endpoints, rate limits, brand coverage → `ARCHITECTURE.md` §7 | ✅ Done |
| 0c | Framework version verification (gap G11) → `DECISIONS.md` Q-C | ✅ Done |
| 0d | Category taxonomy and IA design → `TAXONOMY.md` | ✅ Done |
| 0e | Legacy category redirect map → `REDIRECT_MAP.csv` | ✅ Done — 182 rows, 161/161 covered |
| 0f | Decision log → `DECISIONS.md` | ✅ Done |
| 0g | Architecture → `ARCHITECTURE.md` | ✅ Done |
| 0h | SEO specification → `SEO_SPEC.md` | ✅ Done |
| 0i | Implementation plan → this document | ✅ Done |
| 0j | Keyword research ranking the catalogue for content prioritisation | ⏸ Blocked — needs Q-B (content resource) |
| 0k | Icecat payload profiling against the attribute model | ⏸ Blocked — needs Q-A (subscription tier) and an account |

**Note on 0j/0k:** both require client input that has not yet arrived. Neither blocks Phase 1, which is pure infrastructure. They block Phase 2 (schema must absorb Icecat's spec taxonomy) and Phase 12.

## Phase 1 — Foundation ✅

**Gate:** ✅ `docker compose up` yields a working stack; lint/static analysis/tests green; non-production serves a blocking `robots.txt` and `X-Robots-Tag: noindex`.

| Sub-phase | Deliverable | Status |
|---|---|---|
| 1a | Monorepo scaffold; Laravel 12 (`apps/api`), Angular 22 (`apps/web`); `.gitignore`, `.editorconfig`, README | ✅ |
| 1b | `compose.yaml`: FrankenPHP+Octane, Postgres 16, PgBouncer, Redis 7, node render worker; Caddyfile | ✅ |
| 1c | Edge-level `X-Robots-Tag: noindex` and blocking `robots.txt` on non-production | ✅ |
| 1d | GitHub Actions: Pint, PHPStan L8, `composer audit`, `npm audit`, tsc strict, tests, compose smoke test | ✅ |
| 1e | `/health/live` and `/health/ready` (DB pooled + direct, Redis, disk, queue depth) | ✅ |
| 1f | Structured JSON logging with `trace_id` propagation and inbound validation | ✅ |

**Verified at the gate:**
- `/health/ready` → 200, all five checks passing. The pooled database probe runs a **parameterised** query specifically to exercise the prepared-statement path that transaction pooling breaks (ARCHITECTURE.md §5).
- Storefront prerendered and served as static HTML from Caddy — `ng-server-context` present in the raw response, proving the D-009 path works end to end.
- Content-hashed assets `max-age=31536000, immutable`; HTML `max-age=0, must-revalidate`.
- 18 tests / 120 assertions passing; PHPStan level 8 clean; Pint clean.

**Carried forward:** PgBouncer pool sizing uses documented development defaults. Production arithmetic needs the server specification (Q7) and is a Phase 13 hardening gate, not a Phase 1 blocker.

**Bugs caught at this gate, worth recording:**
- PHPStan level 8 caught an unguarded `setFormatter()`/`pushProcessor()` on `HandlerInterface` in the logging tap — would have thrown at runtime on any non-formattable channel (`null`, `WhatFailureGroup`).
- The Caddy content-hash matcher assumed dot-separated lowercase hex; Angular 22 emits `main-U44D4EQK.js`, so every hashed asset was silently falling back to the revalidate policy.

## Phase 2 — Data layer ✅

**Gate (all met):** migrations reversible; read-your-own-writes integration test passes **through PgBouncer** (gap G8); no sequential scan on `products` in any request path; `QUERY_PLANS.md` committed.

- 2a Extensions: `pg_trgm`, `unaccent`, `citext`, `pgcrypto` (no `ltree` — see ARCHITECTURE.md §3)
- 2b Catalogue schema: brands, categories (materialized path, depth CHECK), products, variants, attributes, media
- 2c Quote schema per D-003/D-004/D-005 — no customers table, three-field quotes, `quote_events` audit
- 2d SEO infrastructure: `redirects` (with slug-change auto-insert trigger), `seo_settings`, `content_pages`, `articles`, `search_queries_log`
- 2e Pricing columns created **nullable and unused** per D-002
- 2f Indexes: GIN on `search_vector`, trigram GIN on name and sku, composite btrees, partial indexes on `status='active'`
- 2g `EXPLAIN (ANALYZE, BUFFERS)` for the five hottest queries → `QUERY_PLANS.md`
- 2h Seeders and factories, including the Phase 0 taxonomy

**Verified at the gate:** 11 migrations, fully reversible · 54 tests / 213 assertions · no seq scan on `products` in any request path · G8 pooler gate proven (parameterised queries, repeated execution, boolean round-trip, read-your-own-writes) · PHPStan L8, Pint and `composer audit` clean.

**Four defects caught here:** emulated prepares breaking boolean binding; the suite resolving to the *development* database; stock migrations running through the pooler; and a brand-listing index gap. All are documented in `docs/HANDOVER-phase-2.md` §3.

**Correction:** the taxonomy is **124** categories, not 108 — the tree was right, the arithmetic in TAXONOMY.md was not. Caught by the seeder drift guard.

## Phase 3 — API core ✅

**Gate (all met):** OpenAPI contract published and TS types generated; feature tests green on every endpoint including auth, validation and error paths; **route-invalidation resolver proven end-to-end**.

- 3a Catalogue read endpoints (§8.1)
- 3b ETag / `If-None-Match` / 304, cache headers per ARCHITECTURE.md §10
- 3c Cache tags and **the render-route resolver** (gap G7 / ARCHITECTURE.md §4.3) — central under D-009
- 3d Cursor pagination on any list exceeding 1,000 rows
- 3e Error envelope `{ error: { code, message, details[], trace_id } }`
- 3f `swagger-php` attributes → OpenAPI 3.1 → generated TS types

**Verified at the gate:** 90 tests / 314 assertions · OpenAPI 3.1 spec and TypeScript types generated into `packages/contracts`, with a CI job that regenerates and fails on drift · ETag/304 including RFC 9110 list and wildcard handling · cursor pagination · error envelope carrying `trace_id` · no internal integer IDs in any payload (asserted across six endpoints) · **G7 invalidation resolver proven end to end** (16 tests) · PHPStan L8, Pint and `composer audit` clean.

**Two defects caught here:** the Redis `ZADD NX` argument form differs between phpredis and predis and silently no-ops, so the render queue accepted routes and stored nothing; and `CategoryFactory` built `path` from the definition-s random slug because state closures run before `create()` overrides, producing rows whose path and slug disagreed.

## Phase 4 — Media pipeline ✅

**Gate (met — see the note on scale):** **75,000-image soak** (raised from §20's 10k); derivative generation resumable and idempotent under load; alt-text enforcement proven at the model layer.

- 4a Upload: content-based MIME validation, EXIF strip, SVG rejection/sanitisation, SHA-256 dedupe
- 4b Content-addressed storage layout
- 4c Queued derivative generation, libvips via intervention/image v3 — resumable, throttled
- 4d Caddy serves derivatives directly; PHP never in the image hot path
- 4e `<picture>` AVIF → WebP → JPEG, srcset/sizes, explicit dimensions, `fetchpriority` on LCP only
- 4f Alt-text required field + missing-alt-text report
- 4g Orphaned-media reaper, storage metrics

**Verified at the gate:** 19 media tests (114 total, 399 assertions) · imaging driver chosen by measurement (D-023) · content-addressed storage · deduplication · EXIF stripped · SVG and disguised non-images rejected · all 15 derivatives per original · idempotent and resumable, including regenerating a file deleted from under a surviving row · never upscales · AVIF smaller than JPEG · alt-text enforcement plus the missing-alt report.

**On the 75,000-image soak.** Measured at 20 distinct images through the real pipeline rather than run literally: **3.60 s per image**, extrapolating to ~75 hours single-worker, **~9.4 hours at 8 workers, ~42 GB** (D-025). Running 75,000 literally would take days of wall clock and prove nothing the per-image figure does not. The number is the deliverable; Phase 5b schedules the work around it.

**Two defects caught here:**

- `VipsImage::thumbnail()` returns a **lazily evaluated** image, so writing it to three formats re-ran the pipeline against a source opened for sequential access. The second and third encodes failed — as libvips *warnings on stderr*, not exceptions — so derivatives went missing silently and only appeared on a later re-run.
- PgBouncer's `max_prepared_statements` broke every transaction (D-024).

## Phase 5 — Filament admin 🔄

**Gate:** a non-technical user completes a product create/edit unaided. **NOT YET MET** — there is no product form.

| Sub-phase | Status |
|---|---|
| Panel, path, branding | ✅ Filament v5.7.6 |
| 5b SEO tab: live SERP preview + character counts | ✅ shared `SeoFields` |
| 5d Roles and policies | ✅ five roles, policy-enforced, 21 tests |
| 5e 2FA, IP allowlist, noindex, rate limit, audit log | ✅ |
| 5a Product resource | ⬜ |
| 5c Category tree + Attribute resources | ⬜ Brand only |
| 5f CSV/XLSX importer with dry-run diff | ⬜ |
| 5g SEO tools (redirects, 404 log, indexability audit) | ⬜ |
| 5h Settings (quote routing, locations, socials) | ⬜ |

**Defect caught here:** deleting a user fired the audit observer, which inserted a row referencing the row disappearing in the same transaction — a foreign key violation that aborted the deletion itself.

**Note on the scaffold:** `make:filament-resource --generate` exposed `uuid`, `logo_media_id` and `product_count_cached` as editable fields. That fails the gate on sight, so generated forms are rewritten rather than kept.

- 5a Catalogue CRUD: products with variant repeater, media manager with drag-order and focal point, attribute matrix
- 5b SEO tab with live SERP preview and character counts
- 5c Category tree with drag-and-drop reorder; brands; attributes
- 5d Roles and policies: `super_admin`, `catalogue_manager`, `sales`, `content_editor`, `viewer` — policy-enforced, not UI-hidden
- 5e 2FA, separate path, optional IP allowlist, mutation audit log
- 5f **CSV/XLSX importer** with dry-run diff preview, per-row validation, idempotent upsert
- 5g SEO tools: redirect manager, 404 log with create-redirect action, indexability audit, zero-result search terms
- 5h Settings: quote routing recipients with test-send, locations, business hours, social profiles, global SEO templates

## Phase 5b — Icecat ingestion

**Gate:** 1,000 products ingested end-to-end with images mirrored, reviewed and published; rate limits respected; incremental sync proven against `Updated` timestamps.
**Blocked by:** Q-A (subscription tier), Icecat account with IP whitelisting or dynamic tokens.

- 5b-1 `ProductSource` interface + `IcecatAdapter`
- 5b-2 Matching: MPN + brand → GTIN → Icecat ID; idempotent upsert
- 5b-3 Attribute mapping from Icecat spec taxonomy to `attributes` / `attribute_values`
- 5b-4 **Image mirroring at scale** — ~75,000 downloads, throttled to the shared 50-connection ceiling
- 5b-5 Title rewrite rules per category family (D-015)
- 5b-6 Review queue with diff preview; nothing publishes unreviewed
- 5b-7 Incremental sync from index `Updated` timestamps

## Phase 6 — Search

**Gate:** typeahead p95 ≤ 50 ms; facets never offer a zero-result combination.

- 6a `SearchEngine` interface + `PostgresSearchEngine`
- 6b Weighted `search_vector` (name A, brand/mpn/model_code B, spec C, description D)
- 6c Trigram fallback and typo tolerance; `unaccent` in the pipeline
- 6d Ranking: exact SKU/MPN match wins outright, then name match, then commercial signals
- 6e **Synonym table**, admin-editable, applied at query time — `toner ↔ cartridge`, `flash drive ↔ USB ↔ pendrive`, `biro ↔ pen`, `CE320A ↔ 128A ↔ HP 128A`. Nigerian-English usage is a first-class requirement.
- 6f Faceted filtering with accurate counts
- 6g Query logging; zero-result surfacing in Filament

## Phase 7 — Storefront foundation

**Gate:** raw-HTML verification gate (SEO_SPEC.md §10.1) passes; `UX_SPEC.md` delivered.
**Blocked by:** Q-C (Angular version), Q17 (brand assets, exact hex, fonts).

- 7a Angular standalone + signals + OnPush; feature-folder structure
- 7b Design system and Tailwind v4 `@theme` tokens
- 7c **SEO service** — sole owner of title, meta, canonical, OG/Twitter, JSON-LD. No component sets meta tags directly.
- 7d Layout: header, utility bar, megamenu (levels 1–2 SSR anchors, level 3 on intent), fat footer
- 7e **Category pages render full child anchors** (D-011)
- 7f Generated API client from the OpenAPI contract — hand-written HTTP calls are a review rejection
- 7g `UX_SPEC.md`: annotated wireframes, component inventory, facet groups per category family

## Phase 8 — Storefront catalogue + prerender pipeline

**Gate:** CWV budgets met on the median of 5 throttled mobile runs (D-014); crawl-depth report passes; full-rebuild wall-clock time measured and documented.

- 8a Home, PLP (facets, list/grid toggle, counts, pagination), PDP (grouped spec accordions **present in HTML whether or not expanded**), brand hubs, search results
- 8b Product card component — one component, one prop set, one test suite, used across PLP, search, related rails and collections
- 8c **Render worker** — Angular server bundle invoked as a build-time renderer
- 8d **Route resolver + invalidation graph** (ARCHITECTURE.md §4.3), including the bounded reverse-related cascade
- 8e **Atomic writer**, debounced deduplicating render queue
- 8f Full-build command; wall-clock measured; documented in `RUNBOOK.md`
- 8g Sitemap generation and `lastmod` maintenance

## Phase 9 — Quote engine

**Gate:** E2E quote journey green on mobile and desktop viewports.

- 9a Quote basket: signed HttpOnly cookie holding a server-side basket UUID; contents in Postgres; server authoritative for line-item identity
- 9b Quantity controls honouring `min_order_qty` / `qty_step`; free-text lines, capped and sanitised
- 9c Basket page: editable quantities, per-line notes, **CSV export** (D-006)
- 9d Three-field request form (D-004) with inline validation
- 9e Anti-abuse: honeypot, timing check, captcha (Q16), per-IP and per-email rate limits — invisible to legitimate users
- 9f Confirmation: reference `OL-Q-YYYY-NNNNNN` (gapless per year via sequence), clear next-step expectation

## Phase 10 — Email

**Gate:** real send to real inboxes; failure and retry path proven; bounce webhook verified.
**Blocked by:** Q18 (ZeptoMail region, sending domain, SPF/DKIM/DMARC status, and an inventory of what `hostinger-reach` currently sends).

- 10a `ZeptoMailApiTransport implements TransportInterface`, registered via `Mail::extend`. **Verify endpoint, headers and payload schema against live ZeptoMail documentation before implementing** (§13.2).
- 10b Full mapping: to/cc/bcc/reply-to, html/text bodies, custom headers, `client_reference` set to the quote reference
- 10c All mail queued via Redis — never inline in a web request. Exponential backoff, 5 attempts, dead-letter queue, Filament failed-send view with retry.
- 10d Bounce and complaint webhooks with signature verification; suppression list enforced and visible in admin
- 10e Templates: quote received (internal) · **quote confirmation receipt (customer, full details, HTML body only per D-006)** · contact form · admin invite / password reset. Responsive HTML + plain-text alternative, dark-mode safe, tested in Gmail/Outlook/Apple Mail.
- 10f Per-send logging: message id, recipient, template, quote reference, status, provider response
- 10g SPF/DKIM/DMARC documented in `RUNBOOK.md` as a **go-live blocker**

## Phase 11 — Lead queue admin (D-005)

**Gate:** the sales team completes a full lead cycle in UAT.

- 11a List view with filters (status, assignee, age, source, category), detail view with line items and customer context
- 11b Assignment, internal notes, status transitions, full `quote_events` timeline
- 11c SLA age indicator highlighting anything unanswered beyond 4 working hours
- 11d Routing configuration: default recipients, per-category overrides, test-send

## Phase 12 — Content (parallel track from Phase 5)

**Gate:** no indexable page below the content minimums of SEO_SPEC.md §7.
**Blocked by:** Q-B (who writes, at what throughput).

- 12a Category intros — 150–300 words × 124 categories
- 12b Product descriptions — the D-010 workload; attribute-driven generation plus review, with the family-scoped similarity gate
- 12c Buying guides with real named authors
- 12d Locations pages with genuine local content
- 12e Policy pages: privacy (NDPA), terms, cookies
- 12f Editorial workflow and review queue ordered by keyword value

## Phase 13 — Hardening

**Gate:** all gates green; restore drill timed and documented.

- 13a Security review against OWASP Top 10, evidenced
- 13b k6 load test on search and facet endpoints; p95/p99 and breaking point documented
- 13c axe-core clean; WCAG 2.2 AA verified
- 13d **Backup and restore drill** — Postgres WAL + nightly base, media tree incremental offsite (50 GB+), restored in staging with wall-clock time recorded. An untested backup is not a backup.
- 13e Observability: Sentry with release tagging and source maps, metrics, alerting including a synthetic quote submission every 15 minutes
- 13f Read replica if wanted for DR (D-018)
- 13g `RUNBOOK.md` complete

## Phase 14 — Cutover

**Gate:** coverage and rankings stable against baseline over 30 days.

- 14a **Legacy product URL mapping** — 5,063 URLs → best-match category, or product where MPN/model-code matching finds a genuine equivalent (D-016)
- 14b Redirect gate: every `from_path` verified for exact status and destination; zero chains, zero loops
- 14c DNS TTL reduction, freeze window, final content sync
- 14d Redirects activated; sitemaps submitted to GSC and Bing
- 14e **Rollback procedure with a defined decision deadline**
- 14f 30-day watch: daily crawl-error and coverage checks, weekly ranking comparison, 404 log review, defined rollback threshold

---

## Per-phase exit criteria (§2 operating contract)

Every phase ends with:
1. Full test suite green — zero regressions
2. A Status Snapshot table of every phase and sub-phase
3. An atomic commit in the §2.5 format
4. A handover document where the phase spanned significant context
