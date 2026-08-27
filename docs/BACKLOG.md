# Backlog

Everything outstanding, in one place. Updated as items land.

**Why this file exists.** Requests were arriving faster than they were being
completed and were being tracked in conversation, which meant "the office
address should have images" had to be asked three times. A file is not a
better memory than a person; it is a memory that survives the conversation.

**How to read it.** *Blocked* means it cannot be done here without something
from outside — a file, a credential, a decision. Everything else is work.

Last updated: 2026-08-27.


---

## 0. Landed since the last snapshot

- **Category imagery.** Nine department heroes, real licensed photography
  (docs/images/SOURCES.md). `media:import` now generates derivatives, which
  it did not — it imported and attached images that rendered as nothing.
- **Crawl depth fixed.** 224 products were 4 clicks from home; department
  pages now render two levels of child anchors and none are deeper than 3.
- **Catalogue populated.** `DemoCatalogueSeeder` creates 1,190 products —
  10 per leaf category, 119 leaves, 23 brands — so every department, listing,
  facet and brand row has realistic volume. **Every product is invented and
  every one is `noindex`.** See §6.
- **Megamenu brands now match the department**, derived from what each
  department actually stocks rather than one global featured list.
- Search bar fills the header row.
- Department rail is a carousel with no scrollbar.
- Category images, title tags and meta descriptions wired end to end;
  46 categories have written titles and descriptions.
- Twelve missing pages built, plus a link gate so dead internal links fail
  the build.
- Deployment procedure for Debian 13 + aaPanel.
---

## 1. Blocked on assets or decisions

These are not estimates. Each one is stopped on something specific.

| Item | Blocked on | What unblocks it |
| --- | --- | --- |
| **Showroom photos on the location cards** | The three photographs | Drop them in `docs/images/` with `.txt` sidecars, run `media:import` (§4) |
| **Category hero images** | Photographs, one per category | Same command; filename names the category |
| **Brand logos in the megamenu** | Logo files + a trademark-use decision | Same command, `--attach-brands` (not yet written, §3) |
| **Hero section imagery** | Licensed or free-licensed files **in the repo** — `img-src 'self'` rules out hotlinking | Files, plus their licence recorded |
| **Testimonials** | Real review text | A Google Business Profile export, or quotes with permission to publish |
| **Social profile URLs** (Q17) | The handles | Feeds `Organization.sameAs`; omitted rather than guessed |
| **Licensed Montserrat / Inter webfonts** (Q17) | The files | Type is currently system-font fallback |
| **Embedded map** | A decision, not an asset | Either a `frame-src` CSP exception (several hundred KB of third-party JS against a 130 KB budget, D-053) or a Static Maps key with the image fetched at build time and its licence checked |
| **Legal review of the policy pages** | A lawyer | `/privacy/`, `/terms/`, `/cookies/` are accurate about the system and structured against the NDPA 2023, and nobody here can sign them off |
| **Icecat ingestion** (Phase 5b) | Q-A | Credentials are entered by the client on their own server |
| **Dubai trading status** (Q14) | An answer | Decides `LocalBusiness` markup and whether hreflang is needed |
| **Google review aggregate in markup** (Q15) | Nothing — settled | Displayed as text, never marked up. Third-party reviews as our own `aggregateRating` is against Google's policy |

---

## 2. Requested, not started

In the order asked.

- [ ] **Breadcrumbs as round category chips with image and name**, per the
      pixfort reference. The chips are image wells, so they render as tinted
      circles until §1 lands. Note the trade: a chip row is not a breadcrumb —
      `BreadcrumbList` JSON-LD and the ancestor trail are what Google reads
      for site structure, so the chips have to be an addition beside the
      breadcrumb or carry the same markup, not replace it.
- [ ] **PDP premium polish — "industry standard, all necessary features".**
      Needs unpacking before it is buildable. Present: gallery, specs
      accordion, breadcrumb, structured data, quote basket, related rails.
      Candidates: sticky quote panel, spec comparison, stock/lead-time
      clarity, delivery estimate, downloadable datasheet, recently viewed,
      Q&A. Several imply data that does not exist yet.
- [ ] **All products must have images.** 1,200 products, no files. Generating
      product photographs is not an option — an invented photograph of a real
      product is a lie a buyer acts on. Until files exist the cards show a
      designed empty state rather than a broken frame.
- [ ] **Homepage product sections per major department, in varied layouts.**
      Buildable now that the catalogue is populated; carries far more once
      products have photographs.
- [ ] **Brand logos in the megamenu** — the row renders a logo the moment one
      exists; 23 brands, none with a file. Needs `--attach-brands` (§3) and
      the files.
- [ ] **Subcategory circles above the listing**, per the pixfort reference:
      round category thumbnails between the breadcrumb and the grid. The
      layout is buildable now; the circles are image wells, so tinted until
      §1 lands.
- [ ] **Multiple display layouts** on listings — 2/3/4-column and list
      toggles. URL-driven so the choice survives a reload and stays
      prerenderable.
- [ ] **Walmart-style promo band on the homepage** — headline, "Shop now",
      three product tiles, carousel arrows.
