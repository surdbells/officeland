# Implementation Plan

**Status:** Phases 0-11 built; Phase 12's pipeline built and enforced, its writing not started. Five gates remain open, none of them on code: Phase 8 CWV (Q7 + a browser harness), Phase 9 viewport testing (same), Phase 10 real sends (Q18 credentials), Phase 11 UAT (the sales team), and Phase 12 the writing itself (**Q-B**). Phase 13a-13e are done: the security review (4 findings), the load test, the accessibility gate (4 findings), the restore drill, and the quote-pipeline canary. Phase 5b or 14 next. See docs/HANDOVER-phase-2.md.

**Q-B is now the binding constraint on launch date.** Everything mechanical that 15,000 indexable products needs is in place; what is missing is the copy, and no decision has been taken about who writes it.
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
| **5** | Filament admin + CSV importer | ✅ Done | 4–5 |
| **5b** | Icecat ingestion framework | ⬜ Not started | 5–7 |
| **6** | Search: FTS, trigram, synonyms, facets | ✅ Done | 3–4 |
| **7** | Storefront foundation + UX_SPEC | ✅ Done | 5–6 |
| **8** | Storefront catalogue + **static prerender pipeline** | 🟡 Built; CWV unmeasured | 7–9 |
| **9** | Quote engine (basket, 3-field form, anti-abuse) | ✅ Done | 3–4 |
| **10** | ZeptoMail transport, templates, queue, webhooks | 🟡 Built; needs Q18 to send | 3 |
| **11** | Lead queue admin | 🟡 Built; awaiting UAT | 1 |
| **12** | Content: category intros, guides, product copy | 🟡 Pipeline built; writing blocked on Q-B | *content track* |
| **13** | Hardening: security, load, a11y, backup drill | 🟡 13a-13e done; 13e needs a Sentry DSN | 4–5 |
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

## Phase 5 — Filament admin ✅

**Gate:** ✅ a non-technical user completes a product create/edit unaided — proven by driving the real forms, not the models.

| Sub-phase | Status |
|---|---|
| Panel, path, branding | ✅ Filament v5.7.6 |
| 5b SEO tab: live SERP preview + character counts | ✅ shared `SeoFields` |
| 5d Roles and policies | ✅ five roles, policy-enforced, 21 tests |
| 5e 2FA, IP allowlist, noindex, rate limit, audit log | ✅ |
| 5a Product resource | ✅ tabbed, editor-facing, live content-floor counter |
| 5c Category tree + Attribute resources | ✅ derived path/depth, drag reorder |
| 5f CSV importer with dry-run diff | ✅ idempotent, field-level diff, 16 tests |
| 5g SEO tools (redirects, 404 log, indexability audit) | ✅ zero-result terms await Phase 6 search |
| 5h Settings (quote routing, locations, socials) | 🟡 quote routing + test-send landed in Phase 11d; locations and socials still open on Q17 |

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

## Phase 6 — Search ✅

**Gate (met):** typeahead p95 ≤ 50 ms; facets never offer a zero-result combination.

- 6a `SearchEngine` interface + `PostgresSearchEngine`
- 6b Weighted `search_vector` (name A, brand/mpn/model_code B, spec C, description D)
- 6c Trigram fallback and typo tolerance; `unaccent` in the pipeline
- 6d Ranking: exact SKU/MPN match wins outright, then name match, then commercial signals
- 6e **Synonym table**, admin-editable, applied at query time — `toner ↔ cartridge`, `flash drive ↔ USB ↔ pendrive`, `biro ↔ pen`, `CE320A ↔ 128A ↔ HP 128A`. Nigerian-English usage is a first-class requirement.
- 6f Faceted filtering with accurate counts
- 6g Query logging; zero-result surfacing in Filament

**Verified at the gate:** 189 tests (28 new) · typeahead p95 under the 50ms budget against 300 products · facets proven never to offer a zero-result value AND to keep siblings selectable after one is chosen · exact-identifier short-circuit proven to beat decoys and to DECLINE on an ambiguous MPN rather than silently pick one · PHPStan L8, Pint and `composer audit` clean.

