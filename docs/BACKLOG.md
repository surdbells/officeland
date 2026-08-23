# Backlog

Everything outstanding, in one place. Updated as items land.

**Why this file exists.** Requests were arriving faster than they were being
completed and were being tracked in conversation, which meant "the office
address should have images" had to be asked three times. A file is not a
better memory than a person; it is a memory that survives the conversation.

**How to read it.** *Blocked* means it cannot be done here without something
from outside — a file, a credential, a decision. Everything else is work.

Last updated: 2026-08-23.

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

- [ ] **Design tokens: premium feel**, referencing
      `essentials.pixfort.com/store-elementor`. Asked twice. The ramp, display
      type, shadows, radii and easings were reworked once already, so a second
      pass needs to know what specifically still reads as cheap — spacing,
      type scale, colour, density, or the absence of imagery. Probably the
      last, which makes it partly blocked on §1.
- [ ] **Secondary colour `#004f9a`.** Not yet in the token ramp. Needs a
      contrast check against every surface it will land on before it ships.
- [ ] **Walmart-style promo band on the homepage** — headline, "Shop now",
      three product tiles, carousel arrows. Buildable now; the tiles want
      product imagery to be worth much.
- [ ] **Subcategory circles above the listing**, per the pixfort reference:
      a row of round category thumbnails between the breadcrumb and the grid
      on a category page. Buildable now, tinted until images exist.
- [ ] **Multiple display layouts** on listings — the 2/3/4-column and list
      toggles in the reference. URL-driven so the choice survives a reload
      and stays prerenderable.
- [ ] **Tailwind throughout.** Some components carry hand-written CSS in
      `styles:` blocks — the carousel buttons, the masthead, `.ol-prose`.
      Audit which of those are genuinely outside Tailwind's reach (`:host`,
      `::-webkit-scrollbar`, injected-HTML descendants) and convert the rest.
- [ ] **Seed products, categories, brands and their images properly.** The
      catalogue is 10 products against 170 categories, which is why seven
      departments show no brands and every listing is nearly empty. This is
      the single change that would make the site look finished, and it is
      mostly §1 — images — plus a decision about whether to seed
      representative demo data or wait for the real import.

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
