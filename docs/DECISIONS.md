# Decision Log

Every decision that departs from, resolves an ambiguity in, or closes an open question from the Master Build Prompt. Append-only: supersede entries, never edit them.

Format: **ID · Decision · Rationale · Source · Date**

---

## Closed by the client

### D-001 — No data migration from the legacy site
**Decision:** Nothing is migrated from WordPress/WooCommerce. The catalogue is built fresh.
**Consequence:** §17 (extraction, deduplication, taxonomy remap, content rewrite pipeline) is out of scope. Gaps G1 (SKU as deduplication key), G4 (70% similarity import gate) and G13 (migration sequencing) are void.
**Source:** Client, 2026-08-19.

### D-002 — No public pricing (§5.3 Option A)
**Decision:** No prices are displayed anywhere on the storefront.
**Consequence:** `Product` JSON-LD is emitted without `offers`; no price facet, no price sort, no was/now display. Ineligible for price rich results — the accepted, documented cost of an RFQ model per §14.4.
**Implementation note:** `price_ngn`, `compare_at_price_ngn`, `price_valid_until` and `pricing_mode` are still created as nullable, unused columns so posture C can be enabled later without a migration, exactly as §5.3 instructs.
**Source:** Client, 2026-08-19.

### D-003 — No customer accounts
**Decision:** Quoting is fully anonymous. No storefront auth, no quote history, no `/account/**` routes.
**Consequence:** The `customers` table is not built; quotes stand alone.
**Source:** Client, 2026-08-19. Resolves §22 Q3.

### D-004 — Quote form captures email, phone and optional message
**Decision:** Three fields only, replacing the §7.2 field set (contact_name, company_name, delivery_state/city/address, is_government_entity, required_by, budget_band).
**Flagged and not adopted:** optional `name` and `company` fields were recommended — without company, institutional enquiries cannot be qualified or routed by customer type. Client retained the three-field form.
**Consequence:** §5.5 per-location quote routing is not implementable (no location captured). Per-category routing remains possible, derived from basket contents.
**Source:** Client, 2026-08-19.

### D-005 — Lead capture only; no priced quote cycle
**Decision:** §5.1's back half is out of scope. Sales prices and responds to quotes internally, outside the system.
**Consequence:** No pricing UI, no margin display, no Kanban board, no priced-quote PDF, no tokenised accept/reject/revision flow. Filament receives a lead queue with list/detail, filters, assignment, notes and status. Retires gap G11 (Filament Kanban plugin availability was the most likely trigger for the §6.3 v3.3 fallback).
**Source:** Client, 2026-08-19.

### D-006 — Customer receipt is HTML email body only
**Decision:** The quote confirmation receipt is an HTML email containing full request details. No PDF attachment.
**Consequence:** **Dompdf is removed from the stack** (§6.3). Nothing in the system generates a PDF. Basket export proceeds as **CSV only** — no library required, and it is the format that pastes into a requisition.
**Source:** Client, 2026-08-19.

### D-007 — Legacy site is replaced on the same domain, with a redirect layer
**Decision:** `officelandng.com` is taken over by the new site. A redirect layer is built despite there being no data migration.
**Rationale:** 5,063 legacy product URLs and 161 category URLs would otherwise 404 on cutover, discarding all accumulated organic equity — directly contrary to §1. Redirects require no data import and cost days, not months.
**Consequence:** `docs/REDIRECT_MAP.csv` and the `redirects` table are in scope. See D-016.
**Source:** Client, 2026-08-19.

### D-008 — Product URLs at `/p/{slug}`, categories at `/c/{path}/`
**Decision:** §14.2's URL scheme is retained. Root-level product slugs were considered and rejected.
**Rationale:** Flat product URLs (one product, one URL, regardless of category membership) were already delivered by `/p/{slug}`. Moving to root-level adds no ranking benefit — URL structure is not a keyword-proximity ranking factor — while permanently reserving every product slug against every current and future site section.
**Source:** Client accepted the recommendation, 2026-08-19.

### D-009 — Full static prerendering with incremental, publish-triggered rebuilds
**Decision:** No Node SSR process in production. All catalogue pages are prerendered to static HTML and served by Caddy. A publish in Filament enqueues affected routes for regeneration.
**Rationale:** Viable specifically because D-002 removes prices and there is no live stock — product data changes only on editorial action. Delivers the lowest achievable TTFB with the simplest production estate.
**Consequence:** This is a custom pipeline, not an Angular feature. See ARCHITECTURE.md §4. Client-rendered `noindex` surfaces (search, quote basket, quote form) are unaffected.
**Source:** Client, 2026-08-19.

### D-010 — All 15,000+ products indexable at launch
**Decision:** Gated indexability was proposed and rejected. Every product is indexable on day one.
**Recorded risk:** §14.7 requires ≥120 words of original copy per product — approximately 1.8M words at this catalogue size. Publishing Icecat prose verbatim would reproduce legacy defect §4B/C at three times the scale on a domain with no accumulated authority.
**Mitigation, agreed:** product titles and descriptions are rewritten rather than syndicated verbatim (D-015). Pages carry grouped spec tables, images, breadcrumbs and internal links so that page value does not rest on prose alone.
**Source:** Client, 2026-08-19.

---

## Taken by the architect, pending objection

### D-011 — Category pages render their full child list as crawlable anchors
**Decision:** Every category page renders all child categories as real `<a href>` elements. The megamenu itself stays at levels 1–2.
**Problem solved:** §14.5 requires every product within 3 clicks of the homepage; §7.1 caps categories at depth 3; §23.1 rule 2 loads level-3 menu links on intent only. Together these make products 4 clicks minimum and the gate unpassable. Rendering child anchors on category pages gives home → department (1) → leaf (2) → product (3) without inflating header weight.
**Status:** Architect decision. Invisible at small catalogue sizes; structural at 15,000 products.

### D-012 — G5: curated facet pages render as real anchors; ad-hoc filters as buttons
**Decision:** §14.5 rule 3 (filters as buttons, not crawlable links) applies to ad-hoc filter state only. The whitelisted indexable facet combinations of rule 4 render as genuine `<a href>` links.
**Rationale:** As written, rules 3 and 4 contradict: if all filter UI is buttons, the curated facet landing pages have zero internal links and cannot be discovered or accrue authority.
**Status:** Architect decision, awaiting objection.

### D-013 — G6: paginated pages get self-referencing canonical + `index,follow`
**Decision:** Pages 2..N carry a self-referencing canonical and are indexable, rather than `noindex,follow`.
**Rationale:** §14.5's pagination rule is internally inconsistent — it justifies self-canonicals by discovery, then applies `noindex`, and long-lived `noindex` pages lose crawl scheduling over time, producing the exact deep-discovery loss the rule exists to prevent. At ~200 products per leaf (~9 pages), a large share of the catalogue sits on page 2+.
**Status:** Architect decision, awaiting objection.

### D-014 — G10: Lighthouse budgets enforced on the median of 5 runs
**Decision:** CI fails on the median of 5 throttled mobile runs, not a single run, with a documented tolerance band.
**Rationale:** Throttled Lighthouse runs have material variance; single-run hard gates red-build on noise, which trains the team to ignore the gate.
**Status:** Architect decision, awaiting objection.

### D-015 — Product titles and descriptions are rewritten, not syndicated
**Decision:** Icecat structured data (MPN, model codes, EAN, specifications, dimensions, images, datasheets) is used verbatim — these are facts and duplication is harmless. Descriptive prose is **not** published as received.
**Title format** — client specified `[code] [brand] [name]`; the ordering was amended per category family on the architect's recommendation:

| Family | Format | Example |
|---|---|---|
| Consumables / IT (code is a real query) | `[Brand] [Name] ([Code])` | `HP 128A Black LaserJet Toner Cartridge (CE320A)` |
| Furniture (code is internal) | `[Descriptive Name] ([Code])` | `Mesh-Back Ergonomic Task Chair with Headrest (CM-B146AS)` |