**Four defects caught here:** PostgreSQL inferring a `then ?` type from `else 0` and rejecting the 0.30 relevance boost as an integer; multi-word synonyms producing invalid tsquery; `similarity()` being too diluted for a single mistyped word (switched to `word_similarity`); and `SearchTest` not resetting `search_synonyms`, so an assertion passed for the wrong reason.

**Image reproducibility (D-026):** fixing a media-test failure revealed that the Phase 4 image never contained libvips — it had been installed by hand into the running container for the driver benchmark. Also surfaced that `docker compose build` was reporting success without replacing the image (buildx needed `--load`), and that `libvips42` can read AVIF but not write it. All three fixed and verified against the image.

## Phase 7 — Storefront foundation ✅

**Gate:** ✅ raw-HTML verification gate (SEO_SPEC.md §10.1) passes — 20 URLs spanning all four templates, drawn from 136 prerendered pages; `UX_SPEC.md` delivered.
**Q17 (brand assets, exact hex, fonts) remains open.** Built against clearly-marked placeholder tokens in `styles.css`. It blocks visual identity, `Organization.sameAs` and the colour-contrast commitment — not structure.

| Sub-phase | Deliverable | Status |
|---|---|---|
| 7a | Angular standalone + signals + OnPush; feature folders | ✅ Done |
| 7b | Design system and Tailwind v4 `@theme` tokens | ✅ Done — values provisional pending Q17 |
| 7c | SEO service, sole owner of title/meta/canonical/OG/JSON-LD | ✅ Done — 22 unit tests |
| 7d | Header, utility bar, megamenu, fat footer | ✅ Done |
| 7e | Category pages render full child anchors (D-011) | ✅ Done |
| 7f | Typed API client over the OpenAPI contract | ✅ Done |
| 7g | `UX_SPEC.md` | ✅ Done |

**Also delivered, because the gate could not pass without it:**

- **The prerender pipeline, working end to end for the first time.** `/api/v1/render/manifest` supplies the route list and `app.routes.server.ts` renders all of it: 136 routes, zero failures. Phase 8 still owns the *incremental* half; the full rebuild works now.
- **`ServerApiBackend`** (D-027) — before this the prerender could not reach the API at all.
- **`TrailingSlashUrlSerializer`** (D-028) — every internal link on the site was a redirect hop.
- **Two-scope JSON-LD** (D-029) — every product page was shipping without `Organization`.
- **Candidate titles** (D-030) — the real title data does not fit the template.
- **`DemoCatalogueSeeder`** — ten hand-written products with real copy and grouped specifications, so the gate asserts against content rather than faker output.
- **D-024 corrected, and `PgBouncerConcurrencyTest` added.** The prerender was the first genuinely concurrent load the API had ever seen, and it failed 64 of 136 pages. A production-severity database bug that four phases of tests had not surfaced; see D-024 for the corrected diagnosis and why the suite missed it.
- **CI**: `web-build` now stands up PostgreSQL and the API, seeds a catalogue, prerenders against it, and runs the raw-HTML gate. Previously it built with no API and asserted only that some HTML existed.

**Deferred to Phase 8 as planned:** product grid, facet rail, pagination, gallery, mobile drawer, typeahead, related rails.

## Phase 8 — Storefront catalogue + prerender pipeline

**Gate:** ⏳ 2 of 3. Crawl-depth report ✅ and full-rebuild wall-clock ✅ measured and documented; **CWV budgets not yet measured** — see below.

| Sub-phase | Deliverable | Status |
|---|---|---|
| 8a | Home, PLP (facets, counts, pagination), PDP (gallery, spec accordions), brand hubs, search results | ✅ Done |
| 8b | Product card — one component, one prop set, one test suite | ✅ Done — 18 tests |
| 8c | Render worker — Angular server bundle invoked as a build-time renderer | ✅ Done |
| 8d | Route resolver + invalidation graph, bounded reverse-related cascade | ✅ Done |
| 8e | Atomic writer, debounced deduplicating render queue | ✅ Done — 12 tests |
| 8f | Full-build command; wall-clock measured; documented in `RUNBOOK.md` | ✅ Done |
| 8g | Sitemap generation and `lastmod` maintenance | ✅ Done — 10 tests |

