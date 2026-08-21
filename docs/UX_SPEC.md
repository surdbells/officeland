# UX Specification

**Phase 7 deliverable 7g.** Annotated wireframes, component inventory, and facet groups per category family.

**Status:** components marked ✅ are built. Everything marked ⬜ is specified here and built later in Phase 8 or in Phase 9, so the structure is agreed before the components exist rather than after.

**Provisional:** every colour, font and social URL depends on **Q17** (brand assets, exact hex, fonts). The tokens in `apps/web/src/styles.css` carry placeholder values and are marked as such. Nothing else in this document is blocked by Q17.

---

## 1. Principles this document is accountable to

The reference site (currys.co.uk) was studied for structure, not for styling. Five rules come out of §23 and govern every screen below.

1. **If it matters for search, it is in the HTML.** Not behind a click, not behind a script, not behind an accordion's open state. Collapsed is a CSS and ARIA state, never absent content.
2. **Quote-first, not cart-first.** There are no prices (D-002). The primary action everywhere is *Add to quote*, and the secondary is *Ask on WhatsApp*.
3. **The buyer is a business.** Lead time, minimum order quantity, bulk supply and installation are the facts that convert; consumer patterns like scarcity timers and discount shouts are not.
4. **Never hover-only.** Every disclosure works by click, by Enter/Space, and by touch. Escape closes and returns focus.
5. **No layout shift.** Panels overlay; they never push.

---

## 2. Global chrome

### 2.1 Header ✅ `ol-site-header`

```
┌──────────────────────────────────────────────────────────────────────────┐
│ [skip to main content]                       ← first focusable, always   │
├──────────────────────────────────────────────────────────────────────────┤
│ Quotes answered within 1 business day · Nationwide delivery       [×]    │  announcement, dismissible
├──────────────────────────────────────────────────────────────────────────┤
│ Request a quote │ Track my quote │ Delivery │ Showrooms │ Bulk │ Guides  │  utility bar (md+)
├──────────────────────────────────────────────────────────────────────────┤
│ OfficeLand   [ search products, brands, model codes    ]  [WhatsApp] [Quote ▸ 3] │
├──────────────────────────────────────────────────────────────────────────┤
│ Office Furniture │ IT Products │ Printers │ Supplies │ … │ Brands │ Services │
└──────────────────────────────────────────────────────────────────────────┘
```

| Element | Note |
|---|---|
| Skip link | §23.5. First focusable element on every page; asserted by `app.spec.ts`. |
| Announcement | One rotating **service promise**, never a discount. Dismissible. |
| Utility bar | The quote-first equivalent of the reference site's order-tracking row. Hidden below `md`, where its links live in the mobile drawer. |
| Search | A real `<form action="/search" method="get">`, so it works before JS loads. Typeahead (Phase 6 API) upgrades it in place. |
| WhatsApp | §23.1. Converts better than a phone number in this market; the reference site has no equivalent. |
| Quote basket | Count badge only when non-zero. |
| Departments | Levels 1 **and** 2 are real `<a href>` in the rendered HTML. This is how deep categories are crawled at all. |

**Megamenu behaviour.** Disclosure pattern, **not** `role="menu"` — `role="menu"` is for application menus and makes screen-reader users navigate site navigation as though it were a menubar. The department is simultaneously a link and the panel trigger: a button alone makes the department page unreachable without JS, a link alone makes the panel unopenable by keyboard. 150 ms hover intent on open, 250 ms on close (the pointer has to cross the gap between trigger and panel, and closing on that gap is the single most irritating megamenu bug). Level 3 loads on intent, keeping the header under ~150 anchors.

### 2.2 Footer ✅ `ol-site-footer`

Five columns — Shop, Help & support, Company, Policies, Contact — on the §23.4 model. It is a real link surface, which fixes legacy defect 4K (no discernible internal linking).

Two other legacy defects are fixed here specifically:
- **4G** Social links pointed at `http://officeland`, not a URL, in five places. They are **omitted entirely** until real profile URLs arrive (Q17), because these also feed `Organization.sameAs` and asserting broken URLs as the company's identity is worse than asserting none.
- **4H** Emails were rendered as `[email protected]`, machine-unreadable for entity extraction. They are now plain, marked-up text.