**Rationale:** leading with the product code spends the most valuable characters of a ~60-character SERP snippet on the lowest-volume token, and for furniture reproduces legacy defect §4D. The code remains fully searchable in both formats via the title, H1, spec table, `mpn`/`model_code` fields and the synonym table (§16).
**Note:** `products.name` and `meta_title` are distinct fields. The §14.3 template applies on top and drops segments in priority order to hold the 60-character budget.
**Status:** Client specified the format; architect amended the ordering. **Awaiting confirmation.**

### D-016 — Legacy product URLs redirect to categories, not products
**Decision:** The 161 legacy category URLs map one-to-one to new categories or brand hubs (complete — see `REDIRECT_MAP.csv`). The 5,063 legacy product URLs map to their best-matching new **category**, except where a genuine equivalent exists in the new catalogue matchable by MPN or model code, which map product → product.
**Rationale:** With no data migration the new catalogue holds different SKUs; one-to-one product mapping is impossible for most URLs. A relevant category is a far better destination than a 404 and materially better than a homepage redirect.
**Status:** Category half complete in Phase 0. Product half deferred to Phase 14, when the new catalogue exists.

### D-017 — Brand × category hubs absorb legacy brand-category URLs
**Decision:** Legacy `/product-category/hp/{type}/` URLs redirect to curated `/brands/hp/{type}/` hubs rather than to plain category pages.
**Rationale:** §17.2 requires mapping to whichever destination better matches the intent the URL currently earns. `/product-category/hp/printers/` earns "HP printers" intent, which a brand × category hub matches exactly and a generic printers category does not. These five HP hubs are the first entries in the §14.5 rule 4 indexable facet whitelist and have earned their place through existing traffic.
**Status:** Architect decision.

### D-018 — PgBouncer at launch; read replica deferred to hardening
**Decision:** PgBouncer in transaction pooling mode from Phase 1. The PostgreSQL read replica is deferred to Phase 13 and justified on disaster-recovery grounds rather than load.
**Rationale:** D-009 collapses request-path database load — crawlers and users hitting 15,000 prerendered pages touch flat files, not Postgres. The database serves only search, typeahead, facets and quote submission. This position moved twice as the rendering decision moved; it is now locked. If the replica is wanted at launch for DR, it returns to Phase 1.
**Status:** Architect decision, awaiting objection.

### D-019 — Laravel 12, not Laravel 11
**Decision:** the API is built on Laravel 12 (installed: v12.67.0). §6.3 fixes Laravel 11 as a client stack choice.
**This is forced, not preference.** Every Laravel 11 release — v11.31.0 through v11.55.1 — is blocked by Composer's security-advisory policy. Verbatim from the resolver:

> `laravel/framework[v11.31.0, ..., v11.55.1] ... these were not loaded, because they are affected by security advisories`

Six advisories apply across the 11.x line, including reflected XSS via route and request parameters on the debug-mode error page. Installing would require `policy.advisories.block: false` or explicitly ignoring advisory IDs.

**Why substitution was chosen over suppression:** §18 requires OWASP Top 10 to be addressed and evidenced, and §19.3 requires dependency scanning in CI. Shipping a framework with known unpatched advisories to a B2B/B2G site handling customer contact data fails both, and `composer audit` would fail the pipeline on the first run. Laravel 12 resolves cleanly and audits clean.
**Verified:** `composer audit` → *No security vulnerability advisories found.*
**Status:** taken under §2.1's escalation rule. **Client notification required** — this changes a stack choice §6.3 marked as fixed.

### D-020 — Angular 22 and Filament v5
**Decision:** Angular 22 (installed: 22.1.0, `@angular/ssr` 22.1.5, TypeScript 6.0.2) and Filament v5, replacing §6.3's Angular 21 / Filament v4.
**Rationale:** both specified versions are now the previous major. Filament v5 shipped January 2026; Angular 22 is current with incremental hydration enabled by default under `provideClientHydration()`. D-005 removed the Kanban dependency, so the §6.3 plugin-availability fallback to v3.3 is not needed.
**Source:** client, 2026-08-20. Closes Q-C.

### D-021 — Node 24.19+ required; frontend toolchain runs in Docker
**Decision:** the Angular toolchain runs inside the `node:24-alpine` container rather than on the host.
**Rationale:** Angular CLI 22 requires Node ≥24.15.0 and the development host runs 24.14.0. Rather than modify the developer's system Node, the containerised toolchain is authoritative — which also makes local builds identical to CI and production. `node_modules` lives in a named volume so Linux-native binaries never land in the Windows bind mount.
**Consequence:** CI pins Node 24.19.0. Developers wanting to run `ng` directly on the host must upgrade to ≥24.15.0; nothing requires them to.

### D-023 — libvips for imaging, chosen by measurement
**Decision:** libvips (via `jcupitt/vips`, FFI), not GD or ImageMagick.
**Measured** on a real 1024x1536 product photograph from the legacy site, producing the full 5-variant x 3-format derivative set:

| driver | time | total output |
|---|---|---|
| Imagick | 4,977 ms | 1,414 KB |
| GD | 1,585 ms | 1,306 KB |
| **libvips** | 3,371 ms | **808 KB** |

**Rationale:** libvips output is ~40% smaller at equivalent quality. GD is the fastest and would blow the 800 KB PDP page-weight budget (section 14.9) on images alone. Generation is a one-time build cost; bytes are paid by every visitor on metered Nigerian mobile data forever.
§12.3 asked for libvips and for the choice to be justified — it is, on evidence rather than assumption.
**Status:** architect decision, measured.

### D-024 — Neither §10.1 pooling strategy works; the fix is `PDO::PGSQL_ATTR_DISABLE_PREPARES`
**Decision:** `max_prepared_statements = 0`, `PDO::ATTR_EMULATE_PREPARES => false`, **and `PDO::PGSQL_ATTR_DISABLE_PREPARES => true`**.

**Supersedes the Phase 4 version of this decision**, which named the right two settings and drew the wrong conclusion from them. See *Correction* below.

**The actual root cause.** `pdo_pgsql` gives every server-side prepared statement a NAME (`pdo_stmt_00000001`, …) and caches it on the PHP-side connection. Under transaction pooling the `Parse` can land on one server connection and a later `Execute` on another, which never saw it:

```
ERROR: prepared statement "pdo_stmt_00000001" does not exist
```

`PGSQL_ATTR_DISABLE_PREPARES` routes each statement through `PQexecParams`: parameters are still bound **by the server** — so types, booleans and escaping stay correct — but nothing is named, so there is nothing to be missing. It is a third option §10.1 does not mention, and the only one that holds.

**Why the two documented strategies still do not work:**
- *PgBouncer prepared-statement support* (`max_prepared_statements > 0`) breaks transactions. Re-verified against PgBouncer 1.25.2 in Phase 7: four `PgBouncerTransactionTest` cases fail with it raised and pass with it at 0.
- *PDO emulation* breaks boolean binding — emulation interpolates client-side and renders a PHP `true` as a bare `1`, which PostgreSQL will not coerce to boolean (42804). Found in Phase 2.

**Correction to the Phase 4 conclusion.** That version claimed the working configuration was "neither", and that a pinned server connection "is all that was ever required". Both halves were wrong:

- The `prepared statement does not exist` error is present in **both** configurations. Raising `max_prepared_statements` did not introduce it; it made it visible *inside* transactions, where one failed statement poisons every statement after it as `25P02`. The `25P02` was a downstream symptom and was mistaken for the cause. PostgreSQL's own log carried the real first error the whole time.
- Pinning holds only for the duration of a transaction. It says nothing about a `PDOStatement` re-executed across requests — which is exactly what an Octane worker does with a persistent PDO connection.

**How it escaped for four phases.** Every existing test drove one client connection and executed each statement once. `pdo_pgsql` defers the parse until the first execute, so a statement executed once has its parse, bind and execute inside a single implicit transaction on a single server connection and cannot fail this way. The failure needs **re-execution across a reassignment**, which nothing exercised. The suite was not weak on assertions; it was testing a shape the bug does not live in.

**How it was found.** The Phase 7 prerender ran twenty concurrent workers against the API. 64 of 136 pages rendered "not found"; the API had returned 134 500s, every one of them this error. It was the first genuinely concurrent load the API had ever seen — which is worth noting on its own, because the production render pipeline is the only component that generates load like this.