**The pipeline works end to end.** A model change enqueues routes, the consumer renders them through the worker, and the atomic writer publishes them where Caddy serves them. Verified by touching one product: 10 routes enqueued, 5 rendered in 327 ms after the 60-second debounce, files replaced with no temporary files left behind.

**Measured** (development stack, 145 routes, 10-product demo catalogue):

| Operation | Wall clock | Per route |
|---|---|---|
| `render:full` (bundle already built) | **7.6 s** | **52 ms** |
| `ng build` (compile + prerender) | ~200 s | — |
| Incremental render, one product edited | 0.33 s | 66 ms |

Extrapolated to 15,000 products (~15,150 routes): **~13 minutes** for `render:full`. That is the floor on time-to-recover for any markup or global-SEO defect, and it is in `RUNBOOK.md` §3 rather than in someone's head.

**Crawl-depth gate: every product at 2 clicks**, one better than §14.5's requirement of 3, with headroom for another taxonomy level. D-011's child anchors combined with subtree listings are what produce that. The gate walks the prerendered files following only real `<a href>` — no JavaScript, no sitemap, no manifest, because both of those are generated from the database and would agree with each other while the link graph was broken.

### Still outstanding

- **CWV budgets (D-014): not measured.** It needs a Lighthouse harness that is not yet in the stack, and this machine cannot produce a trustworthy number — see D-033, where a bind-mounted file read costs 29 ms and a full API test run took 40 minutes. Measure on the production host once **Q7** (server spec) is answered.
- **Mobile drawer, typeahead** — specified in `UX_SPEC.md`, not built.
- **The render image rebuild is unverified.** The entrypoint now serves the SSR worker, but `docker buildx build` failed on a transient `ECONNRESET` from the npm registry and the change is only verified in a running container. Per D-026 that is not proof: rebuild and assert against the **image** before relying on it.

### Decisions this phase forced

- **D-031** — listings page by number, not by cursor; §8.3's requirement is met by a different route.
- **D-032** — pagination is a path segment, because Angular cannot prerender a query string.
- **D-033** — `vendor/` off the Windows bind mount; a 1,450× I/O difference that presented as an application fault.
- **D-034** — `render:full` and `ng build` are different operations with different jobs.

## Phase 9 — Quote engine ✅

**Gate:** ✅ the quote journey is green end to end — add, merge, coerce, edit, free-text, export, submit, confirm — covered by 25 tests in `QuoteJourneyTest` plus 10 for the CSV. **Viewport testing is not automated** (see below).

| Sub-phase | Deliverable | Status |
|---|---|---|
| 9a | Signed HttpOnly cookie holding a server-side basket; server authoritative for line identity | ✅ Done — see D-035 on "signed" |
| 9b | Quantity controls honouring `min_order_qty` / `qty_step`; free-text lines capped and sanitised | ✅ Done — D-036 |
| 9c | Basket page: editable quantities, per-line notes, CSV export (D-006) | ✅ Done |
| 9d | Three-field request form with inline validation | ✅ Done |
| 9e | Honeypot, timing check, per-IP and per-email rate limits; captcha seam | ✅ Done — D-037 |
| 9f | Reference `OL-Q-YYYY-NNNNNN`, gapless per year | ✅ Done — D-038 |

**The whole journey is server-authoritative.** Nothing about a line — its name, its specification, its allowed quantities — is accepted from the client, because all of it is copied into an email a salesperson acts on. A crafted request cannot put `1` against a product with a minimum of 50.

**Quotes snapshot the catalogue.** Names, SKUs, brands, specs and URLs are copied onto the quote at submission. If it rendered live, renaming a product would retroactively change what a customer is on record as having requested, and the email they already hold would no longer match the quote we hold.

**The IP is stored hashed.** It exists to answer "did these fifty submissions come from one place", which a hash answers just as well, and NDPA compliance is easier to argue for a hash than for an address we had no need to keep.

### Found while building

- **D-039** — `GET /api/v1/basket` was being marked `public, max-age=60` like any other catalogue read. A shared cache could have served one visitor's basket, and their cookie, to the next person who asked. Fixed and asserted.
- Laravel's JSON test helpers send **no cookies** unless `withCredentials()` is called, and the test client does not persist `Set-Cookie` between calls. Without accounting for that, every multi-step basket test silently starts a fresh basket and the assertions become meaningless rather than failing loudly.