- [ ] **Tailwind throughout.** Some components carry hand-written CSS in
      `styles:` blocks — carousel buttons, masthead, `.ol-prose`. Audit which
      are genuinely outside Tailwind's reach (`:host`, `::-webkit-scrollbar`,
      injected-HTML descendants) and convert the rest.
- [ ] **Secondary colour `#004f9a`.** Needs a contrast check against every
      surface it will land on. It is close enough to `#0046bf` that the job
      it does — hover, dark surfaces, accents — decides where it belongs.
- [ ] **Design tokens: premium feel** (pixfort reference). Asked three times.
      Reworked once. The remaining gap is most likely photography rather than
      tokens, which makes it partly §1.
---

## 3. Known gaps in what has shipped

Things that work but are incomplete, found while building other things.

- [ ] `media:import --attach-brands`. `--attach-categories` exists; the brand
      equivalent does not, so brand logos cannot be attached by filename yet.
- [ ] No Filament resource for media. Images can only enter through the
      command, so the client cannot upload one from the admin.
- [ ] `/quote/track/` has no self-service lookup. Deliberate — a lookup keyed
      on the reference alone lets anybody enumerate other people's
      quotations, so it needs reference *and* email, rate limiting, and a
      decision about what a wrong pair reveals. The page says so plainly.
- [ ] Long-form buying guides. `/guides/` points at the category intros,
      which is where the guidance actually is, and says longer ones are
      coming.
- [ ] Phase 8 gate: Core Web Vitals measured, crawl-depth report, build-time
      budget. The only original phase gate still open.
- [ ] Intro copy for the 124 categories outside Smart Tech and WPC. They stay
      `noindex` until written, which is the content floor working as designed
      (D-048).

---

## 4. Adding images

The pipeline has been complete since Phase 4 and had no entry point until
`media:import` — which is why the media table was empty and the whole
storefront is typographic.

```bash
# One .txt sidecar per image, holding its alt text.
#   docs/images/office-furniture-seating.jpg
#   docs/images/office-furniture-seating.txt   -> "Mesh-back task chairs in the Victoria Island showroom"

docker compose exec api php artisan media:import --path=/docs/images                      # dry run
docker compose exec api php artisan media:import --path=/docs/images --attach-categories --apply
sh scripts/serve.sh --rebuild
```

A file without a sidecar is skipped and reported. §12.6 makes alt text
mandatory, and an invented one is a lie to a screen reader.

Filenames name the category: `wpc-doors-interiors-wpc-doors.jpg` becomes the
hero of `/wpc-doors-interiors/wpc-doors/`.

---

## 5. Before production

Deployment is documented in [DEPLOYMENT.md](DEPLOYMENT.md). Staging is safe
now; production is not, and these are why.

- [ ] **The storefront hardcodes `https://officelandng.com`** as its origin
      for canonical tags and JSON-LD (`seo.service.ts`, `structured-data.ts`).
      A staging deploy canonicalises to production. Harmless while staging is
      `noindex`, wrong the moment it is not, and it needs a build-time origin
      rather than a constant.
- [ ] **Catalogue is 10 products against 170 categories.** The single biggest
      reason not to go live.
- [ ] **Policy pages need legal review** (§1).
- [ ] **No images anywhere** (§1).
- [ ] 124 categories without intro copy stay `noindex` by design (D-048) —
      correct behaviour, but it means most of the site is not indexable yet.

---

## 6. The demo catalogue

`DemoCatalogueSeeder` fills the storefront so the design can be judged
against realistic volume. It is not a substitute for the real catalogue and
it is built so it can never be mistaken for one.

```bash
docker compose exec api php artisan db:seed --class=DemoCatalogueSeeder
docker compose exec api php artisan demo:purge --apply     # take it out again
```

**Every product is invented** — plausible names, fabricated SKUs, generic
specifications, nothing corresponding to real stock. Three things keep that
contained:

- Every SKU begins `DEMO-`, so they are obvious in the admin and to
  `demo:purge`.
- The seeder **throws** if `APP_ENV=production`. Invented products on a live
  site get quoted against, and somebody then has to have that conversation
  with a customer.
- **Every one is `noindex`, and that is the point rather than a limitation.**
  §14.7 wants 120 words of original description per product and D-050
  rejects near-duplicates within a family. Generating 1,190 descriptions
  would either fail that floor or pass it with exactly the thin, templated
  content the floor exists to keep out of Google. Real products earn their
  way in with real copy, as the 46 written categories already do.

**Cost:** the build goes from ~3 minutes to roughly 15, because every active
product is a prerendered page (`RouteManifest::productSlugs`). Worth knowing
before a deploy; `demo:purge` reverses it.

---

## 7. Freepik attribution — before public launch

The nine category photographs are Freepik free-licence, which **requires
visible credit**. There is none on the site.

It needs a decision about where it lives: a `/credits/` page linked from the
footer, or a line in the footer itself. Either is a small change; leaving it
undecided until launch day is the failure mode.

The requirement disappears entirely if these are replaced with OfficeLand's
own photography, which is the intended path anyway — see
docs/images/SOURCES.md.