**Guarded by:** `PgBouncerConcurrencyTest`, which reproduces it deterministically in one process — prepare, execute once, churn the pool with other clients, re-execute. Both re-execution cases were confirmed to **fail** with `PGSQL_ATTR_DISABLE_PREPARES` set to `false`. An earlier draft that executed each statement only once passed against the broken configuration and was replaced; it had reproduced the original blind spot exactly.

**Status:** architect decision, reproduced by toggling one value with everything else held constant, and verified end-to-end by a full 136-route prerender with zero page failures and zero non-200 responses.


### D-025 — Initial media build is a scheduled operation, not a background trickle
**Decision:** the first full derivative build is planned and timed work.
**Measured** at 20 distinct sources through the real pipeline: **3.60 s per image**, 15 derivatives each, ~552 KB of derivatives per image.
Extrapolated to the 75,000-original target: **~75 hours single-worker, ~9.4 hours at 8 workers, ~42 GB**.
**Consequence:** Phase 5b must schedule this rather than assume it happens quietly. It also sets the floor for any full re-encode (a quality change, a new format), which is the same 9.4 hours.

### D-026 — Image builds must be verified, not assumed
**Discovered:** Phase 6, 2026-08-20.
**What happened:** the Phase 4 media pipeline was developed and its gate measured against a container into which libvips and ext-ffi had been installed **ad hoc** for the driver benchmark. The `docker compose build` that was supposed to bake them into the image timed out, and php-vips continued working — so the build was assumed to have succeeded. It had not. The image on disk predated the Dockerfile change, and every `--force-recreate` afterwards reverted to an image with no libvips.

**What was and was not affected:**
- The Phase 4 code is correct and its measurements were real — they ran against real libvips.
- The **image was not reproducible**, so a fresh checkout, a new machine or CI would have failed at the first media test with `FFI extension not loaded`.

**Rule adopted:** after any Dockerfile change, assert the change is present **in the image**, not in a running container:

```
docker run --rm --entrypoint sh <image> -c 'php -m | grep -c ffi'
```

A container that works proves nothing about the image when anything has been installed into it by hand. The container healthcheck does not catch this either, because the application boots fine without libvips — only media work fails.
**Status:** architect finding; the rebuild is verified in Phase 6.

### D-027 — Prerender reaches the API through a server-only `HttpBackend`, not an interceptor
**Decision:** during prerendering, `/api/**` requests are redirected to `PRERENDER_API_ORIGIN` by `ServerApiBackend`, provided as `HttpBackend` in `app.config.server.ts` only.

**The problem.** Application code requests `/api/v1/navigation`. In the browser that is same-origin and correct. During prerendering, `platform-server`'s `relativeUrlsTransformerInterceptorFn` resolves relative URLs against the current document origin — which, in a prerender, is the throwaway HTTP server the Angular builder starts on a random port to invoke `server.ts`. The request comes back to the app's own Express handler, which has no `/api` route: `Error: Unable to handle request: '/api/v1/navigation'.`

So this is not a missing base URL. It is a base URL that is present and wrong, applied by a root interceptor that runs before any backend — which is why the redirect matches on the **path**, not on the absence of an origin.

**Why a backend and not an interceptor.** §14.1 requires hydration not to re-fetch what the render already fetched, and counts duplicate hydration requests as a gate failure. The transfer cache interceptor keys entries on the request URL as it sees it, and it is registered ahead of `platform-server`'s transformer, so on the server it keys on the **relative** URL, exactly as the browser will. Rewriting anywhere in the interceptor chain risks keying the server's entries on a random localhost port the browser can never reproduce — every entry misses, and all 15,000 pages re-fetch on hydration. A backend sits below every interceptor, so the keys stay untouched.

**Why not `HTTP_TRANSFER_CACHE_ORIGIN_MAP`.** It is the framework's tool for the neighbouring problem, but it maps one absolute origin onto another and cannot map onto a relative path (`if (!mappedOrigin) return url` treats `''` as absent). Committing the browser to an absolute origin would bake the deployment hostname into the bundle, so a staging build would call production.

**Verified:** the prerendered HTML carries an `ng-state` script containing the navigation and product payloads, keyed relatively.
**Status:** architect decision, found by a failing prerender and fixed against the framework's actual interceptor ordering.

### D-028 — A custom `UrlSerializer` keeps the trailing slash on every internal link
**Decision:** `TrailingSlashUrlSerializer` replaces Angular's `DefaultUrlSerializer`.

Angular drops trailing slashes when serialising, so `routerLink="/c/office-furniture/"` renders `href="/c/office-furniture"`. Under D-009 the page is a file at `/c/office-furniture/index.html`, so Caddy serves the slashless form by redirecting to the canonical one. Every internal link on the site would be a redirect hop: a crawler following the megamenu across 15,000 products pays for 15,000 avoidable redirects, the redirect gate's "zero chains" rule is broken by the site's own navigation, and the link graph splits against the canonicals `SeoService` emits — which always carry the slash.

The root is left alone, and any last segment containing a dot is left alone so `/favicon.ico` and `/sitemap.xml` do not gain a directory slash. Query strings and fragments are preserved: the slash belongs to the path.

**Guarded by:** the raw-HTML gate, which fails on any internal `href` lacking a trailing slash.
**Status:** architect decision, found by inspecting prerendered output rather than by a test — which is why the gate now asserts it.

### D-029 — Page-level and site-level JSON-LD are separate scopes
**Decision:** `SeoService` keeps two structured-data scopes. The shell writes `Organization` and `WebSite` once via `setSiteStructuredData()`; each route writes its own nodes via `setStructuredData()`, which replaces only the page scope.

With a single scope the route's call wiped the shell's, because the shell runs first. Every product page shipped a `Product` and a `BreadcrumbList` with no `Organization` for them to attach to, and §14.4 requires all of them. Nothing failed, nothing logged, and the page looked correct — the loss was visible only in the raw HTML.

**Guarded by:** `seo.service.spec.ts`, and by the raw-HTML gate, which requires `Organization` and `WebSite` on every sampled page.
**Status:** architect decision.

### D-030 — Metadata callers may offer several candidate titles
**Decision:** `PageSeo.title` accepts a string or an ordered list of candidates. The service uses the first that fits the 60-character budget whole, and only truncates if none do. Where even the bare name will not fit alongside the site name, the **site name** is dropped rather than the name truncated.

Two measured cases forced this:
- The category template `{name} in Nigeria — Prices & Bulk Supply` overflows for most category names. Blunt truncation produced `Laser Printers in Nigeria — Prices… | OfficeLand Nigeria`, discarding "Bulk Supply" — a phrase chosen for query intent — and ending the snippet on a dangling word. A shorter whole phrase is strictly better than a longer mangled one.
- Product titles use the agreed `[code] [brand] [name]` format and run 40–55 characters routinely. `CE320A HP 128A Black LaserJet Toner Cartridge` is 45; with ` | OfficeLand Nigeria` it is 66. Truncating would have cut the model name — the part that matches the search — on essentially the whole catalogue, to keep branding Google frequently appends by itself.

This refines §14.3's sacrifice order (drop the brand segment, then shorten the name) with a step it does not cover: prefer a different whole phrasing before sacrificing anything.
**Guarded by:** `seo.service.spec.ts`, and by the raw-HTML gate's title-budget assertion.
**Status:** architect decision, driven by the real title data.

### D-031 — Listings page by number, not by cursor; §8.3 applies to API consumers
**Decision:** every listing surface (PLP, brand hub, search) is offset-paginated by page number. Cursor pagination is retained nowhere in the catalogue read path.

**The conflict.** §8.3 requires cursor pagination on any list that can exceed 1,000 rows, because offset degrades linearly as a crawler walks deep. §14 requires every page of a listing to be reachable by a crawlable anchor. Under D-009 those cannot both be satisfied by one mechanism: a cursor is opaque, so page 3 is unreachable without first fetching page 2, and no anchor for it can be rendered at build time.