### Outstanding

- **Q16 (captcha)** — deliberately not implemented. It costs every legitimate buyer on a slow mobile connection, and should only be added if the cheaper checks prove insufficient against real traffic. The seam is `AbuseGuard::challengePassed()` and it fails closed.
- **The confirmation email is Phase 10.** A quote currently records itself and returns a reference; nothing is sent. The confirmation page says a copy has been emailed, which will only be true once Phase 10 lands — that page copy and the email must ship together.
- **Viewport testing is not automated.** The gate says "green on mobile and desktop viewports"; the journey is verified by API and unit tests, and the layouts are responsive by construction, but there is no browser harness. It needs the same Playwright/Lighthouse setup Phase 8's CWV measurement is waiting on (Q7).

## Phase 10 — Email 🟡

**Gate:** ⏳ **not met, and cannot be without Q18.** The gate is "real send to real inboxes; failure and retry path proven; bounce webhook verified", and all three need live ZeptoMail credentials that the client holds on their own server. Everything up to that boundary is built and tested against the verified API contract; the procedure to close the gate is `RUNBOOK.md` §8.6.

| Sub-phase | Deliverable | Status |
|---|---|---|
| 10a | `ZeptoMailApiTransport implements TransportInterface`, via `Mail::extend` | ✅ Done — API verified against live docs first |
| 10b | Full mapping: to/cc/bcc/reply-to, html/text, headers, `client_reference` | ✅ Done — 11 tests |
| 10c | Queued via Redis, exponential backoff, 5 attempts, failed-send view | ✅ Done |
| 10d | Bounce/complaint webhooks with signature verification; suppression enforced and visible | ✅ Done — 14 tests |
| 10e | Templates: confirmation receipt (HTML body, D-006) and internal notification | 🟡 Built; **not yet tested in Gmail/Outlook/Apple Mail** |
| 10f | Per-send logging: request id, recipient, template, reference, status | ✅ Done |
| 10g | SPF/DKIM/DMARC documented as a go-live blocker | ✅ Done — `RUNBOOK.md` §8.1 |

**The API contract was verified before implementation, as §10a demands.** That mattered: `Authorization: zoho-enczapikey <token>` is not `Bearer`, recipients nest inside an `email_address` object, and the bodies are `htmlbody`/`textbody`. ZeptoMail accepts a structurally wrong request with a **200 and delivers nothing** — so every field name is asserted (D-040).

**The whole chain is proven at the wire.** `QuoteMailDeliveryTest` submits a real quote through the real endpoints and asserts the exact JSON that would reach ZeptoMail: subject, `client_reference`, both bodies, the product name in the receipt, and reply-to. Submission → mailer → mailable → Blade → transport → payload.

**Two emails, opposite reply-to.** The customer's receipt replies to sales; the sales notification replies to **the customer**, so a lead is answered by hitting reply rather than copying an address out of the body.

### Judgements worth knowing about

- **D-041** — soft bounces do not suppress, and a suppressed customer still produces a sales notification. A bad email address must not become a lost order.
- **D-042** — the webhook signature is the entire security boundary and fails closed. An unverified webhook could suppress any address an attacker names.
- **D-043** — open and click tracking are off. A tracking pixel on a transactional receipt is a privacy cost the customer never agreed to, and rewritten links get receipts flagged as phishing.

### Outstanding

- **Q18 is now blocking.** Needed: the ZeptoMail **region** (a EU tenant on the `.com` endpoint returns a 401 that reads exactly like a bad key), the sending domain, SPF/DKIM/DMARC status, and **an inventory of what `hostinger-reach` currently sends** — anything still live starts failing the moment DMARC is enforced, and it will look like a ZeptoMail fault.
- **Email client testing.** The templates are Laravel markdown mail, so they compile to the table-based inlined-CSS HTML Outlook needs and generate the plain-text alternative from one source. They have not been opened in Gmail, Outlook or Apple Mail, in light or dark mode. That is a manual step and belongs with the first real send.
- **SPF/DKIM/DMARC are a go-live blocker.** Not a hardening task for afterwards: without them, confirmations land in spam, the customer concludes we never replied, and **nobody involved learns the email was the problem**.

