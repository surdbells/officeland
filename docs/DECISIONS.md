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


---

## Open

### Q-A — Icecat subscription tier
Open Icecat (free) covers **600+ sponsoring brands**, verified to include HP, ASUS, Lenovo, Canon, Dell, Epson and Brother. **Baseus is not among them.** Baseus exists in Icecat's wider catalogue at 50% data health — community-sourced rather than brand-authorised — which places it behind Full Icecat (paid) and means its content will be patchy even then.
**Decision needed:** Open Icecat only (and Baseus via CSV/manual), or a Full Icecat subscription? This bears directly on whether 15,000 products is reachable from Icecat alone.

### Q-B — Who is rewriting 15,000 descriptions, and at what throughput?
Determines whether Phase 12 is a scheduling constraint or a launch blocker. If AI-assisted, the review queue needs the similarity gate described in SEO_SPEC.md §7.

### ~~Q-C — Framework versions~~ — CLOSED 2026-08-20
Resolved by D-020 (Angular 22, Filament v5) and D-019 (Laravel 12, forced by security advisories).

### Q-E — Client sign-off required on D-019
Laravel 12 replaces Laravel 11, which §6.3 marked as fixed by the client. The substitution is forced — Laravel 11 cannot be installed without disabling Composer's security-advisory policy — but §6.3's "do not substitute" instruction means this needs explicit acknowledgement rather than silent adoption.

### Q-D — Remaining §22 items unaffected by the above
Q13 (WoodTek), Q14 (Dubai/hreflang), Q15 (first-party reviews), Q16 (captcha provider), Q17 (brand assets, social URLs), Q18 (ZeptoMail region, and what `hostinger-reach` currently sends), Q20 (delivery model), Q21 (RPO/RTO, offsite backup destination), Q22 (compliance scope), Q23 (merchandising taxonomy approval — see TAXONOMY.md §7).
