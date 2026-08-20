# SEO Specification

**Status:** living document. Phase 0 baseline.
Organic search performance is the primary non-functional requirement (§1). Every rule here is testable, and most are enforced by the regression suite described in §10.

---

## 1. Baseline — what we are replacing

Audited live at `officelandng.com`, 2026-08-19. These are acceptance criteria in reverse: the new site must demonstrably exhibit none of them.

| Defect | Measured |
|---|---|
| Homepage `<title>` | **389 characters** |
| Homepage HTML weight | **626 KB** |
| JSON-LD on homepage | **0 blocks** |
| Meta description on homepage / PDPs | **Absent** |
| `og:image` | **Absent** |
| `<h1>` on homepage | **3** |
| Distinct products on homepage vs link occurrences | 114 vs **308** (2.7× repeat) |
| Product URLs live | **5,063** |
| Slugs with `-N` duplicate suffix | **1,410 (27.8%)** across **1,257 clusters** |
| Categories | 161, of which **35 (22%) are brand branches** |
| Social profile links | 5 × `http://officelandng` (invalid) |
| Obfuscated emails | 2 × `[email protected]` |
| Crawlable wishlist URLs on homepage alone | **120** |
| Supplier-copy slugs (`wholesale`/`factory`/`china`/`xinda`/`hot-sale`) | ~200 |
| `new-office-chairs-collection*` placeholder slugs | **95** |
| Platform | WordPress 7.0.4 · WooCommerce 10.9.4 · Elementor 4.2.1 (external CSS, Google Fonts) |

Structured data correction to §4K: a `BreadcrumbList` **is** present on PDPs. There is no `Product`, `Organization` or `WebSite` schema anywhere.

Note the legacy site carries **no prices and no add-to-cart** (0 of 25 sampled PDPs) — it already runs a quote-first model via the ELEX Request a Quote plugin. D-002 formalises this rather than introducing it.

## 2. URL architecture (D-008)

Lowercase, hyphenated, **trailing slash**, no extensions, no IDs, no parameters in canonical URLs.

```
/                                   Home
/c/                                 All departments hub
/c/{department}/                    Depth 1
/c/{department}/{subcategory}/      Depth 2
/c/{department}/{sub}/{leaf}/       Depth 3 — maximum
/p/{product-slug}/                  Product — FLAT, no category in path
/brands/{brand-slug}/               Brand hub
/brands/{brand-slug}/{category}/    Curated brand × category hub (whitelist only)
/guides/{article-slug}/             Editorial
/locations/{location-slug}/         Lagos HQ · Furniture Showroom · Dubai
/about /contact /services/{slug}
/search?q=                          noindex
/quote/ /quote/submitted/           noindex, nofollow
```

**Flat product URLs are deliberate.** A product in three categories has exactly one URL; breadcrumbs convey hierarchy, the URL does not.

**Slug rules.** Derived from the rewritten product name (D-015), max 75 characters, stop-words trimmed, model code appended where it disambiguates. Uniqueness enforced at database level. **Any slug change auto-inserts a 301 into `redirects`** — not optional, and the mechanism survives D-001's removal of migration.

**Canonicalisation.** One hostname, one protocol: `https://officelandng.com`. All of `http://`, `www.`, missing-trailing-slash and uppercase variants resolve to canonical in **exactly one hop**. Redirect chains are a test failure, not a warning.

## 3. Metadata templates

Character budgets are enforced in code; Filament shows a live SERP preview with counts. Truncation raises a validation warning — never a silent trim.

| Template | Pattern | Budget |
|---|---|---|
| Product | `{Product Name} \| {Brand} \| OfficeLand Nigeria` | ≤ 60 — drop brand segment first, then shorten name |
| Category | `{Category} in Nigeria — Prices & Bulk Supply \| OfficeLand` | ≤ 60 |
| Brand | `{Brand} Products in Nigeria — Authorised Supplier \| OfficeLand` | ≤ 60 |
| Brand × category | `{Brand} {Category} in Nigeria \| OfficeLand` | ≤ 60 |
| Article | `{Headline} \| OfficeLand Guides` | ≤ 60 |
| Home | `Office Supplies, IT & Furniture in Lagos \| OfficeLand Nigeria` | ≤ 60 |

**Adjustment under D-002:** the category template retains "Prices" because it matches query intent even though prices are not displayed. Revisit at Phase 12 if it produces a measurable expectation mismatch in Search Console click-through.

`products.name` and `meta_title` are **distinct fields**. D-015's title format governs `name` (and therefore the H1); the template above governs `meta_title`.

Required on every page: self-referencing absolute canonical · `og:title` / `og:description` / `og:image` / `og:type` / `og:url` · `twitter:card=summary_large_image` · `og:locale=en_NG` · a real, verified `og:image` per template. **Never emit `meta-keywords`** (§23.6 item 7).

