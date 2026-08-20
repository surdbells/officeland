# Architecture

**Status:** Phase 0 deliverable. Reflects decisions D-001 … D-018 in `DECISIONS.md`.
Where this document departs from the Master Build Prompt, the departure is recorded as a decision ID.

---

## 1. Topology

D-009 removes the Node SSR runtime from production. Catalogue pages are prerendered to static HTML and served directly by Caddy; the API serves only genuinely dynamic surfaces.

```
                    ┌────────────────────────────────────────────┐
   Search engines   │  Caddy / FrankenPHP edge (HTTP/3, br+gzip)  │
   & browsers ─────▶│  TLS, HSTS                                  │
                    │  ├── /  /c/**  /p/**  /brands/**  /guides/** │──▶ static HTML
                    │  │      served from disk, no PHP, no Node    │    (prerendered)
                    │  ├── /media/**  immutable, max-age=31536000  │──▶ static derivatives
                    │  └── /api/v1/**                              │
                    └───────────────────────┬────────────────────┘
                                            │
                              ┌─────────────▼──────────────┐
                              │ Laravel 11 + Octane        │
                              │ (FrankenPHP worker mode)   │
                              │ + Filament admin           │
                              │                            │
                              │ serves ONLY:               │
                              │  search · typeahead        │
                              │  facet queries             │
                              │  quote basket + submission │
                              │  delivery/lead-time lookup │
                              │  admin                     │
                              └───┬──────────────────┬─────┘
                                  │                  │
                    ┌─────────────▼──────┐   ┌───────▼────────┐
                    │ PgBouncer          │   │ Redis 7        │
                    │ (transaction pool) │   │ cache · queue  │
                    └─────────┬──────────┘   │ session · lock │
                              │              └───────┬────────┘
                    ┌─────────▼──────────┐           │
                    │ PostgreSQL 16      │           │
                    │ primary            │           │
                    │ (replica: Phase 13,│   ┌───────▼─────────────┐
                    │  D-018)            │   │ Queue workers        │
                    └────────────────────┘   │  · render worker     │
                                             │  · media derivatives │
                                             │  · Icecat ingestion  │
                                             │  · mail (ZeptoMail)  │
                                             └───────┬──────────────┘
                                                     │
                              ┌──────────────────────┴───────────┐
                              │                                  │
                   ┌──────────▼──────────┐          ┌────────────▼────────┐
                   │ Static site root    │          │ ZeptoMail HTTP API  │
                   │ (Flysystem local)   │          │ (no SMTP, §13)      │
                   │ + media derivatives │          └─────────────────────┘
                   └─────────────────────┘
```

**What this buys.** Catalogue TTFB is disk-read latency. Googlebot crawling 15,000 product pages never touches PHP or Postgres. The failure surface for the highest-traffic paths is Caddy alone.

**What it costs.** A publish is no longer instantaneous — it enqueues a render. See §4.

## 2. Repository layout

```
officeland/
├── apps/
│   ├── api/                 # Laravel 11 + Octane + Filament
│   └── web/                 # Angular storefront (prerendered)
├── packages/
│   └── contracts/           # OpenAPI 3.1 spec + generated TS types
├── infra/
│   ├── docker/              # frankenphp, node-render, pgbouncer
│   ├── caddy/
│   ├── pgbouncer/
│   ├── systemd/
│   └── backup/
├── docs/
└── scripts/
    ├── ingest-icecat/
    └── seo-audit/
```

TypeScript types are generated from the OpenAPI spec. A contract change not reflected in `packages/contracts` is a failed gate.

## 3. Category tree storage — materialized path

§7.1 requires a justified choice between `ltree` and a materialized path. **Materialized path is chosen.**

`categories.path` holds `/office-furniture/seating/executive-chairs/`, indexed with `text_pattern_ops`.

| Requirement | How it is met |
|---|---|
| Full breadcrumb in one query | Split the path in the application, or `WHERE :path LIKE path \|\| '%'` for ancestor rows |
| Whole subtree in one query | `WHERE path LIKE '/office-furniture/%'` — index-backed prefix scan |
| Depth ≤ 3 | `depth` column with a CHECK constraint, maintained alongside `path` |

**Why not `ltree`:** ltree labels permit only `[A-Za-z0-9_]`. Every slug in this taxonomy is hyphenated (`office-furniture`, `ergonomic-task-chairs`), so ltree would require storing a transliterated second representation and translating on every read and write — permanent friction for a lookup table of **124 rows**. At that size the performance advantage of GiST-indexed ltree operators is unmeasurable, and materialized path avoids a further extension dependency.