### 2.3 Mobile drawer ⬜ *Phase 8*

Off-canvas, focus-trapped, Escape closes and returns focus to the trigger. Contains: search, department tree (accordion, one level at a time), utility links, WhatsApp, quote basket. **The desktop megamenu markup is reused** — the same anchors, restyled — so the mobile experience cannot drift from the crawlable one.

---

## 3. Page templates

### 3.1 Home ✅ `ol-home-page`

```
┌────────────────────────────────────────────────────────┐
│  H1  Nigeria's office supply partner                   │
│      Furniture, IT and consumables for business…       │
│      [Browse the catalogue]  [Request a quote]         │
├────────────────────────────────────────────────────────┤
│  USP strip — 4 across                                  │  ✅
│  Showrooms │ 4.4★/138 │ Quotes in 1 day │ Nationwide   │
├────────────────────────────────────────────────────────┤
│  Shop by department — 7 tiles                          │  ⬜ Phase 8
│  Solution sets: "Fit out an office", "Set up a lab"    │  ⬜ Phase 8
│  Brands we supply — logo rail                          │  ⬜ Phase 8
│  Buying guides — 3 cards                               │  ⬜ Phase 12
└────────────────────────────────────────────────────────┘
```

The title replaces a **389-character** legacy one (defect 4A) and now fits the 60-character budget with "Lagos" intact — see D-030 for why that word survived the budget.

The USP strip does more conversion work than anything else on the page for a B2B visitor who has never heard of the company. Trust signals, not discounts.

> The 4.4★/138 rating is a **business** rating. It appears here as text and may appear in `LocalBusiness` markup on `/locations/**`. It must never enter `Product` JSON-LD as `aggregateRating` — that is a policy violation and a manual-action risk (§14.6).

### 3.2 Category ✅ `ol-category-page`

```
┌────────────────────────────────────────────────────────┐
│  Home / Office Furniture / Seating                     │  breadcrumbs, every non-home page
│  H1  Executive Chairs                                  │
│  intro copy, 150–300 words                             │  ⬜ Phase 12 (content)
├────────────────────────────────────────────────────────┤
│  Browse by category  →  every child as a real <a href> │  ✅ D-011
├────────────┬───────────────────────────────────────────┤
│  facet     │  product grid, 24 per page                │  ✅
│  rail      │  [card] [card] [card] [card]              │
│  (§4)      │  pagination: real <a> to page/2/          │
└────────────┴───────────────────────────────────────────┘
```

**D-011 is the load-bearing decision on this page.** §14.5 requires every product within 3 clicks of home; §7.1 caps categories at depth 3; §23.1 loads level-3 menu links on intent only. Together those make products 4 clicks minimum and the crawl-depth gate unpassable. Rendering every child category here as a real anchor gives home → department (1) → leaf (2) → product (3). Across 86 leaves and 15,000 products that is the difference between a crawlable catalogue and a quarter of it stranded.

**Indexability.** A category seeds as `is_indexable = false` and is raised only once it carries genuine intro copy (§7). It is still **rendered and linked** — that is a different question from whether it is indexed, and conflating the two would 404 links the site itself publishes (see `RenderManifestController`).

### 3.3 Product ✅ `ol-product-page`

```
┌────────────────────────────────────────────────────────┐
│  Home / Printers / Laser Printers                      │
│  H1  M404dn HP LaserJet Pro Mono Printer               │  [code][brand][name]
│  HP · Model W1A53A                                     │
├─────────────────────────┬──────────────────────────────┤
│                         │  Available on 7 day lead time│
│   gallery ⬜ Phase 8    │  Request a quote for pricing │
│                         │  [Add to quote] [WhatsApp]   │
│                         │  Min order 5, multiples of 5 │
├─────────────────────────┴──────────────────────────────┤
│  Overview — rewritten prose                            │
│  Specifications — grouped table                        │  ✅ highest-leverage per §23.3
│  Delivery & lead times ⬜  │  Related ⬜  │  Recently ⬜ │
└────────────────────────────────────────────────────────┘
```