## 4. Structured data

Server-rendered JSON-LD in `<head>`, generated from real data, validated against Schema.org and the Rich Results Test as a CI gate.

- **Organization** — name, legal name, url, logo, `sameAs` (corrected social URLs, pending Q17), `contactPoint` for sales and support with `areaServed: NG`.
- **WebSite** + `SearchAction`.
- **BreadcrumbList** — every product, category, brand and article page, driven by `primary_category_id`.
- **Product** — name, images (≥1200px), description, sku, mpn, brand, and `additionalProperty` for every specification attribute.
  **Under D-002, `offers` is omitted entirely.** No `price: 0`, no fabricated availability offer. This forfeits price rich results — the accepted, documented cost of an RFQ model per §14.4.
  The spec table is doing the work `offers` would otherwise do: `additionalProperty` is the single largest structured-data asset on this site.
- **ItemList** on category pages, in display order.
- **LocalBusiness** on each `/locations/{slug}` — three distinct entities with address, geo, `openingHoursSpecification`, telephone, `hasMap`.
- **FAQPage** only where visible FAQs genuinely exist on the page.
- **Article** on guides, with a real named `author` and `datePublished` / `dateModified`.

**Reviews (§14.6).** The Google Business Profile rating (4.4 / 138) is a *business* rating. It may be displayed on `/locations/**`. It must **never** be emitted as `AggregateRating` on `Product` — that is a policy violation and a manual-action risk. The review model is built in the schema; product review markup stays off until first-party reviews exist (Q15).

## 5. Faceted navigation (D-012)

The largest crawl-budget risk on the site.

1. Filter state lives in query parameters, applied client-side against the API over the prerendered page.
2. Any URL carrying a filter parameter: `<meta name="robots" content="noindex,follow">` and a canonical pointing at the clean category URL.
3. **Ad-hoc filter controls render as buttons** pushing history state — never `<a href>` targets a crawler will queue.
4. **Curated, whitelisted facet combinations render as real `<a href>` anchors** and are indexable landing pages with unique copy, their own H1 and their own metadata.

Rules 3 and 4 together are **D-012**, resolving a contradiction in §14.5: if every filter control is a button, the whitelisted pages of rule 4 have no internal links and cannot be discovered.

**Initial whitelist** (fewer than 30, each earning its place):
the five HP brand × category hubs inheriting legacy intent (D-017), plus the "Best for" merchandising facets once approved (TAXONOMY.md §7). Governed by `attributes.is_indexable_facet` plus an admin-managed whitelist — indexability is data, not a hardcoded list.

Sort order, view mode and pagination parameters are always `noindex,follow`. `robots.txt` disallows known parameter patterns as a second line of defence, with the meta tags remaining primary.

## 6. Pagination (D-013)

Pages 2..N carry a **self-referencing canonical** and **`index,follow`**.

§14.5 as written required self-canonicals (justified by discovery) *and* `noindex` from page 2 — internally inconsistent, and long-lived `noindex` pages lose crawl scheduling over time, producing exactly the deep-discovery loss the rule existed to prevent.

This matters more at current scale than it would have at the legacy site's: **~175 products per leaf category, roughly 8 pages at 24 per page.** A large share of the catalogue lives on page 2+.

Every product must be reachable within **3 clicks** of the homepage. Delivered by **D-011**: category pages render their full child-category list as crawlable anchors, giving home → department → leaf → product. A crawl-depth report is a Phase 8 gate.

## 7. Content strategy

The differentiator, and under D-010 the largest risk.

**Product minimums (§14.7):** ≥120 words of original description · a spec table built from structured attributes, not prose · an "in the box" list · warranty and lead-time facts.

**D-010 makes all 15,000 products indexable at launch.** The risk this creates is recorded in `DECISIONS.md`. The mitigations that make it defensible:

1. **Never publish syndicated prose verbatim.** Icecat descriptions are drafts. Facts (MPN, specs, dimensions, images) are used as-is; prose is rewritten (D-015).
2. **Attribute-driven generation must be information-dense.** The line between defensible generated copy and spun boilerplate is whether the output conveys each product's actual differentiating specifications. §14.3 states this: *never a boilerplate string with the name swapped in*.
3. **Similarity gate in the review queue.** Descriptions are compared pairwise within a product family using trigram similarity. Output differing only by product name is flagged and blocked from publish.
   **Correction to §17.4:** the original rule — *no two products may share >70% description similarity, fail the import* — is unworkable. Toner variants (CE320A / CE321A / CE322A) legitimately share ~90% of their description. The gate therefore (a) measures only the non-boilerplate prose segment, excluding the spec table, (b) scopes the threshold **within** a product family rather than globally, and (c) flags for review rather than failing the import.