Revisit only if the taxonomy exceeds ~10,000 nodes or depth 3 is lifted.

## 4. Static prerender pipeline (D-009)

Angular prerenders all routes at build time. **Incremental, publish-triggered regeneration is not a framework feature — it is built here.** Budgeted at 2–3 weeks in Phase 8.

### 4.0 What exists today (Phase 7)

The **full rebuild** works end to end and is verified in CI. The **incremental** half is still Phase 8; `RouteResolver` and `RenderQueue` are built and unit-tested but nothing consumes them yet.

```
ng build
   │
   ├─ getPrerenderParams ──▶ GET /api/v1/render/manifest ──▶ 124 category paths
   │                                                          + every active product slug
   │
   └─ for each route: bootstrap the app in Node
                        │
                        ├─ HttpClient issues '/api/v1/...'
                        │      platform-server rewrites it to the builder's
                        │      throwaway localhost server  ← wrong target
                        │      ServerApiBackend rewrites it again, by PATH,
                        │      to PRERENDER_API_ORIGIN     ← D-027
                        │
                        └─ HTML + <script id="ng-state"> transfer cache
```

Measured on the development stack: **136 routes in ~200 s**, all 200s, zero failures.

Three properties of this flow are load-bearing and easy to break:

1. **The manifest is not filtered by `is_indexable`.** Rendered, indexed and listed-in-the-sitemap are three different questions. Every category is linked from the megamenu and from its parent's child list (D-011), while all 124 seed as `is_indexable = false` pending intro copy. Filtering the manifest would render almost nothing while the header linked to all of them — failing the orphan gate and the crawl-depth gate at once. The invariant is one-directional: **sitemap ⊆ manifest**.
2. **The manifest is not paginated.** A partial manifest is indistinguishable from a complete one at the point of use: the build would succeed and silently publish a subset of the catalogue.
3. **A build that cannot reach the API fails loudly**, unless `PRERENDER_ALLOW_EMPTY=1` is set. Rendering zero product pages is a catastrophic, silent outcome; local and typecheck builds opt out explicitly.

**Concurrency is a first-class property of this pipeline, not an incidental one.** Angular prerenders with one worker per core. On a 20-core machine that is the heaviest concurrent load anything in the system generates, and it is generated by a build rather than by traffic. The first run of it exposed a database bug that four phases of testing had missed (D-024). Any future work here should assume the render pipeline is where concurrency bugs surface first.

### 4.1 Components

**Render worker.** The Angular server bundle, invoked as a build-time renderer rather than a request server. A queue job passes it a route; it returns HTML.

**Route resolver.** Maps a domain event (product published, category edited, brand updated) to the set of routes requiring regeneration. See §4.3.

**Atomic writer.** Writes to a temporary file and `rename()`s into place. `rename()` is atomic within a filesystem, so Caddy never serves a partially written page.

**Full-build command.** Regenerates everything. Required after any template or global-SEO change, which no publish event can detect. Part of the deploy procedure, and its wall-clock time is measured and recorded in `RUNBOOK.md`.

### 4.2 Flow

```
Filament save
      │
      ▼
  domain event  ──▶  RouteResolver  ──▶  routes[]
                                            │
                                            ▼
                                  Redis queue (deduplicated by route,
                                  debounced ~60s to coalesce bulk edits)
                                            │
                                            ▼
                                  Render worker ──▶ HTML
                                            │
                                            ▼
                                  Atomic write to static root
                                            │
                                            ▼
                                  Sitemap lastmod updated
```

Debouncing matters: a bulk import touching 500 products must not enqueue 500 × N renders immediately. The queue deduplicates by route and coalesces within the debounce window.

### 4.3 The invalidation graph

This is gap **G7**, and under D-009 it is central rather than peripheral. Publishing one product invalidates:

| Target | Why |
|---|---|
| `/p/{slug}` | The product itself |
| `/c/**` for every category it belongs to | It appears in the grid |
| …and the specific pagination page(s) it lands on | Position depends on sort order |
| `/brands/{brand}` | Brand hub listing |
| `/brands/{brand}/{category}` | Where a curated hub exists (D-017) |
| `/` | Only if merchandised into a homepage slot |
| **Other products' PDPs** | Related-product rails are baked into HTML |
| `sitemap-products-N.xml` | `lastmod` |

**The related-rails cascade is the hard case.** Rails must be in the HTML — §14.7 counts them as internal linking, and internal linking is a primary ranking asset for a new domain. Rendering them client-side would make them invisible to crawlers.

