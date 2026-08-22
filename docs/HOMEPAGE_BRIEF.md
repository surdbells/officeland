# Homepage — build brief

A refined version of the original one-line brief, resolved against the decisions this project has already made. **Hand this to Claude (or a developer) as the prompt.**

The original asked for: centred animated logo on a brand-colour background; megamenu, live search, contact number with icons; a 65/35 hero of image slides plus two vertical banners; a category carousel; and a best-sellers section — with Walmart as the design reference.

Four of those needed resolving before they could be built. Those resolutions are in §1. The rest of the document is the buildable spec.

---

## 1. Resolved conflicts — read this first

### 1.1 "Best sellers" → an editorially curated rail

**There is no sales data and there never will be.** D-002 removed pricing; D-005 made this lead-capture only. No orders, no transactions, no basket conversions. Nothing in the system knows what sells.

**Decision: a curated rail, chosen by sales in Filament.** Honest at launch when there is no data at all, and it gives merchandising direct control over what a first-time visitor sees.

- Heading: **"What Lagos offices order most"** — not "Best sellers", which claims a fact we do not have.
- 8 products, ordered, managed in Filament.
- Reuses the existing `ProductCard` (Phase 8b — one card on every surface). **Do not build a second card.**
- Falls back to the most-quoted products if the curated list is empty, so the section is never blank.

> Revisit once the quote pipeline has run for a quarter: quote line-item counts are real first-party demand and a defensible basis for "most requested".

### 1.2 Walmart — what transfers and what does not

Walmart is a **transactional B2C retailer**. This is a **quote-first B2B/B2G supplier with no public prices**. Copying its homepage wholesale would contradict D-002 and D-005 on the first screen.

| Take | Leave |
|---|---|
| Information density — no wasted vertical space | Price-led product cards |
| The category tile carousel | "Add to cart", deal badges, countdown timers |
| Megamenu structure and hover-intent behaviour | Rollbacks / savings / "was £x" |
| Strong grid discipline and consistent card sizing | Personalisation and recommendation rails |
| Utility bar for account/order/support links | Anything implying instant checkout |

**The substitute for price is speed and certainty.** Where Walmart shows a price, this site shows lead time, availability and "quotes answered within 1 business day". That is the competitive claim, and it should be as prominent as a price would be.

### 1.3 The logo animation must not delay content

An animated logo on a full-bleed brand-colour background is, structurally, a splash screen — and a splash screen is the single most reliable way to fail the LCP budget in SEO_SPEC §9.

**Build it as a band, not an overlay.**

- A brand-colour band at the top of the page containing the centred logo. It is *part of* the layout, not on top of it.
- The animation is **CSS only** — no JavaScript. A JS-driven entry animation cannot run before hydration, so it would either not play or play late and cause a layout shift.
- **Nothing below it may be hidden or delayed.** The hero must be painted and readable while the logo animates.
- Wrap in `@media (prefers-reduced-motion: reduce)` — no transform, no opacity fade, static logo.
- Fixed height with an explicit aspect ratio, or it contributes CLS. Current CLS is **0** and the accessibility gate protects it; do not regress that.
- The animation must not be the LCP element. If it is, the budget has been lost to decoration.

> **The brand primary hex is unconfirmed (Q17).** Use the existing `--color-brand-600` token. Do not hardcode a colour.

### 1.4 Images need a licence, not a search result

"Get appropriate images" cannot mean pulling stock photography off the web. Phase 4 enforces content-addressed storage with **mandatory alt text** at the model layer, and commercial use needs a licence.

Acceptable sources, in order of preference:

1. **OfficeLand's own photography** — the two Victoria Island showrooms, real deliveries, real installations. Strongest option by a distance: it is unique, it is honest, and it is the thing competitors cannot copy.
2. **Manufacturer press assets** from the brands already supplied (HP, Canon, Epson, Logitech, Kingston) — usually licensed for reseller use, but confirm per brand.
3. **Paid stock** with a commercial licence on file.