4. **Page value does not rest on prose alone.** Grouped spec tables, images, breadcrumbs, related-product rails and category cross-links carry substantial weight.

**Category intros:** 150–300 words of genuine buying context. Required before a category may be marked indexable. 124 categories — tractable.

**Buying guides** at `/guides/`, each with a real named author, linking down into categories and products.

**Internal linking:** breadcrumbs · related products (same category, same brand, complementary consumables) · category cross-links · guide → product links. An **orphan-page report is a gate** — anything with zero internal inbound links fails.

## 8. Technical checklist

- `robots.txt` with sitemap reference. **Staging serves a blocking `robots.txt` and `X-Robots-Tag: noindex` at the edge** — a staging leak is self-inflicted and unrecoverable in the short term.
- **Sitemap index** with segmented children: `sitemap-products-N.xml` (≤45k each — 15,000 products fits one, still segmented for future growth), `-categories`, `-brands`, `-guides`, `-pages`, `-images`. Generated from the database, regenerated on publish, with accurate `lastmod` from real modification timestamps. **Never** includes `noindex`, redirected or 404 URLs. Submitted to Google Search Console and Bing Webmaster Tools.
- Correct status codes. `404` for missing, `410` for permanently removed legacy junk, `301` for moves. **Never soft-404.**
- Custom 404 page with a search box and top categories. Every 404 logged with its referrer for redirect discovery.
- HSTS · HTTP/2 or HTTP/3 · Brotli.
- `hreflang` **only** if a UAE-targeted variant is in scope (Q14). A broken hreflang cluster is worse than none.
- Semantic HTML: exactly one `<h1>`, logical heading order, `<nav>`, `<main>`, `<article>`, `<table>` for specs.
- WCAG 2.2 AA: keyboard navigability, visible focus, 4.5:1 contrast, alt text on all meaningful images, accessible forms with linked labels and error messaging. **Alt text is a required field** at the model layer, with a Filament report of what is missing.

## 9. Core Web Vitals budgets (D-014)

Measured on **mobile, throttled 4G, mid-range Android** — the real Nigerian browsing context.

| Metric | Budget |
|---|---|
| LCP | < 2.0 s target · fail > 2.5 s |
| INP | < 200 ms |
| CLS | < 0.05 |
| TTFB | < 400 ms |
| Initial JS gz (home) | < 130 KB |
| Initial JS gz (PDP) | < 160 KB |
| Total page weight (PDP) | < 800 KB |
| Lighthouse SEO / Best Practices / A11y | ≥ 95 each |

**Enforced on the median of 5 runs** with a documented tolerance band (D-014). Single-run gates on throttled Lighthouse red-build on variance, which trains teams to ignore the gate.

D-009 helps materially here: TTFB on catalogue pages is disk-read latency, and no application code executes on the highest-traffic paths.

## 10. Verification gates

Automated, in CI, blocking.

1. **Raw-HTML gate (§14.1).** For a sample of ≥20 URLs spanning every template, fetch the served HTML **with JavaScript disabled** and assert the presence of: `<title>`, meta description, canonical, exactly one `<h1>`, full product/category body copy, breadcrumbs, internal links and valid JSON-LD. Under D-009 these are static files, so this gate reduces to fetching and asserting — but it must still run, because a regression in the render pipeline is otherwise silent.
2. **Redirect gate.** Every `from_path` in `REDIRECT_MAP.csv` returns the exact expected status and destination. Zero chains, zero loops. *(Phase 0 status: 182 rows, 161/161 legacy categories covered, 0 duplicates, 0 chains.)*
3. **Indexability gate.** No page intended as indexable carries `noindex`. The sitemap contains no non-200 URL.
4. **Orphan gate.** No indexable page has zero internal inbound links.
5. **Crawl-depth gate.** Every product reachable within 3 clicks of the homepage.
6. **Structured-data gate.** Every template validates against Schema.org and the Rich Results Test.
7. **Accessibility gate.** axe-core, zero critical violations.
8. **Duplicate-content gate.** Per §7 above — family-scoped, prose-only, flag-not-fail.

## 11. Measurement

GA4 · Google Search Console (all four property variants verified) · Bing Webmaster Tools.

Server-side event tracking for quote submissions — client-side alone loses 20–40% to blockers. **Note the tension with §18:** server-side collection does not exempt analytics from consent under the NDPA. Essential telemetry and consented analytics are separated explicitly, with a documented lawful basis for each.

Piped into Filament dashboards: zero-result search terms · 404 hits with referrers · quote conversion by landing page. Zero-result queries are a merchandising and content asset, not a log.