| Decision | Reason |
|---|---|
| No price, ever | D-002. The CTA is driven by `pricing_mode` **from data**, so enabling posture C later is a data change, not a code change. |
| No `offers` in JSON-LD | §14.4. Emit `Product` without `offers` rather than fake a price. The cost — no price rich results — is real and accepted; `price: 0` is a lie a merchant feed would eventually be penalised for. `additionalProperty` carries the weight instead. |
| Lead time is prominent | The B2B equivalent of the consumer stock check (§23.2), and the thing buyers actually ask. |
| MOQ / step shown only when > 1 | Noise otherwise. |
| Grouped spec table | §23.3 calls it the single highest-leverage fix for the legacy site's unstructured supplier prose. Grouping arrives **with the source data** (Icecat `CategoryFeatureGroup`), not invented in the view. |

**Accordions.** Panels may be visually collapsed, but their content is always in the HTML with `aria-expanded` / `hidden` driving presentation. Content rendered only on click is content Google may never see.

### 3.4 Category index ✅ `ol-category-index-page`

`/c/` — all departments with their children. A second crawl path into the tree alongside the megamenu, and the destination of the legacy `/shop/` redirect. Carries `BreadcrumbList` and `ItemList`.

### 3.5 404 ✅ `ol-not-found-page`

Search box plus department links. `noindex`, and the server returns a **real 404 status** — a soft 404 (200 with "not found" content) is explicitly forbidden by §14.8.

### 3.6 Search results ⬜ *Phase 8*

`noindex, follow`. Exact SKU/MPN match short-circuits straight to the product. Zero-result pages show synonym suggestions and the nearest category, never a dead end.

### 3.7 Quote basket and request form ✅ `ol-basket-page`, `ol-quote-request-page`

Three fields only — **email, phone, optional message** — per the client's decision. No accounts, no addresses, no priced-quote cycle.

The basket is server-owned and identified by an opaque HttpOnly cookie (D-035). Quantities are coerced up to what each product allows rather than rejected (D-036), free-text lines cover what the catalogue does not list, and the list exports as CSV (D-006) because the buyer's next step is usually a spreadsheet.

Anti-abuse is invisible: a honeypot, a timing floor, and per-IP and per-email limits. A rejection is always shown — accepting and discarding would leave a real buyer waiting for a reply nobody knows to send (D-037).

The customer receives an HTML email receipt with the full quote detail; there is no PDF (Dompdf is out). **That email is Phase 10** — until it ships, the confirmation page's promise of a copy by email is not yet true.

---

## 4. Facet groups per category family

Facets are **per family**, not global: a facet rail offering "Page yield" on office chairs tells the buyer the site does not understand its own catalogue.

Rules that apply to every family:

- **Brand and price-band are never facets.** Brand has its own crawlable hub (`/brands/`), and there are no prices.
- **Facet URLs are `noindex, follow`** and use query parameters, never path segments. Faceted paths would multiply the crawlable URL space by every combination.
- **Only `is_indexable_facet` attributes may ever become a landing page**, and only by an editorial decision, one at a time.
- A facet with **fewer than 2 distinct values** in the current result set is hidden rather than shown empty.

| Family | Facet groups (display order) |
|---|---|
| **Office Furniture** | Availability & lead time · Seat/frame material · Maximum load · Colour · Adjustability (height, lumbar, arms) · Dimensions · Assembly (flat-pack / assembled) |
| **IT Products** | Availability & lead time · Form factor · Capacity · Interfaces · Screen size · Processor family · Memory · Warranty term |
| **Printers & Consumables** | Availability & lead time · Print technology (laser/inkjet) · Colour vs mono · Functions (print/scan/copy/fax) · Print speed · Automatic duplex · Connectivity · **Compatible printer model** · Page yield · Cartridge colour |
| **Office Supplies** | Availability & lead time · Pack size · Colour · Material · Refillable · Tip/line width |
| **Paper & Notebooks** | Availability & lead time · Size (A4/A3/foolscap) · Weight (gsm) · Sheets per pack · Packs per box · Colour · Finish |
| **Filing & Storage** | Availability & lead time · Capacity (files/sheets) · Size compatibility (**foolscap vs A4**) · Material · Lockable · Number of drawers/tiers · Colour |
| **School Supplies** | Availability & lead time · Age/stage · Pack size · Subject · Colour |