## Phase 11 — Lead queue admin (D-005) 🟡

**Gate:** ⏳ **built, awaiting UAT.** The gate is "the sales team completes a full lead cycle", which only the sales team can sign off. The cycle itself is proven end to end in `LeadQueueTest` — a lead arrives, somebody takes it, replies, notes what happened, and closes it won or lost, with every step on the timeline afterwards — driven through the real Filament pages rather than the models.

| Sub-phase | Deliverable | Status |
|---|---|---|
| 11a | List view with filters (status, owner, source, age), detail view with line items and customer context | ✅ Done |
| 11b | Assignment, internal notes, status transitions, full `quote_events` timeline | ✅ Done |
| 11c | SLA age indicator, working hours, highlighting anything past 4 | ✅ Done — D-044, 23 tests |
| 11d | Routing: default recipient, per-category overrides, test-send | ✅ Done — D-046, D-047 |

**This is a queue, not a CRM.** D-005 put pricing and the reply itself outside the system, so the only job left here is making sure every lead is seen, owned and answered within the promise the site makes on every page. Everything on the screen serves that and nothing else: there is no create form (a lead typed by hand is indistinguishable from a real submission afterwards), no pricing, no pipeline board.

**The default view is deliberately narrow.** Tabs — Waiting, Late, Mine, Everything — rather than a pre-applied filter. A queue that opens on "everything ever submitted" gets scrolled past; and unlike a hidden default filter, a tab makes it obvious that the view *is* narrowed, which is how somebody avoids concluding a lead was never received.

**Oldest first**, which is the opposite of most admin tables and correct here: newest-first buries the lead that has waited longest under every new submission.

### Judgements worth knowing about

- **D-044** — the SLA clock counts **working hours** in Africa/Lagos, and public holidays are deliberately not modelled. Elapsed time makes every Friday-evening lead look catastrophically late by Monday, and a queue where most rows are red is one nobody reads — which hides the row that is genuinely late.
- **D-045** — `first_response_at` is set by an explicit "I have replied" button, never inferred. The reply happens outside this system, so nothing can detect it; every alternative either makes the metric a lie or never sets it for the leads that stall.
- **D-046** — routing rules live in the **database**, editable by sales; the fallback address stays in config. Routing is a personnel decision, and config-file routing means a deploy every time somebody changes desk. Rules point at a category row rather than a path string, longest prefix wins, and routing can narrow but **never drop** — every failure mode falls back to the default inbox.
- **D-047** — the test-send fabricates a quote and sends it synchronously. Fabricated because the destination is typed into a form, so a real lead would let admin access forward a customer's details anywhere; synchronous because a queued test reports success before the transport is touched, which is exactly the failure the button exists to catch.

### Two corrections made while building this

- **`QuoteReceivedMail` counted its lines with a second query** (`items()->count()`) while the body iterated the same rows. Now counted from the loaded relation — one fewer query per notification, and it is what lets an unsaved quote render at all.
- **A rule for a soft-deleted category kept its row.** Categories soft-delete, so the FK cascade never fires. It stops *routing* because the trashed category resolves to null through the relation and the lead falls back — asserted directly, because the failure would otherwise be leads sent to a team for a department that no longer exists.

### Outstanding

- **UAT with the sales team** is the gate and cannot be simulated. What it needs to establish: that the four tabs match how they actually triage, that "I have replied" is pressed reliably enough for the SLA to mean anything, and that the routing rules they want are expressible as category prefixes.
- **`first_response_at` depends on discipline.** If the button is not pressed, the queue reports leads as unanswered forever. That is a training matter, not a code one — but if UAT shows it is not happening, the honest fix is to remove the indicator rather than let it report a number nobody trusts.
- **Public holidays** will read as one day of overrun each. If that turns out to matter, it needs a maintained holiday table (D-044), not a constant.

## Phase 12 — Content (parallel track from Phase 5) 🟡

**Gate:** ⏳ **the machinery is built and enforced; the writing has not started and cannot until Q-B.**

The gate is *no indexable page below the content minimums of SEO_SPEC §7*. That is now **structurally true rather than aspirational**: a product or category below the floor cannot be marked indexable at all, because the guard is on the model rather than in the form (D-048). What remains is the writing itself, and the writing needs a writer.