**Resolution — they serve different consumers.**
- **Listings** are read by humans and crawlers, page by page, and every page is a prerendered file. They are offset-paginated and *bounded*: a 5,000-product brand hub is ~208 pages at 24 a page, and `products_active_idx` carries that.
- **The render worker** walks the whole catalogue, and it does not paginate at all — `/api/v1/render/manifest` returns everything in one response, precisely because a partial answer is indistinguishable from a complete one at the point of use.

So §8.3's concern — a client walking a large collection — is still met, by removing the pagination rather than by making it opaque.

**Consequence.** `CategoryController@products` and `BrandController@products` now run through `SearchEngine` rather than their own Eloquent queries. A listing and a search result are the same question with a different filter, and §16 puts facets behind the engine; two implementations of subtree resolution, attribute filtering and facet counting would have disagreed the first time either changed. The shared envelope lives in `BuildsListingResponses`.

**Superseded:** `test_category_listings_use_cursor_pagination`, which asserted the opposite and cited §8.3. Replaced by tests for page numbering, non-overlapping pages, facet counts and facet narrowing.
**Status:** architect decision; the spec conflict is resolved in favour of crawlability, and the §8.3 requirement is met by a different route.

### D-032 — Pagination is a path segment, not a query parameter
**Decision:** page 2 of a listing is `/c/office-furniture/page/2/`. Query-string pagination is not used anywhere.

Angular prerenders **paths**. It cannot emit a file for a query string, so under D-009 `?page=2` would have no file behind it: the static host would answer it with `/c/office-furniture/index.html` — page 1 — and every listing page past the first would be duplicate content showing the wrong products, while the anchors pointing at them looked perfectly correct.

This shows up in three places, and all three have to agree or the pagination silently breaks:
- `Pagination` builds `page/N` paths.
- `CategoryPage` splits a trailing `page/N` off the wildcard before asking the API for a category (the trailing segment must be a positive integer, so a category legitimately slugged `page` is not mistaken for pagination).
- `RenderManifestController` emits a `page/N` route for every additional page, counted over the whole subtree, matching what the listing lists.

`RenderManifestController::PER_PAGE` must match the listing page size in `BuildsListingResponses::perPage()`. If they diverge the manifest renders a different number of pages than the listing links to, which is a 404 on the last page or an orphaned file past it.

**Facets stay as query parameters.** Facet pages are `noindex, follow` and are not prerendered; a crawler following one receives the unfiltered page, whose product links are a superset of the filtered page's. That is the correct outcome, and it is why the combinatorial facet space costs nothing at build time.

**Guarded by:** `RenderManifestTest`, which asserts the paged routes exist, stop at the right number, and count a parent's whole subtree.
**Status:** architect decision, forced by what static prerendering can and cannot address.

### D-033 — `vendor/` lives on a named volume, not the Windows bind mount
**Decision:** the API container mounts `api_vendor` at `/app/vendor`, outside the host bind mount.

**Measured.** On Docker Desktop for Windows a bind-mounted file read costs about **29 ms**. 500 `vendor/` files took **14.5 s** from the bind mount against **0.01 s** from container-local storage — a factor of ~1,450.

**The failure it caused.** Laravel reads hundreds of files to boot. A cold Octane worker could not finish booting inside the 30-second request limit, so it timed out, died, and its replacement cold-booted into the same wall. The visible symptoms were:

- every endpoint slow, including `/health/live`, which touches nothing;
- fatal errors at random framework locations (`AliasLoader`, `Connector`, `Application`) — wherever the timeout happened to land during boot;
- the Angular prerender failing with `TimeoutError: routes extraction was aborted`, because `getPrerenderParams` could not fetch the manifest in time;
- a full API test run taking 40 minutes instead of 9.

None of it looks like an I/O problem from the inside, which is why it was misread twice as an application fault before being measured.

**After the change:** the same endpoints went from 15–40 s to ~2.5 s, and the test suite from ~10 s per test to ~2 s.

`vendor/` is generated by composer and never hand-edited, so nothing is lost by keeping it off the host filesystem — the same reasoning already applied to `web_node_modules` on the render service. Application code stays on the bind mount because it is edited live, and still costs ~2 s per cold request on this platform.

**Consequence for the Phase 8 gate:** performance figures from this machine are not representative and must be re-taken on the production host (Q7). Recorded in RUNBOOK.md §6.
**Status:** architect decision, measured before and after.

### D-034 — The full build renders from the manifest; `ng build` is a different operation
**Decision:** two distinct paths publish HTML, and they are not interchangeable.

| | `ng build` | `render:full` |
|---|---|---|
| Compiles bundles | yes | no |
| Renders routes | yes, in-process | via the running worker, one at a time |
| Used by | CI, and any code change | a data-only republish |
| Measured | ~200 s for 145 routes | **7.6 s for 145 routes, 52 ms each** |

**Why both exist.** The incremental renderer regenerates the routes a DATA change affects. It cannot see a change to a template, a component, a design token or a global SEO rule — nothing in the database moved, so no event fires, and every page silently keeps the old markup. A full build is therefore part of the deploy routine, not an emergency measure.

`render:full` covers the other case: the bundle is unchanged but a lot of content moved (a bulk import, a re-categorisation, a restore). Recompiling to republish would be wasted work.

**Both read the same `RouteManifest` service.** The endpoint and the command publishing different route sets is precisely the failure that leaves pages with no file behind them, so there is one implementation and the command does not call its own HTTP API to get it.

**Extrapolation.** At 15,000 products the manifest is ~15,150 routes: **~13 minutes** for `render:full`, and 1.5–3 hours for a full `ng build` prerender. That is the floor on time-to-recover for any markup or global-SEO defect, and it is why it is in the runbook rather than in someone's head.
**Status:** architect decision, measured on the development stack; re-measure on the production host (Q7).

### D-035 — The basket cookie is an opaque token, not a signed payload
**Decision:** the `ol_basket` cookie carries 64 random characters. The server stores only its SHA-256, and everything else lives in Postgres.

§9a asks for a "signed HttpOnly cookie". The cookie is HttpOnly, SameSite=Lax and Secure outside local development, but it is **not signed** — and does not need to be, because signing provides integrity and integrity is already guaranteed by construction:

- The value is ~380 bits of server-generated randomness, so it cannot be guessed.
- It is compared against a stored hash, so a tampered value simply matches nothing and is treated as "no basket" — exactly the outcome a failed signature check would produce.
- It carries no claims, so there is nothing for a signature to protect.

Storing the **hash** rather than the token means a database leak does not hand anyone else's basket to an attacker. That matters because a basket is about to carry an email address and a phone number.

**Note on encryption.** Laravel's `EncryptCookies` belongs to the `web` middleware group, and these are `api` routes, so the cookie is transmitted in plaintext. That is consistent with the above and is asserted by `QuoteJourneyTest`, which reads the cookie value directly.

**SameSite=Lax, not Strict.** A buyer arriving from a Google result and adding an item in the same visit must keep one basket. Strict drops the cookie on that first cross-site navigation and silently starts them a second one.
**Status:** architect decision; the spec's intent (the client cannot forge or tamper with basket identity) is met by a different mechanism.

### D-036 — Quantities are coerced upward, never rejected
**Decision:** a quantity that violates `min_order_qty` or `qty_step` is rounded **up** to the nearest allowed value, server-side, and the coerced value is returned.

A buyer who types 7 against a product sold in multiples of 5 has not made an error worth an error message — they want about seven. Rounding to 10 and saying so is the behaviour that gets the quote sent; a validation error is the behaviour that loses it. Rounding **down** would silently give them less than they asked for, which they would not notice until delivery.

Steps count **from the minimum**, not from zero: a product with a minimum of 4 sold in fives allows 4, 9, 14 — not 5, 10, 15, which would exclude the minimum the product itself declares.

The storefront applies the same rules in its steppers, but that is a convenience. The server is authoritative (§9a), and quantity is exactly the field where a crafted request would otherwise put `1` against a product with a minimum of 50 — producing a quote a salesperson has to catch by eye.

**Guarded by:** `QuoteJourneyTest`, which asserts coercion up, coercion to the minimum, and step-from-minimum.
**Status:** architect decision.