Two entries carry disproportionate weight for this market and are called out deliberately:

- **Compatible printer model** is the single most valuable facet on the site. Consumables are bought by "what fits my machine", and it is the query that most often arrives as a bare model code — which is why the search engine short-circuits exact SKU/MPN matches.
- **Foolscap vs A4** in Filing & Storage. Nigerian records come in foolscap suspension files; a cabinet cut for A4 will not take them, and that discovery usually happens after delivery.

---

## 5. Component inventory

| Component | Status | Notes |
|---|---|---|
| `ol-site-header` | ✅ | Skip link, announcement, utility bar, search form, megamenu |
| `ol-site-footer` | ✅ | Five columns, machine-readable contact details |
| `ol-home-page` | ✅ | Hero + USP strip |
| `ol-category-index-page` | ✅ | Departments hub |
| `ol-category-page` | ✅ | Breadcrumbs, D-011 child anchors |
| `ol-product-page` | ✅ | Breadcrumbs, CTAs, overview, grouped specs |
| `ol-not-found-page` | ✅ | Search + departments, real 404 |
| `SeoService` | ✅ | Sole owner of title/meta/canonical/OG/JSON-LD |
| `TrailingSlashUrlSerializer` | ✅ | D-028 |
| `ServerApiBackend` | ✅ | D-027 |
| Mobile drawer | ⬜ P8 | Reuses megamenu markup |
| Product card | ✅ | One shape for PLP, search, related rails — 18 tests |
| Facet rail | ✅ | Per §4 above; real anchors, live counts |
| Pagination | ✅ | Real anchors on `page/N` paths (D-032), `rel=next`/`rel=prev` |
| Product image | ✅ | `<picture>` over Phase 4 derivatives, intrinsic dimensions |
| Gallery | ⬜ P8 | Multi-image PDP gallery with thumbnails |
| Typeahead | ⬜ P8 | Progressive enhancement over the existing form |
| Quote basket + form | ✅ | Three fields; CSV export; server-authoritative lines |
| Delivery/lead-time checker | ⬜ P9 | |

---

## 6. Accessibility commitments

Gate: **axe-core, zero critical violations** (SEO_SPEC §10.7).

- Skip link is the first focusable element on every page.
- One `<h1>` per page; heading levels never skip. Asserted by the raw-HTML gate.
- Every disclosure is keyboard-operable; Escape closes and restores focus.
- Focus is visible and never removed — only restyled.
- Colour contrast ≥ 4.5:1 for body text. **Pending Q17**: the placeholder brand tokens have not been contrast-checked against final brand colours, and this is the one accessibility commitment that Q17 can invalidate.
- Icons that carry meaning have accessible names; decorative ones are `aria-hidden`.
- Landmarks: one `<main id="main">`, `<nav>` elements labelled (`Departments`, `Support`, `Breadcrumb`, `Subcategories`).

---

## 7. Performance budgets (§14.9)

| Page | Initial JS (gzipped) | Page weight |
|---|---|---|
| Home | 130 KB | — |
| Product | 160 KB | 800 KB |

Routes are lazy-loaded per template to stay inside these. Under D-009 the HTML arrives prerendered, so the budgets govern hydration cost rather than first paint.

---

## 8. Open questions affecting this document

| Ref | Blocks |
|---|---|
| **Q17** | Brand assets, exact hex, fonts, social profile URLs. Blocks visual identity, `Organization.sameAs`, and the contrast commitment in §6. |
| **Q-B** | Who writes 15,000 product descriptions and 124 category intros. Category `is_indexable` cannot be raised without it, and it is the launch-limiting factor (D-010). |
