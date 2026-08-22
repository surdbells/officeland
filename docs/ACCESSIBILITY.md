# Accessibility — WCAG 2.2 AA

**Phase 13c.** Verified 2026-08-22. Two layers, because neither is sufficient alone.

| Layer | Covers | Runs |
|---|---|---|
| `npm run gate:a11y` — axe-core under jsdom | every structural rule | CI, every push |
| Browser testing | colour contrast, target size, actual hit areas | manually, before release |

---

## The automated gate

`apps/web/tools/a11y-gate.mjs` runs **axe-core 4.13** against **all 147 prerendered pages**, on the `wcag2a`, `wcag2aa`, `wcag21a`, `wcag21aa`, `wcag22aa` and `best-practice` tag sets.

**It scans the pre-hydration HTML on purpose.** jsdom is configured `runScripts: 'outside-only'`, which allows axe to be injected while leaving the page's own scripts inert. That is the state a crawler and a screen reader meet first, and it has to be accessible on its own — running Angular would test the hydrated app and hide a server-rendered document that is only accessible once JavaScript repairs it.

**Every page, not a sample.** This is not caution for its own sake: the 9-page sample passed while a **critical** invalid-ARIA violation sat on 7 brand pages. Page types differ in what they render, and the cheap failure is one template used on one type.

### What it cannot check, and why that is stated rather than hidden

jsdom parses and builds a DOM. It does not lay out or paint, so two rules are **explicitly disabled** rather than left to report as "incomplete":

- `color-contrast` — needs computed colours against painted backgrounds
- `target-size` — needs geometry

A gate that reports a rule it never ran is worse than one that admits the gap. Both are covered below.

---

## Findings, and what fixed them

All four were found by tooling that did not exist before this phase.

### Six colour-contrast failures (browser)

`--color-ink-400` was `#8b95a3`: **3.03:1 on white** and **2.85:1 on ink-50**, against a 4.5:1 requirement. It carries product codes, spec labels and the whole footer — small text, which is exactly where contrast matters most.

Now `#68717d`: 4.94:1 and 4.65:1.

> **The binding constraint was the footer, not white.** A value tuned only against white passes the obvious check and still fails: `#6b7480` is 4.74:1 on white and **4.45:1 on ink-50**. Any future change to this token has to be checked against both.

### Content outside all landmarks (`region`, 9 page types)

The announcement bar sat outside `<header>` as a plain `<div>`, so its text belonged to no landmark and was skipped entirely by screen-reader landmark navigation. Now a `<section aria-label="Service announcement">`.

### Duplicate landmarks (`landmark-unique`, 2 page types)

The 404 and search pages each carry their own search form alongside the header's, and both announced as an unlabelled "search" region. Same for two "Departments" navs. All now carry distinguishing `aria-label`s — a screen reader offering two identical landmarks tells the user nothing about which is which.

### Invalid ARIA on facet links (`aria-allowed-attr`, **critical**, 7 pages)

The facet rail used `aria-pressed` on `<a>` elements. **`aria-pressed` is only supported where the role is button**; on an anchor it is invalid, and a screen reader may ignore it entirely — announcing nothing about whether a filter is applied.

Facets are deliberately real anchors (D-012), so the correct token is `aria-current="true"`. Emitted as `null` rather than `"false"` when unselected: `aria-current="false"` is legal but puts an attribute on every unapplied facet for nothing.

The same attribute on the gallery thumbnails is on `<button>` elements and is correct — it was left alone.

---

## Verified in a browser

Category page at 1280×800 and 375×812:

| Check | Result |
|---|---|
| Contrast, 88 text elements | **0 failures**, lowest ratio 4.65 |
| Structural axe rules, 147 pages | **0 violations** |
| Horizontal overflow at 375px | none — `scrollWidth` equals the viewport |
| Cumulative layout shift | **0**, no shifts recorded |
| Invalid `aria-pressed` on links | 0 |

**The product card's stretched hit area works.** Probes at the card's top-left, centre and lower-right all resolve to the product link, so the effective tap target is the whole **343×812** card rather than the 18px title line. That was a Phase 8b design decision, now confirmed rather than assumed.

---

## Open

- **Target size (SC 2.5.8).** Brand links inside product cards measure 15×16, and some inline navigation links are under 24×24. SC 2.5.8 has **inline** and **spacing** exceptions that may well cover them, and deciding that is a judgement about each control's context rather than a measurement. **Not claimed either way** — it needs a designer's call, and if the exceptions do not apply the fix is spacing, not size.
- **Keyboard and screen-reader walkthrough.** Focus order, focus visibility and the megamenu's disclosure behaviour under a real screen reader (NVDA or VoiceOver) are not automatable and have not been done. The megamenu deliberately uses a disclosure pattern rather than `role="menu"` (§23.1 rule 3), which is the right choice but needs confirming with an actual assistive technology.
- **Reduced motion and forced colours.** No `prefers-reduced-motion` or Windows High Contrast testing yet.
- **The admin panel is out of scope here.** Filament's own accessibility is upstream, and the panel is not a public surface.