### D-037 — A rejected submission is always reported, never silently dropped
**Decision:** every anti-abuse rejection returns an error the caller can see. Nothing is accepted-and-discarded.

The tempting alternative — accept the request, return a confirmation, and quietly bin it — is the worst failure this form has. A real buyer whose submission tripped a heuristic believes their request was sent and waits for a reply that is never coming, and **nobody ever learns it happened**. A visible rejection at least sends them to the phone number.

The checks are chosen to be invisible to legitimate users (§9e): a honeypot, a timing floor, and per-IP and per-email rate limits. All are optional server-side, so the form still works for a client that never ran JavaScript — traffic this site must not turn away.

**Deliberately absent: a captcha (Q16).** It is a real cost to every legitimate buyer on a slow Nigerian mobile connection and should only be added if the cheaper checks prove insufficient against real traffic. The seam is `AbuseGuard::challengePassed()`, which **fails closed** if a provider is configured but unimplemented.

The rate limiter is hit only on **success**, so a few typos do not consume a real buyer's allowance.
**Status:** architect decision.

### D-038 — Quote references are gapless, which is why they are not a sequence
**Decision:** `OL-Q-YYYY-NNNNNN`, issued from a per-year counter row locked with `SELECT … FOR UPDATE` inside the submitting transaction.

§9f asks for a reference that is "gapless per year via sequence". Those two requirements conflict: a PostgreSQL sequence is explicitly **not** gapless. `nextval()` is non-transactional by design — that is what makes it fast and lock-free — so a rolled-back submission burns its number permanently.

A locked counter row is gapless: the number is consumed only if the quote commits. The cost is that submissions serialise against each other for the duration of one insert, which at lead volume — tens per day — is unmeasurable. It would be the wrong trade for orders; it is the right one here.

`ReferenceGenerator` **throws** if called outside a transaction. That is not defensive decoration: without a surrounding transaction the lock lasts only for the statement, two callers read the same value, and the unique index turns that into a failed submission for a real customer.
**Status:** architect decision; the spec's mechanism is contradicted, its intent is met.

### D-039 — Basket responses must never enter a shared cache
**Decision:** `SetCachePolicy` marks `api/*/basket` and `api/*/basket/*` as `private, no-store`.

Found during Phase 9. `GET /api/v1/basket` is a 200 GET that looks exactly like a cacheable catalogue read, and the middleware's private-path list only covered `quote*`. Left as it was, a shared cache could have stored one visitor's basket — and the `Set-Cookie` header issuing their basket token — and served it to the next person who asked.

**Guarded by:** `QuoteJourneyTest::test_the_basket_is_never_publicly_cacheable`.
**Status:** architect finding, fixed and asserted.

### D-040 — The ZeptoMail payload shape is asserted field by field, because a wrong one succeeds
**Decision:** `ZeptoMailTransportTest` asserts every field name against the live API documentation rather than against the implementation.

Verified before implementing, as §10a requires:

| | |
|---|---|
| Endpoint | `POST https://api.zeptomail.com/v1.1/email` (EU: `api.zeptomail.eu`) |
| Authorization | `zoho-enczapikey <token>` — **not** `Bearer`; the prefix is part of the value |
| Recipients | `to[].email_address.{address,name}` — **nested**, not flat |
| Bodies | `htmlbody` / `textbody` — one word, no separator |
| Reference | `client_reference` |

**Why this needs tests rather than care.** ZeptoMail accepts a structurally wrong request with a **200** and delivers nothing. A flat `{address,name}` recipient, or `html_body` instead of `htmlbody`, produces a success response and silence. On the quote pipeline, silence is the failure the entire project exists to avoid — the customer believes they were answered, sales believes the customer went quiet, and nobody learns otherwise.

Two of these are actively misleading: `Bearer` returns a **401 that reads exactly like a bad token**, and a EU tenant hit on the `.com` endpoint returns the *same* 401. The natural response to both — reissue the token — does not help.
**Status:** architect decision; API shape verified against live documentation on implementation day.

### D-041 — Soft bounces do not suppress
**Decision:** only hard bounces and spam complaints add to the suppression list. Soft bounces are logged and otherwise ignored.

A soft bounce is a full mailbox or a temporary server failure, and it clears on its own. Suppressing on the first one would permanently stop writing to a customer whose inbox was briefly full — losing orders to fix a problem that had already fixed itself. Opens and clicks are ignored entirely: tracking is off by default, and we do not want a record of who read what.

**A suppressed customer still generates a sales notification.** The two messages have different recipients and different consequences: if a customer's address hard-bounced, sales must still be told the lead exists, because they can phone. Dropping both would turn a bad email address into a lost order. The suppressed receipt is recorded on the quote as `confirmation_suppressed`, so the salesperson knows to call rather than ask "did you not see my email?".

Releasing a suppression is manual and attributed, never an automatic expiry: an expiry quietly resumes sending to a dead address, re-earns the bounce, and repeated bounces to one address are exactly what damages a sending domain.
**Status:** architect decision.

### D-042 — Webhook signature verification is the entire security boundary, and fails closed
**Decision:** HMAC-SHA256 over the **raw request body**, from the `producer-signature` header, with a five-minute freshness window and constant-time comparison. No secret configured means every webhook is rejected.

The endpoint is unauthenticated by necessity — ZeptoMail cannot hold our credentials — so the signature is not a formality. An unverified webhook could suppress **any address an attacker names**: a denial of service against our own customers, delivered through our own bounce handling.

Four details are load-bearing and each fails silently if got wrong:

- **The raw body, not the decoded array.** JSON decode/re-encode does not round-trip byte-for-byte — key order, unicode escaping and number formatting all change — so the bytes that arrived are the bytes that must be hashed.
- **Split on the first `=` only.** A base64 signature is frequently `=`-padded, and splitting on every `=` truncates it.
- **A freshness window.** Without it a captured webhook can be replayed indefinitely to suppress an address on demand.
- **`hash_equals`.** A fast comparison leaks how much of the signature matched, which is enough to forge one byte at a time.

**The webhook secret is a different secret from the send token.** They are issued independently by ZeptoMail. Reusing one for the other verifies nothing, rejects every webhook, and the only symptom is bounces never reaching the suppression list while the sender reputation slowly degrades.
**Status:** architect decision; webhook contract verified against live documentation.

### D-043 — Open and click tracking are off by default
**Decision:** `track_opens` and `track_clicks` are omitted unless explicitly enabled per mailer.

An open pixel and rewritten links on a **transactional receipt** are a privacy cost the customer never agreed to — they asked for a quote, not to be measured. Rewritten links are also a common reason a receipt is flagged as phishing, which costs delivery of the exact message that matters most.

Configurable rather than removed, because a future marketing mailer may legitimately want them; the default belongs on the side of the customer.
**Status:** architect decision.
### D-044 — The SLA clock counts working hours, and public holidays are deliberately not modelled
**Decision:** lead ageing is measured in **working hours** — 08:00–17:00, Monday to Friday, Africa/Lagos — not elapsed hours. Nigerian public holidays are **not** modelled.

§11c asks to highlight anything unanswered beyond four working hours. Elapsed time cannot express that. A quote submitted at 16:30 on Friday and answered at 09:30 on Monday took **one working hour**, but sixty-five elapsed ones. Under elapsed time every weekend row turns red, and a queue where most rows are red is a queue nobody reads — which hides the row that is genuinely late: a Monday-morning lead untouched since 09:00.

The timezone is **configured, not inherited from the server**. A server that moves region must not silently change what "four working hours" means; the business, its customers and the promise printed on every page are all in West Africa Time.

Public holidays are excluded on purpose. Nigerian public holidays are partly declared at short notice, so a hardcoded list would be wrong within a year — and wrong in the damaging direction, flagging a lead as late when the office was shut. Weekends are handled; a holiday reads as one day of overrun, which a salesperson interprets correctly. Making this exact needs a maintained holiday table, not a constant in a class.

The clock **stops at the first reply**, and closed leads (won/lost/spam) never age. A queue that keeps ageing rows nobody is waiting on cannot be triaged at a glance — and one spam run would otherwise turn the whole screen red.
**Status:** architect decision.