**Resolution:** rails stay in the HTML; cascade regeneration is **bounded and deferred**. A product change enqueues its direct routes at normal priority and its reverse-related set at low priority with a longer debounce. A related rail displaying slightly stale data for a few minutes has no user or SEO cost; a synchronous cascade across hundreds of pages per edit does.

**Bounding rule:** the reverse-related set is capped per event. If a change would cascade beyond the cap (e.g. re-categorising a whole department), the resolver escalates to a scheduled partial rebuild of the affected subtree instead of enqueueing individual routes.

### 4.4 Dynamic surfaces (not prerendered)

Client-rendered against the API, all `noindex` per §14.2, so nothing of search value depends on them:

`/search` · `/quote` and `/quote/**` · facet-filtered category views (query parameters) · the delivery/lead-time checker.

Facet filtering is a client-side call against the API layered over the prerendered category page. The unfiltered page is static and indexable; filtered states are query-parameter variants carrying `noindex,follow` and a canonical to the clean URL (§14.5).

## 5. PgBouncer and connection management

**Mode: transaction pooling** (§10.1).

**Consequences, and how each is handled:**

| Consequence | Handling |
|---|---|
| Server-side prepared statements do not survive transactions | `PDO::ATTR_EMULATE_PREPARES => true` in the connection options. An integration test asserts parameterised queries behave correctly **through the pooler** — a suite bypassing PgBouncer cannot catch this class of bug. |
| Session-level `SET` does not persist | `search_path` is set as a role default via `ALTER ROLE … SET search_path`, never per-session. This is a correction to §10.1's wording. |
| `LISTEN/NOTIFY`, advisory locks across transactions, `WITH HOLD` cursors unavailable | Nothing in the design requires them. Locking uses Redis. Should a need arise, it routes through a separate direct-to-Postgres connection that bypasses the pooler, and is documented here. |
| Migrations | Connect **direct to Postgres**, never through PgBouncer. |

**Separate pools and roles:** `app_write` (primary), `admin` (Filament), `queue` (workers, including the render worker), `migrate` (direct).

**Pool sizing** is derived, not guessed:

```
default_pool_size = octane_workers × connections_per_worker × safety_factor
max_client_conn   = sum of all pool sizes × client_multiplier
```

`octane_workers` follows from the server's core count. **This arithmetic cannot be completed until the server specification is supplied (open question Q7)** and is a Phase 1 blocker, not a Phase 0 one.

## 6. Read/write splitting

D-018 defers the read replica to Phase 13. Until then, all connections target the primary.

The connection configuration is nevertheless written with `read`/`write` split and `'sticky' => true` from Phase 2, so introducing the replica is configuration rather than refactoring. Under Octane the container persists across requests, and `sticky` depends on per-request state; combined with transaction pooling a subsequent read may land on a different server connection. **An integration test asserting read-your-own-writes through the pooler is a Phase 2 gate** (gap G8) and must exist before the replica is introduced, not after.

Quote submission and every admin mutation are explicitly pinned to the primary.

## 7. Icecat ingestion (Phase 5b)

### 7.1 Access model — verified 2026-08-19

| | Open Icecat | Full Icecat |
|---|---|---|
| Cost | Free | Paid subscription |
| Coverage | 600+ sponsoring brands | Icecat's full catalogue |
| Access level token | `freexml` | `level4` |

Verified present in Open Icecat: **HP, ASUS, Lenovo, Canon, Dell, Epson, Brother**.
Verified **absent**: **Baseus** — present in the wider catalogue at 50% data health (community-sourced, not brand-authorised). See open question Q-A.

Both tiers require an account. Access is IP-whitelisted, or authenticated with Dynamic Tokens where the egress IP is not stable. Non-whitelisted requests return 404, not 403 — worth knowing when debugging.

**Credentials never need to reach the development team.** The adapter reads its token from `.env` or the Filament settings panel; the client enters it on their own server. This is the §18 secrets requirement and applies equally to any future HP Catalog Service or distributor adapter.

### 7.2 Endpoints

```
Export (by Icecat ID):
  https://data.icecat.biz/export/{access_level}/{lang}/{icecat_id}.xml

Search (by MPN + brand, or GTIN):
  https://data.icecat.biz/xml_s3/xml_server3.cgi
      ?lang={lang}&prod_id={mpn}&vendor={brand}&output=productxml
```

Lookup keys: Icecat ID · MPN (`prod_id`) · GTIN/EAN/UPC · brand.

**Rate limits:** 100 concurrent connections per IP maximum; Icecat recommends 50–75. The ingestion queue is throttled to **50** and is the shared ceiling across product fetches and image mirroring.