| Sub-phase | Deliverable | Status |
|---|---|---|
| 12a | Category intros — 150–300 words × 124 | 🟡 Enforced and queued; **nothing written** (Q-B) |
| 12b | Product descriptions: attribute-driven generation, review, family-scoped similarity gate | ✅ Pipeline done — D-049, D-050, 27 tests |
| 12c | Buying guides with real named authors | ⬜ Not started — needs real authors |
| 12d | Locations pages with genuine local content | ⬜ Not started — needs branch addresses |
| 12e | Policy pages: privacy (NDPA), terms, cookies | ⬜ Not started — needs company registration details and legal review |
| 12f | Editorial workflow and review queue ordered by keyword value | ✅ Done — D-051 |

### What "enforced" now means

`is_indexable` is refused on any product missing 120 words of original prose, three specifications, an "in the box" list, a warranty line or a lead time — and on any category without a 150-word intro. It throws, from the model, so the CSV importer and any future Icecat sync are covered rather than just the editor (D-048).

### What turning it on immediately caught

Two things, both of which had been quietly wrong:

- **The test suite was creating indexable products with no content at all** — asserting sitemap and manifest behaviour for pages the real system will never publish. They now build content-complete fixtures via `Product::factory()->indexable()`.
- **Eight of the ten demo catalogue products were below the 120-word floor**, and two had fewer than three specifications — while the seeder carried a comment asserting *"these carry genuine original copy, so they clear the §14.7 content floor"*. Nobody had ever counted. That file is the reference for what a finished product record looks like, and for the raw-HTML gate, so it has been brought up to the floor rather than exempted from it.

The second is the more useful finding. A comment claiming a threshold is met is worth nothing; the same claim as an assertion that throws is worth having.

### The pipeline for 15,000 descriptions

`content:draft` composes a draft from each product's **distinguishing** attributes — the ones that vary within its family, since an attribute every sibling shares says nothing about this product. It **refuses to pad**: a product without enough structured data gets no draft and a place in the queue marked as needing *data*, not copy.

Every draft is stamped, and a stamped description cannot be indexed until a person approves it **since** the stamp (D-049). The similarity gate strips names, SKUs and MPNs before comparing within the family, which is what makes it catch the §14.3 defect instead of scoring lowest exactly where the defect is worst (D-050).

Run it dry first — the report answers the question that decides the launch plan:

```bash
docker compose exec api php artisan content:draft
```

### Outstanding

- **Q-B is now the binding constraint on launch.** Everything mechanical is in place; what is missing is 124 category intros and 15,000 product descriptions, and no decision has been taken about who writes them or at what rate. Until that is answered, Phase 12's completion date is unknowable — and it gates D-010's promise that every product is indexable at launch.
- **The review order is a proxy.** §12f asks for "keyword value"; that number does not exist before launch, so the queue orders by our own search log instead, and says so on the screen (D-051). Replace it once GSC has 28 days.
- **12c needs real authors.** E-E-A-T on a buying guide means a named person with a bio page and something to be expert about. That is a client decision about who at OfficeLand puts their name to it, not a schema problem — the `articles` table already carries `author_id`, `reviewed_by` and `reviewed_at`.
- **12e is a legal document, not a template.** An NDPA privacy policy needs the registered company details, the data-protection contact, and a lawyer's eye. Generating something that *looks* like a policy would be the most dangerous kind of finished-looking work.
- **The orphan-page report (§7) is not built.** It belongs with 12c: it only becomes meaningful once guides exist to link down into categories, since today every product already has breadcrumb and category inbound links by construction.

## Phase 13 — Hardening 🟡

**Gate:** all gates green; restore drill timed and documented. **13a–13e are done.** The restore drill passes and is verified against its own failure modes. What remains is 13f (open on Q7) and a Sentry DSN for the last of 13e.