### D-045 — `first_response_at` is set by an explicit action, never inferred
**Decision:** the SLA clock is stopped by a salesperson pressing **"I have replied"**. Nothing infers it.

D-005 put the reply outside this system: sales prices and responds in their own tools, so no code can observe that the customer was answered. Every alternative is worse than a button. Setting it as a side effect of opening the record makes the metric a lie — the promise would be met by clicking. Setting it on a status change means it is never set for the leads that stall, which are the only ones the metric is about. Leaving it unset makes every answered lead look permanently overdue, and within a week the whole indicator is ignored.

Replying also **takes ownership**: an unowned lead is assigned to whoever pressed the button. Reassignment afterwards is one action, but the default must never be "answered by nobody" — an unowned lead is exactly the state the queue exists to eliminate.
**Status:** architect decision.

### D-046 — Quote routing rules live in the database; the fallback address stays in config
**Decision:** per-category routing (§11d) is a `quote_routing_rules` table editable in admin. The **default** recipient stays in `MAIL_SALES_ADDRESS`.

Routing is a personnel decision — it changes when somebody joins, leaves or swaps desks. Config-file routing makes each of those a developer task and a deploy, and the predictable result is that it stops being maintained while leads keep arriving in a former colleague's inbox.

The default is the exception because it is infrastructure, not personnel: it is the address the whole system falls back to, it changes about never, and two places that could define it is how it ends up defined twice and differently.

Three properties are load-bearing:

- **Rules point at a category row, not a path string.** The match is a path prefix, but storing the path would stop matching the day somebody moves or renames a category — and it would fail *open*, routing to the general inbox with no error anywhere.
- **Longest prefix wins, not first match.** `/it-products/printers/` can be routed separately from `/it-products/` without depending on the order somebody happened to create the rules in.
- **Routing can narrow but never drop.** There is no "send nowhere". No rule, a paused rule, a trashed category, a product with no category, a quote with no lines — every one of them falls back to the default inbox. A configuration screen for a feature whose purpose is "make leads more likely to be answered" must not offer a way to make one vanish.

Database rules override config rules for the same path: once somebody has set a rule in the UI, the UI is the truth, or a change made there appears to do nothing.
**Status:** architect decision.

### D-047 — The routing test-send uses a fabricated quote, sent synchronously
**Decision:** "Send a test" builds an unsaved sample quote and sends it with `sendNow`.

**Fabricated, because the address is typed into a form.** Sending a real lead would let anyone with admin access forward a customer's email address, phone number and buying intent to an arbitrary inbox with one click. A sample proves the routing works just as well and discloses nothing. It is also never persisted, so the test does not create a lead in the queue that somebody then tries to answer, and its reference is `OL-Q-TEST` rather than the real `OL-Q-YYYY-NNNNNN` format so nobody chases it.

**Synchronous, because a queued test cannot fail visibly.** `QuoteReceivedMail` implements `ShouldQueue`, so an ordinary `send` would enqueue it and report success before the transport was touched — a wrong token or a dead address would surface only in a failed job nobody is watching, which is exactly the failure the button exists to catch.
**Status:** architect decision.







---

### D-048 — The content floor is enforced on the model, not in the form
**Decision:** `is_indexable` cannot be set to true on a product or category that is below the SEO_SPEC §7 minimums. The transition throws.

The Phase 12 gate is *no indexable page below the content minimums*. A word counter beside a checkbox does not achieve that. Three routes write `is_indexable` — the Filament editor, the CSV importer, and any future Icecat sync — and a form validator sees one of them. The two it does not see are the bulk ones, which is exactly how 15,000 thin pages would enter the sitemap in a single afternoon. Same reasoning as alt-text enforcement in Phase 4f.

Two deliberate limits:

- **It throws rather than silently correcting.** Quietly resetting the flag would leave an editor believing a page is indexed when it is not, and the discrepancy would surface weeks later as traffic that never arrived. An exception is unpleasant exactly once, while somebody can still act on it.
- **Only the transition INTO indexable is guarded.** A live page whose content is later trimmed is not un-indexed as a side effect of an unrelated edit — that is a bigger surprise than the thin page it prevents. The readiness report surfaces those instead.

The 150–300 word range for category intros is enforced only at the bottom. A 400-word intro is a reason to tell the editor it reads long, never a reason to withhold the page from Google; enforcing the ceiling would be enforcing house style as though it were an SEO requirement.
**Status:** architect decision.

### D-049 — Generated descriptions cannot be indexed until a person approves them
**Decision:** anything written by `DescriptionDrafter` is stamped `description_generated_at`, and `ContentMinimums` refuses to index it while that stamp is newer than `description_approved_at`.

D-010 puts 15,000 products in the index on day one and §14.7 wants 120+ words on each. At that scale the first draft is machine-written, and §14.3 forbids shipping the result — *never a boilerplate string with the name swapped in*. A template is a boilerplate string with the name swapped in, however carefully written. What makes the output defensible is the editing pass, so the pipeline makes that pass **mandatory rather than encouraged**.

**Timestamps rather than a status enum.** The rule is "a human approved it *after* the machine last touched it", and an enum cannot express the ordering — regenerating a description would leave it sitting on `approved` with nobody having read the new text. Comparing timestamps makes both re-generation and re-flagging invalidate a prior approval automatically, which is the safe direction.

**The drafter refuses rather than padding.** A product with three generic attributes cannot support 120 words of specific prose, and the alternative to refusing is filler — the exact failure mode, dressed as success. A refusal puts the product in the review queue marked as needing *data*, not copy, which is a different job for a different person.

The draft is built from the attributes that **vary within the product's family**, because SEO_SPEC §7.2 makes information density the whole test: an attribute every sibling shares says nothing about this product, and a draft built from shared attributes reads identically for every sibling.
**Status:** architect decision.

### D-050 — The similarity gate strips identifiers, scopes to a family, and flags rather than fails
**Decision:** implements the SEO_SPEC §7.3 correction to §17.4, plus one addition: product names, SKUs, MPNs and model codes are removed from both texts before comparison.

§17.4 asked that no two products share >70% description similarity and that the import fail if they do. Toner variants legitimately share ~90% — they are the same cartridge in four colours — so the original rule rejects the honest case and takes the whole import down with it.

The addition matters as much as the corrections. The defect being caught is *a boilerplate string with the name swapped in*. Left in the text, the name is the one part guaranteed to differ, so it drags the similarity score **down** — the gate would read lowest exactly where the defect is worst. Stripped, two boilerplate descriptions score ~1.0, which is what they are.

The threshold is 0.95, set high on purpose. Genuine variants differ by a colour or a page yield, which is a small difference in trigram terms; a lower threshold would flag most of a legitimate family and teach reviewers to approve without reading, which is worse than not flagging at all.

Family is *same brand + same primary category* — the closest thing the schema carries to a manufacturer's range within one product type, and the boundary inside which shared phrasing is legitimate.

Re-running the gate never re-stamps an existing flag, so a nightly batch cannot silently un-index every variant a reviewer already accepted.
**Status:** architect decision; supersedes §17.4's global 70% rule, per SEO_SPEC §7.3.

### D-051 — The review queue is ordered by internal search demand, as an admitted proxy for keyword value
**Decision:** §12f's "ordered by keyword value" is implemented against our own `search_query_log`, weighted 3× for queries that returned nothing, plus catalogue depth for categories.

Keyword value means search volume. Search Console has no data until the site is live and crawled, and no keyword-tool subscription is in scope — so the number §12f asks for **does not exist yet**. Ordering by an invented one would be worse than an admitted proxy, because people trust a priority order.

Internal search demand is genuinely good in one respect a keyword tool is not: it is *our* buyers rather than the market at large. Zero-result queries are weighted up, not down — that is demand we are visibly failing to meet, and a page that answers it converts a dead end into a landing page.

Categories are listed before products in the queue. 124 intros are a week's work and unlock indexability for the pages the whole taxonomy hangs off; 15,000 product descriptions are a quarter's. Doing the cheap structural work first is the difference between launching with a coherent site and launching with 15,000 orphaned leaves.

