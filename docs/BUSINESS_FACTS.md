# Verified business facts

Sourced from the legacy site's own footer, supplied 2026-08-23. **These are the real values** — everything in the codebase that currently guesses at them should be replaced from here.

Recorded separately because they are facts about the business, not decisions about the software, and several of them close or reshape open questions.

---

## Locations

Three, and the third one changes a decision (see §4).

| | Address |
|---|---|
| **Head Office** | Century 21 Mall, 14B Idowu Martins Street, Victoria Island, Lagos, Nigeria |
| **Furniture Showroom** | No 18, Idowu Martins Street, Victoria Island, Lagos State, Nigeria |
| **Dubai Office** | Office Land Solutions DMCC, Unit 808-001 Platinum Tower, Plot JLT-PH1-12, Jumeirah Lakes Towers, Dubai, UAE |

The two Lagos locations are both on Idowu Martins Street, which is why "two Victoria Island showrooms" has been a safe claim throughout.

> **This unblocks 12d.** The `locations` table exists from Phase 2 with `address`, `city`, `country`, lat/long and `opening_hours` for `LocalBusiness` JSON-LD. It has never been populated because nobody had the addresses. Now it can be — **except** opening hours and coordinates, which are still needed.

## Contact

| | |
|---|---|
| Sales | `sales@officelandng.com` |
| Support | `support@officelandng.com` |
| Phone | `+234 707 472 3165` |

The phone number matches the WhatsApp number already in the header (`wa.me/2347074723165`) — same line.

> `MAIL_SALES_ADDRESS` should be `sales@officelandng.com`. The quote-routing default (D-046) currently falls back to a guessed address; this replaces it.

## Social

Facebook, X, Instagram, YouTube, **Telegram**.

Telegram is unusual for a B2B supplier in this market and worth keeping — it is a real channel for bulk buyers.

> **URLs still needed.** Q17 asked for social URLs; this gives the platforms but not the handles. `Organization` JSON-LD `sameAs` cannot be emitted without them.

## Certification

**Standards Organisation of Nigeria (SON)** — displayed as a badge in the legacy footer.

> Worth more than it looks. For government and institutional tenders, SON certification is frequently a *qualification requirement*, not a trust badge. It belongs near the bulk/tender call to action, not buried in the footer.

## Positioning statement

> "We are committed to setting the benchmark for excellence in office supplies and workplace solutions by consistently delivering superior quality and an exceptional customer experience every time."

Generic, and it should be rewritten before it goes anywhere near a page that matters. Recorded because it is what the business currently says about itself.

---

## What this changes

### Q14 — a UAE presence exists, so hreflang is a live question again

Q14 asked whether a Dubai/UAE-targeted variant is in scope, and it has been parked. **There is a registered UAE entity** — Office Land Solutions DMCC — with its own address.

That does not automatically mean a UAE site. It does mean the question is commercial rather than hypothetical, and it needs an answer before cutover:

- **If the UAE entity sells**, an `hreflang` cluster or a separate domain is a real requirement, and SEO_SPEC §8 is explicit that a broken hreflang cluster is worse than none.
- **If it is an administrative or procurement office only**, the Dubai address belongs on the contact page and nowhere else — and it must *not* appear in `LocalBusiness` markup, which would tell Google the business serves UAE customers.

**Getting this wrong in either direction is expensive**, and it is one question to a person who already knows the answer.

### Q17 — partially closed

Confirmed: brand primary `#0046bf`, the social platforms, the real contact details, and the legal entity name (**OfficeLand Limited** / "OFFICE LAND LIMITED").

Still outstanding: **logo files**, licensed Montserrat/Inter webfonts, and the social profile URLs.

### 12d — unblocked except for two fields

`locations` can be seeded now. Still needed: **opening hours** per location, and **latitude/longitude** for each. Both are required for useful `LocalBusiness` JSON-LD, and the coordinates matter for local search.

---

## Open

- Social profile URLs (handles).
- Opening hours and coordinates per location.
- Whether the Dubai entity trades — see Q14 above.
- Logo files, in SVG if they exist.
- Whether SON certification should be surfaced against tender/bulk pages rather than only the footer.

---

## Google Business Profile

Supplied 2026-08-23.

| | |
|---|---|
| Listing name | **Office Land Ltd** |
| Rating | **4.4★ from 138 reviews** |
| Pin | Idowu Martins St / Kofo Abayomi St, Victoria Island, Lagos |

This **verifies the claim already on the site.** The trust strip has been asserting "4.4★ from 138 Google reviews" since Phase 7 on the basis of the Phase 0 crawl; it is real.

Review content is also useful evidence of what the business is actually known for: HP products, JBL audio, laptop accessories, and repeat corporate custom over several years. That is a *reseller of branded IT* as much as a furniture supplier, which supports the megamenu weighting.

> One review mentions **"Office Land Ltd @ The Mega Plaza store"**. That is a fourth location, or a former one, and it is not in the footer. Worth confirming before the locations page is built — a stale address on a locations page sends customers to a shop that is not there.

### Embedding the map and reviews — three real constraints

The screenshot shows an embedded Google map and a Google review carousel. Neither can be dropped in as-is.

**1. The CSP blocks both.** The policy is `default-src 'self'` with no `frame-src` and `connect-src 'self'` (D-053). A Google Maps iframe and a review widget would both be blocked outright. Allowing them means widening the policy — a deliberate decision, not an oversight to patch around.

**2. A live map embed is expensive.** A Maps iframe pulls several hundred KB of third-party JavaScript and is a well-known LCP and INP regression. The homepage JS budget is 130 KB gzipped **total**. The standard fix is a *facade*: a static map image that loads the interactive embed only on click. That keeps the budget and still gives directions in one tap.

**3. Marking up Google reviews as our own `AggregateRating` is against Google's own guidelines.** Structured-data policy prohibits self-serving review markup — an `AggregateRating` on `Organization` describing reviews collected on a third-party platform. Displaying them is fine; claiming them in JSON-LD risks a manual action.

This is what **Q15** (first-party reviews) was really asking. If review stars in search results are wanted, the reviews have to be collected first-party, on the site, with `Product` or `LocalBusiness` markup.

> **Recommended:** a static map facade linking to the real Google Maps listing for directions, review quotes shown as plain testimonial text with attribution, and **no** `AggregateRating` markup until Q15 is resolved.