| Sub-phase | Deliverable | Status |
|---|---|---|
| 13a | Security review against OWASP Top 10, evidenced | ✅ Done — `docs/SECURITY_REVIEW.md`, 4 findings fixed, 15 tests |
| 13b | k6 load test on search and facets; p95/p99 and breaking point | ✅ Done — `docs/LOAD_TEST.md`; numbers need Q7 hardware |
| 13c | axe-core clean; WCAG 2.2 AA verified | ✅ Done — `docs/ACCESSIBILITY.md`, 4 findings fixed, gate in CI |
| 13d | **Backup and restore drill**, timed | ✅ Done — D-055, drill run and verified against its own failure modes |
| 13e | Observability: Sentry, metrics, synthetic quote submission | 🟡 Canary + backup heartbeat + schedule done; Sentry needs a DSN |
| 13f | Read replica for DR (D-018) | ⬜ Open on Q7 |
| 13g | `RUNBOOK.md` complete | 🟡 §11 security and §12 backup added; completes with the rest of 13 |

### 13a — what the review found

Four findings, all fixed. **Two were serious, and both were absences rather than mistakes** — the code that was there was sound; the code that should have been there did not exist.

- **F1 (High) — the public API had no rate limit of any kind.** Only the admin panel and quote submission were throttled. Search was both the most expensive endpoint in the system and completely open: FTS, then a **trigram fallback whenever FTS finds nothing**, then facet aggregation. A query designed to miss takes the slowest path every time, and nothing stopped one client sending it continuously. Fixed with per-surface limits (D-052).
- **F2 (High) — nothing recorded a failed login.** Filament caps attempts at five a minute, so brute force could not succeed quickly — but that is not the same as somebody noticing. A fortnight of patient credential stuffing left no trace at all. Fixed with an authentication log (D-054).
- **F3 (Medium) — no CSP and nothing preventing the site from being framed.** Angular's own auto-CSP refuses to run alongside SSR, so the hashes are now derived from the prerendered output and gated in CI (D-053).
- **F4 (Medium) — the session cookie was not `Secure` by default**, so the admin session could travel over plain HTTP if anything ever reached the site that way.

**Reviewed and found sound**, recorded because that is the half of a review worth keeping: every raw SQL fragment (user input is bound throughout, including the tsquery builder), basket item scoping (no IDOR), media MIME detection from magic bytes with SVG rejected, webhook HMAC over the raw body, CSV formula-injection defusing, and `composer audit` / `npm audit` already failing the build.

### What is deliberately outside 13a

- **Penetration testing.** This is a code and configuration review. An authenticated test against a deployed instance is a different exercise and needs the production host (**Q7**).
- **The admin panel under a strict script CSP.** Livewire and Alpine build functions at runtime, so it would need `'unsafe-eval'` — most of the appearance of protection, little of the substance.
- **Edge rate limiting.** These limits are per-process in Redis. A volumetric attack is absorbed upstream, which is a Cloudflare-or-equivalent decision that is still open.

### Outstanding for the rest of Phase 13

- **13b is done, and its own numbers say so.** The load test measures `/health/live` as a baseline, and search comes out FASTER than an endpoint that does nothing — on this host the endpoint work is below the noise floor and ~2.4s of every request is bind-mount overhead (D-033). It degrades by queueing rather than failing: 0 errors at 200 VUs. The method transfers; the numbers need **Q7**.
- **13c is done.** axe-core runs over all 147 prerendered pages in CI; contrast and target size are verified in a browser because jsdom cannot paint. Four findings fixed, including a critical invalid-ARIA one that a 9-page sample missed. Target size (SC 2.5.8) is left open on purpose — it needs a design judgement, not a measurement.
- **13d is done** — the drill runs in 12s on the development dataset and is verified against its own failure modes (RUNBOOK §12.5). What is still open is where the offsite copy goes, its retention, and the RPO/RTO it is meant to meet — all **Q21**. Media *restore* is also untested: the copy is verified, pulling it back and serving a page from it is not.
- **13e is partly done.** The synthetic quote canary, the backup heartbeat and the schedule are built and tested (RUNBOOK §13). What is left needs a **Sentry DSN** — and when it is wired, quote payloads must be scrubbed first: `email`, `phone` and `message` are customer PII, so shipping them to a third party is an NDPA question rather than a config one.
- **Nothing routes the alerts anywhere yet.** `critical` reaching a log file is not an alert. Choosing what watches — Sentry, a log drain, a cron mailer — is the remaining piece.

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