**Replace this once GSC has 28 days of data.** It is called from one place so that swapping the signal is a change in `ContentValue` and nowhere else.
**Status:** architect decision; revisit after launch.

### D-052 — The public API is rate limited per surface, with internal traffic exempt
**Decision:** separate limits for catalogue reads (300/min), search (60/min), basket writes (60/min) and the mail webhook (120/min), keyed by client address, with private addresses exempt.

**The finding this closes.** Before Phase 13a the public API had **no rate limit of any kind** — only the admin panel and quote submission were protected. Search was both the most expensive endpoint in the system and completely open: it runs a weighted FTS query, then a **trigram fallback whenever FTS finds nothing**, then facet aggregation. A query designed to miss goes down the slowest path every time, and nothing stopped one client sending it continuously. Basket writes were the second hole — one row inserted per request, unbounded.

One global number would have been wrong in both directions. The surfaces differ by more than an order of magnitude in cost, and the render worker's traffic pattern looks nothing like a user's.

**The exemption is the load-bearing part.** `render:full` walks 15,000+ routes; throttling it would turn a template fix into a half-built site. Private addresses are therefore exempt, which is safe **only because PHP runs inside Caddy under FrankenPHP** — there is no proxy hop, `$request->ip()` is the real socket address, and an internet client cannot present a private one.

That property is an assumption about the deployment, not about the code, so it is pinned by a test (`test_a_public_address_is_never_treated_as_internal`) and carries a warning in both the review and the runbook: **putting a load balancer in front of Caddy without adding it to `trusted_proxies` would make every request on the internet exempt.**
**Status:** architect decision; finding F1 in `docs/SECURITY_REVIEW.md`.

### D-053 — The storefront CSP is generated from the build, and gated in CI
**Decision:** `apps/web/tools/csp-hashes.mjs` derives SHA-256 hashes of Angular's inline scripts from the prerendered output and generates `infra/caddy/csp.caddy`. CI fails when the committed policy stops matching the build.

**Why not Angular's own auto-CSP.** Angular 22 ships `security.autoCsp`, which does exactly this — and refuses to run here: *"Cannot set both SSR and auto-CSP at the same time."* D-009 prerenders through the SSR builder, so it is unavailable to this project. A nonce is equally unavailable: nonces must be unique per response and injected by whatever serves the page, and the storefront is static files with no application in the path.

**Measured rather than assumed.** Three distinct inline scripts across 147 prerendered pages — Angular's hydration bootstrap embeds the event-replay contract for the events a page actually binds, so pages genuinely differ and the policy is the union of all of them. The tool fails above 24 distinct hashes, because past that something page-specific is being inlined and hashes are the wrong mechanism.

**Why the CI gate matters more than the policy.** A stale hash does not error — it blanks the site. The browser silently refuses to run the bootstrap, the page renders as inert static HTML, and the only evidence is a console message no user will ever report. A generated policy without a gate is a trap set for a future Angular upgrade.

**`style-src` keeps `'unsafe-inline'` and `script-src` does not.** Angular sets style attributes during hydration, which hashes cannot cover without `'unsafe-hashes'`. The asymmetry is the point: inline style is a defacement risk, inline script is code execution, and the whole value of a CSP is in the second one.

**The admin panel is deliberately excluded from the script policy.** Livewire and Alpine construct functions at runtime, so it would need `'unsafe-eval'` — most of the appearance of protection and little of the substance. It is defended instead by authentication, mandatory 2FA, an optional IP allowlist, a non-default path, `throttle:admin`, and `frame-ancestors 'none'`.
**Status:** architect decision; finding F3 in `docs/SECURITY_REVIEW.md`.

### D-054 — Authentication events are logged, and failures are `warning`
**Decision:** `Failed`, `Lockout`, `Login`, `Logout` and `PasswordReset` are written to the structured log. Failures, lockouts and resets at `warning`; successes at `info`.

**Nothing recorded a failed login before this.** Filament caps attempts at five per minute, so brute force could not succeed quickly — but *cannot succeed quickly* is not *somebody will notice*. A fortnight of patient credential stuffing against `/admin` left no trace anywhere, and the first evidence of a compromise would have been its consequences. That is OWASP A09 exactly: the controls existed, the record did not.

Three details carry the value:

- **`user_exists` on a failure.** A run of failures against a real account is an attack on a specific person; the same run against addresses that do not exist is a scanner working a leaked list. Different responses, and the log cannot distinguish them without this.
- **`warning`, not `info`.** A failed login at `info` is a line nobody has ever alerted on. At `warning` it clears a level filter and reaches whatever is watching.
- **Never the password.** Asserted directly, because the natural implementation logs `$event->credentials` wholesale.

Registered explicitly rather than by auto-discovery: these are the events an investigation starts from, and a listener that silently stops being discovered is a log that silently stops being written.
**Status:** architect decision; finding F2 in `docs/SECURITY_REVIEW.md`.

### D-055 — Backups are physical-primary with a logical secondary, and the drill is the deliverable
**Decision:** continuous WAL archiving plus nightly `pg_basebackup` as the primary; a weekly `pg_dump -Fc` as a secondary; `rsync --ignore-existing` for the media tree. A restore drill that verifies against a throwaway cluster is run on a schedule.

**Physical primary, because it is the only one that does point-in-time recovery.** A logical dump restores to the moment it was taken; base backup plus archived WAL restores to any moment in between. On a 15,000-product catalogue with 75,000 media rows, a logical restore is also a replay of every INSERT and a rebuild of every index.

**Logical secondary, because a physical backup faithfully copies problems too.** A corrupt page or a bad `UPDATE` from three weeks ago is present in every base backup taken since. A dump is a stream of SQL: slower, no PITR, but it survives that case and it restores into a *different major version*, which is the only backup that helps when a version upgrade goes wrong. Weekly rather than nightly, because its value is depth of history — recency is what the WAL archive is for.

**The media tree is copied, not regenerated.** Derivatives could in principle be rebuilt from originals, but Phase 4 measured 3.6s per image for 15 variants: 75,000 images is roughly **75 hours** of single-core libvips. That is not a recovery, it is an outage with a progress bar. Storage is cheaper than the downtime.

`--ignore-existing` is safe here specifically because media is content-addressed (Phase 4b): a path is derived from its content, so **a file at a given path never changes** and the nightly run is a directory comparison rather than a 50 GB read. Verified — a second consecutive run transfers zero files.

**The drill is the deliverable, and it is verified against its own failure modes.** Every way a backup silently fails to restore is invisible from the backup side: an archive that stopped accepting segments weeks ago, a tar written by a server version the restore host lacks, a base backup taken while `archive_command` was failing. So the drill restores into a **separate** cluster — safe to run in production, and therefore actually runnable on a schedule — and compares **row counts** rather than checking an exit code, because a restore that starts cleanly holding the wrong data is the worst outcome available.

Proven rather than asserted: a row inserted *after* the base backup was recovered through the archive (124 categories in the backup, 125 restored), and a truncated artefact fails with exit 1.
**Status:** architect decision. Timings in `RUNBOOK.md` §12.4 are development-scale and must be re-measured on the production host (**Q7**). The offsite destination, retention and RPO/RTO remain open (**Q21**).

### D-056 — The quote pipeline gets a synthetic canary; it rolls back and sends no mail
**Decision:** `monitor:quote` submits a real quote through `QuoteSubmitter` every 15 minutes, asserts it landed, and rolls the whole thing back.

**Because this pipeline fails silently.** A broken storefront is reported within minutes by anyone who looks at it. A quote pipeline that stopped writing rows looks exactly like a quiet week — and a business's own explanation for a quiet week is never "our software is broken". By the time somebody asks, the leads are gone and unrecoverable. Every other check in the system is a proxy for something; this one exercises the actual thing.

**It calls the service, not the HTTP endpoint.** Posting to `/api/v1/quotes` would drag in `AbuseGuard`, and a monitor running four times an hour from one address is precisely what that guard exists to stop — the monitor would either trip the limiter or force us to weaken it. Everything that can genuinely break lives below that layer.

Three properties are load-bearing, and each is a test rather than a comment:

- **It leaves nothing behind.** A synthetic lead arriving every fifteen minutes would train the sales team to ignore the queue, destroying exactly what Phase 11 was built to protect.
- **It burns no reference numbers.** D-038 makes references gapless per year; an un-rolled-back monitor would consume roughly 35,000 annually and make the sequence meaningless.
- **It sends no mail.** `submit()` writes and the *controller* mails, so the monitor stops short of the mailer. That is a property of where the seam sits, not of luck — moving the mailer inside `submit()` would silently start notifying sales four times an hour, so the absence is asserted.

Failures log at **critical**, which is the level that should page: the business's inbound enquiries are not being accepted.
**Status:** architect decision.

### D-057 — Backups run from host cron; the application only watches them
**Decision:** `pg-base-backup.sh`, `pg-logical-dump.sh` and `pg-restore-drill.sh` are scheduled in `infra/cron/officeland.cron` on the database host, **not** in Laravel's scheduler. The application runs `monitor:backups` instead.

The first attempt put them in `routes/console.php`. That was wrong and would have failed every night: the API container has neither the Postgres client tools nor the scripts mounted. Worse, it would have failed in the quietest way available — a backup that never runs looks exactly like one that runs fine.

**What the application can usefully do is notice they stopped.** That is a different job from running them, and it catches the failure the scripts cannot catch about themselves: a cron entry never installed on a new server, a renamed container, a full disk. All of those leave the existing backups sitting there looking healthy while the recovery point silently ages.

`monitor:backups` checks `pg_stat_archiver.failed_count` — a failing `archive_command` is retried forever while `pg_wal` grows, and the first symptom is the disk filling and the database stopping, by which point the archive has a hole in it — and the age of the newest base backup.

**It is deliberately not part of `/health/ready`.** A stale backup must page somebody; it must not deregister the instance. Failing readiness over an old backup is a self-inflicted outage in response to a problem that has not caused one. For the same reason the age check is *skipped* rather than failed where the backup volume is not visible: a daily false alarm is how a real one gets ignored.
**Status:** architect decision.

### D-058 — Redirects are served by Caddy from a generated snippet, not by PHP
**Decision:** the `redirects` table is the editable source of truth; `redirects:publish` generates `infra/caddy/redirects.caddy`; Caddy serves it as a `map` with no application in the path.

**The finding this closes is that the redirect layer was completely inert.** The table, `docs/REDIRECT_MAP.csv` and the Filament admin screen all existed and all looked correct, and **nothing served them**. Every legacy URL returned 200 with the homepage. D-007's entire justification for mapping 5,063 legacy URLs is the organic equity they carry, and none of it would have transferred.

**No PHP in the path**, because D-009 says so and a redirect is the last place to make an exception: legacy URLs are hit by crawlers *in bulk* in the weeks after cutover — precisely when the site is under most scrutiny — and that traffic produces no page and no revenue.

**A `map`, not thousands of `redir` lines.** At D-007's scale individual matchers would be 10,000+ Caddyfile lines, each evaluated in turn; a map is one lookup regardless of size.

Two Caddy behaviours had to be found by testing rather than by reading the docs, and both are the kind that fail silently:

- **A bare `respond 410` is ordered after `handle`**, so it lost to the static file handler and every 410 became a 404. Wrapped in `handle` it joins the same mutually-exclusive group and wins by appearing first. The distinction matters: a 410 is dropped from the index faster and stops being re-crawled; a 404 is retried for months.
- **The map needs an explicit `default ""`**, or an unmatched path leaves the placeholder set and the matcher fires for **every request on the site**.

The generated file is read by Caddy only at start-up, so editing a redirect in Filament changes nothing live until it is published and the edge restarts. That trap is documented in RUNBOOK §14.1 alongside the identical one for assets.
**Status:** architect decision.

### D-059 — The static handler must not fall back to the homepage
**Decision:** `try_files` ends at `{path}.html`, with no `/index.html` fallback. A miss 404s honestly and `handle_errors` serves the prerendered 404 page.

The fallback was an SPA pattern applied to a prerendered site, where it can only ever be wrong: under D-009 every real route has its own file, so falling back to the homepage means serving the wrong page. Worse, Caddy *found* a file and answered **200** — so `handle_errors` never fired, every unknown URL was a soft 404 (forbidden outright by SEO_SPEC §8), and the `/404/` page built in Phase 8 was unreachable.

It looked exactly like the site working, which is why it survived from Phase 8 to Phase 14. RUNBOOK §5 even carried an entry about the 404 page pointing at the wrong file — describing a *different* cause for the same symptom, and therefore reading as though the problem was already understood.
**Status:** architect decision; corrects a Phase 8 defect.

## Open

### Q-A — Icecat subscription tier
Open Icecat (free) covers **600+ sponsoring brands**, verified to include HP, ASUS, Lenovo, Canon, Dell, Epson and Brother. **Baseus is not among them.** Baseus exists in Icecat's wider catalogue at 50% data health — community-sourced rather than brand-authorised — which places it behind Full Icecat (paid) and means its content will be patchy even then.
**Decision needed:** Open Icecat only (and Baseus via CSV/manual), or a Full Icecat subscription? This bears directly on whether 15,000 products is reachable from Icecat alone.

### Q-B — Who is rewriting 15,000 descriptions, and at what throughput? — **NOW BLOCKING**
Determines whether Phase 12 is a scheduling constraint or a launch blocker. If AI-assisted, the review queue needs the similarity gate described in SEO_SPEC.md §7.

**Updated 2026-08-21.** The similarity gate, the attribute-driven drafter, the approval step and the review queue are all built and enforced (D-048 through D-051). Every mechanical prerequisite for D-010's promise — all 15,000 products indexable at launch — is in place. **The only thing missing is the writing**, and with the content floor now enforced rather than advisory, an unwritten product is not a thin page in the sitemap: it is a page that cannot enter the sitemap at all.

That makes Q-B the binding constraint on the launch date rather than a quality risk. Two numbers are needed to plan around it: **who writes**, and **how many products a week**. `php artisan content:draft` (dry run) reports how many products have enough structured data to support a draft at all, which sets the floor on how much of the work is editing rather than writing from nothing.

### ~~Q-C — Framework versions~~ — CLOSED 2026-08-20
Resolved by D-020 (Angular 22, Filament v5) and D-019 (Laravel 12, forced by security advisories).

### Q-E — Client sign-off required on D-019
Laravel 12 replaces Laravel 11, which §6.3 marked as fixed by the client. The substitution is forced — Laravel 11 cannot be installed without disabling Composer's security-advisory policy — but §6.3's "do not substitute" instruction means this needs explicit acknowledgement rather than silent adoption.

### Q-D — Remaining §22 items unaffected by the above
Q13 (WoodTek), Q14 (Dubai/hreflang), Q15 (first-party reviews), Q16 (captcha provider), Q17 (brand assets, social URLs), Q18 (ZeptoMail region, and what `hostinger-reach` currently sends), Q20 (delivery model), Q22 (compliance scope), Q23 (merchandising taxonomy approval — see TAXONOMY.md §7).

### Q21 — RPO/RTO and the offsite backup destination — **sharpened 2026-08-22**
The backup and restore machinery is built, run and verified (D-055): WAL archiving, base backups, a weekly logical dump, an incremental media copy, and a drill that catches corruption and proves WAL replay. **Every script takes a destination and none of them has one.**

Three specific answers are needed, and they are cheap to give:

1. **Where does the offsite copy go?** It must be a different *host* — a copy on the same machine survives a mistaken `rm` and nothing else, not a failed disk and not ransomware. A second VPS, an S3-compatible bucket, or a NAS at the office all work; the scripts take an rsync target.
2. **How far back must it reach?** Retention drives cost, and it is the only defence against a problem discovered late — a bad `UPDATE` noticed three weeks on is unrecoverable from a seven-day window.
3. **What RPO and RTO is the business actually promising itself?** Continuous WAL archiving makes an RPO of minutes achievable; whether that is worth its cost is a business call, not an engineering one. The RTO cannot be quoted at all until the drill is re-run on the production host (**Q7**) — the development figure of 12s is on a 12 MB database and would be dishonest to extrapolate.