**Never** an image whose licence nobody can name. Every image needs alt text describing what it shows — the model layer will reject it otherwise.

---

## 2. What already exists — do not rebuild it

Read these before writing anything. The homepage assembles existing components; it should add very little new code.

| Component | Where | Note |
|---|---|---|
| Header, megamenu, utility bar | `core/layout/site-header.ts` | Levels 1–2 are real `<a href>`; level 3 loads on intent |
| Live search | header form + `features/search` | Real `<form>`, works before JS; typeahead from Phase 6 |
| Product card | `shared/product-card.ts` | One card for every surface (Phase 8b) |
| Product image | `shared/product-image.ts` | `<picture>` with AVIF/WebP/JPEG srcsets, intrinsic dimensions |
| Footer | `core/layout/site-footer.ts` | |
| SEO / JSON-LD | `core/seo/seo.service.ts` | |

The megamenu and live search in the original brief **are already built**. The homepage work is the band, the hero, the carousel and the curated rail.

---

## 3. Page structure

Top to bottom. Everything is server-rendered into the prerendered HTML (D-009) — no section may depend on JavaScript to appear.

```
┌─ announcement bar          exists
├─ utility bar               exists — ADD phone + WhatsApp with icons
├─ header                    exists — logo, search, quote basket
├─ megamenu                  exists
├─ BRAND BAND                NEW — centred logo, CSS entry animation
├─ HERO 65 / 35              NEW — slides left, two stacked banners right
├─ trust strip               exists — showrooms, reviews, response time, delivery
├─ CATEGORY CAROUSEL         NEW — image + name, real anchors
├─ CURATED RAIL              NEW — "What Lagos offices order most", 8 products
├─ bulk & tender CTA         NEW — the B2G/B2B path, see §3.6
└─ footer                    exists
```

### 3.1 Utility bar — contact with icons

Add to the existing bar, right-aligned beside Contact:

- **Phone**: `<a href="tel:+2347074723165">` with a phone icon. Visible on mobile, where it is the highest-converting element on the page.
- **WhatsApp**: already in the header; keep one instance, not two.

Icons must be inline SVG with `aria-hidden="true"` and a visible or `sr-only` text label. **Never an icon alone** — the accessibility gate fails on links with no accessible name.

### 3.2 Hero — 65 / 35

**Desktop ≥1024px:** CSS Grid, `grid-template-columns: 65fr 35fr`, one gutter gap.
**Below 1024px:** single column — slides first, then banners side by side, then stacked below 640px.

**Left, 65% — image slides**

- 3–5 slides. Each is a real `<a>` wrapping an image and a text overlay.
- **All slides in the HTML.** Hidden slides use CSS, never `@if` — a crawler must see every destination, and this is the same rule as the PDP gallery and spec accordions.
- **Slide 1 is the LCP element.** `loading="eager"`, `fetchpriority="high"`, no lazy loading, explicit `width`/`height`.
- **No autoplay before hydration.** If autoplay is wanted at all: pause on hover, pause on focus, respect `prefers-reduced-motion`, and provide previous/next buttons plus dot controls with real accessible names.
- Controls are `<button type="button">` with `aria-label`. Slide container gets `aria-roledescription="carousel"` and a label.
- Fixed aspect ratio (16:9 or 2:1) so rotation causes **zero** layout shift.

**Right, 35% — two stacked vertical banners**

Equal height, summing to the slide height. Each a single real anchor with image + heading + one line.

Suggested, and they should earn their place:
1. **Bulk & tender supply** → `/bulk-supply/` — the B2G path, and the highest-value visitor on the site.
2. **Showroom visit** → `/locations/` — "See the furniture before you commit" is a genuine differentiator against online-only competitors.

### 3.3 Category carousel