**Incremental sync:** repository index files carry an `Updated` attribute (`YYYYMMDDHHMMSS`), and `HEAD` requests return `Last-Modified`. Sync therefore polls the index, compares timestamps against `last_synced_at`, and fetches only changed products. Full re-fetch is a fallback, not the normal path.

### 7.3 Adapter design

```
interface ProductSource {
    supports(brand): bool
    search(criteria): SourceProductRef[]
    fetch(ref): SourceProduct          # normalised DTO
    changedSince(timestamp): SourceProductRef[]
}
```

`IcecatAdapter` is the only implementation at launch. `HpCatalogAdapter` and `CsvFeedAdapter` are later additions requiring no pipeline change. Manual Filament entry sits underneath and always works.

**Matching:** MPN + brand → GTIN/EAN → Icecat ID. Idempotent upsert. Every ingestion decision is written to a review queue with a diff preview; nothing publishes unreviewed.

**Field policy (D-015):**

| Ingested and used verbatim | Ingested as draft only |
|---|---|
| MPN, model code, EAN/GTIN | Marketing prose |
| Specifications and attribute values | Product title (rewritten per D-015 format) |
| Dimensions, weights, warranty terms | |
| Images and datasheet PDFs | |
| Box contents | |

**Images are mirrored, never hotlinked** — the §12 derivative pipeline and CWV budgets both require local control. At ~5 images per product across 15,000 products this is ~75,000 downloads, throttled with the shared connection ceiling, and is a first-class workstream rather than a detail.

## 8. Media pipeline at scale

| Quantity | Estimate |
|---|---|
| Original images | ~75,000 |
| Derivatives (5 variants × 3 formats) | **~1,125,000 files** |
| Total files including originals | ~1.2M |
| Disk | **~50 GB** |

The §12 content-addressed layout (`{sha[0:2]}/{sha[2:4]}/{sha}/`) yields 65,536 leaf directories, ~18 files each — comfortable for any modern filesystem, and the reason date-based paths were rejected.

**Consequences:**
- Initial generation is ~1.1M libvips operations. The job must be **resumable, throttled and idempotent** — not a one-shot batch.
- The §20 Phase 4 gate rises from a 10k-image soak to **75k**.
- §19.4 offsite backup is 50 GB+ with recurring cost. Destination and budget are open (Q21).

libvips via `intervention/image` v3 with the vips driver. GD is not viable at this volume.

## 9. Octane practices (§9)

Unchanged by D-009 — the API still runs under Octane.

1. No mutable static state. Every static property introduced is audited.
2. No singletons holding request-scoped data. Any singleton touching the request is documented here with justification.
3. `RequestReceived` / `RequestTerminated` listeners reset stateful packages.
4. `octane.warm` and `octane.flush` explicitly configured.
5. **Memory gate:** a 10,000-request soak must show worker RSS stabilising. `--max-requests` is a safety net, not a fix.
6. `octane:reload` after every deploy — in the deploy script and in `RUNBOOK.md`.
7. No filesystem writes assuming request isolation; unique tempfile names only.

Note that the render worker is a **separate Node process invoked by queue jobs**, not part of the Octane worker pool. It has its own memory profile and its own soak gate.

## 10. Caching

With D-009 the caching story simplifies considerably — there is no SSR render cache and no CDN HTML cache to invalidate.

| Layer | Policy |
|---|---|
| Prerendered HTML | Served from disk. Correctness comes from regeneration (§4), not TTL. |
| `/media/**` | `public, max-age=31536000, immutable` — content-addressed paths make this safe |
| Static JS/CSS | Content-hashed filenames, far-future cache |
| `/api/v1/**` reads (search, facets) | `public, max-age=60, stale-while-revalidate=600`, strong `ETag`, `304` on `If-None-Match` |
| `/api/v1/quote*` | `private, no-store` |
| Laravel cache tags | Entity-tagged; a product save invalidates its own tags **and** enqueues the render routes of §4.3 |

## 11. Security posture (§18)

Unchanged in substance. Notable simplifications from D-003 and D-005: no storefront authentication, no tokenised quote-response links, and no PDF generation — three attack surfaces removed.

Retained: CSP with nonces and no `unsafe-inline`; HSTS; `X-Content-Type-Options`; `Referrer-Policy`; `Permissions-Policy`; strict CORS allowlist; CSRF on state-changing routes; Redis-backed per-IP and per-route rate limiting, stricter on quote submission and search; Form Request validation at the boundary; HTML purification of all admin-authored rich text; 2FA on admin with a separate path and optional IP allowlist; full mutation audit log.

Static HTML serving means the highest-traffic paths execute no application code at all, which is a meaningful reduction in exposure.