- Source: level-1 departments from the existing navigation endpoint. **Do not hardcode.**
- Each tile: image, category name, real `<a href>` to `/c/{slug}/`.
- **CSS scroll-snap, not a JavaScript carousel.** It works before hydration, it is keyboard-navigable for free, and it costs no JS budget.
- Horizontal scroll on mobile; fits without scrolling on desktop where it can.
- The container scrolls; **the page must not** — `overflow-x: auto` on the rail only. Horizontal page overflow at 375px is a gate failure.
- Every tile is a crawlable anchor. This reinforces D-011's crawl-depth guarantee.

### 3.4 Curated rail

- Heading: **"What Lagos offices order most"**.
- 8 products via `ProductCard`, in a responsive grid (4 / 3 / 2 across desktop / tablet / mobile).
- Managed in Filament: a `HomepageFeature` model or a `homepage_featured` flag with a sort order. Admin sets the list; it is not derived.
- **Fallback when empty:** the 8 most-quoted products by quote line-item count. The section must never render blank.
- Each card must clear the Phase 12 content floor or it will not be indexable anyway — curate from products that are actually finished.

### 3.5 Trust strip

Already built. Keep it directly under the hero: it carries the four claims that substitute for price (showrooms, reviews, response time, nationwide delivery).

### 3.6 Bulk & tender CTA

A full-width band above the footer. Institutional buyers — ministries, schools, corporates — are the highest-value segment and the one a Walmart-style layout has no equivalent for. One heading, one line, one button to `/bulk-supply/`.

---

## 4. Constraints — non-negotiable

| # | Constraint | Why |
|---|---|---|
| 1 | **No inline `<script>` and no `on*` attributes** | The CSP hashes inline scripts (D-053). New ones fail CI, and an inline handler needs `unsafe-hashes`. |
| 2 | **Every section server-rendered** | D-009 prerenders to static HTML. A JS-only section is invisible to crawlers and to the raw-HTML gate. |
| 3 | **Every destination a real `<a href>`** | Crawl depth (D-011). Buttons that navigate are not crawlable. |
| 4 | **Images through `ProductImage` / the media pipeline** | AVIF/WebP/JPEG, srcsets, intrinsic dimensions, mandatory alt text (Phase 4f). |
| 5 | **CLS must stay 0** | Measured at 0 today. Every image and slide needs explicit dimensions. |
| 6 | **JS budget: 130 KB gzipped for the homepage** | SEO_SPEC §9. Currently ~107 KB on a category page — there is headroom, not a lot. |
| 7 | **No horizontal overflow at 375px** | Verified today; a carousel is the most likely thing to break it. |
| 8 | **WCAG 2.2 AA** | `npm run gate:a11y` runs over every page in CI. Carousels are the classic failure: keyboard traps, autoplay with no pause, controls with no accessible name. |
| 9 | **Exactly one `<h1>`** | Gate enforced. The hero owns it. |
| 10 | **No prices, anywhere** | D-002. Not "POA", not "from" — the field does not exist. |

---

## 5. Definition of done

```bash
docker compose exec render sh -lc 'cd /repo/apps/web && npx ng build'
docker compose restart render                      # publish — see RUNBOOK §2 step 3
cd apps/web && node tools/csp-hashes.mjs --write   # only if inline scripts changed
docker compose restart api
cd apps/web && npm run gate                        # raw-html, crawl-depth, csp, a11y
```

All four gates green, plus in a browser:

- [ ] CLS still **0** on the homepage
- [ ] No horizontal overflow at 375, 768 and 1280
- [ ] LCP element is hero slide 1, not the logo band
- [ ] Carousel is keyboard-operable; controls have accessible names
- [ ] `prefers-reduced-motion` stops the logo animation and any autoplay
- [ ] Every category tile and slide is a real anchor in `view-source`
- [ ] Contact number is a working `tel:` link on mobile

---

## 6. Still open

- **Q17** — brand primary hex, logo files, social URLs. Use existing tokens until answered.
- **Image licensing** — §1.4. Blocks the hero and the category tiles.
- **Slide content** — nobody has written the 3–5 hero messages. They are the first thing a visitor reads and should not be filler.
- **Curated product list** — sales needs to choose 8. The fallback covers launch if they have not.
