# Category Taxonomy & Information Architecture

**Status:** Phase 0 deliverable — awaiting client sign-off
**Supersedes:** the legacy WooCommerce `product_cat` tree (161 terms, audited 2026-08-19)

---

## 1. Design rules

These are enforced in code and in migrations, not by convention.

1. **Maximum depth 3.** `/c/{department}/{subcategory}/{leaf}/`. Enforced by a DB check constraint on `categories.depth`.
2. **Brand is never a path segment.** Brands are a facet and a separate hub page type at `/brands/{slug}`. This is the single largest structural fix versus the legacy tree.
3. **One concept, one node.** No type appearing in two branches.
4. **Every category carries unique intro copy** before it may be marked indexable (§14.7).
5. **Products are flat** at `/p/{slug}` and belong to many categories; exactly one is `primary_category_id` and it alone drives the canonical URL and the breadcrumb.

## 2. What the legacy tree got wrong

Audit of the live site, 2026-08-19:

| Defect | Evidence | Count |
|---|---|---|
| Brand used as a category branch | `hp/*`, `wiwu-accessories/*`, `ugreen/*`, `fellowes`, `lenovo`, `lexar`, `mercury`, `iris`, `mg-stationery/*` | **35 of 161 terms (22%)** |
| Same type in two branches | `office-chairs` (root) vs `office-furniture`; `laptop` vs `hp/laptops-hp`; `workstation` vs `office-tables/workstations`; `tv-monitor` vs `it-products/monitor` | 8 collisions |
| Ambiguous term | `storage` — furniture, filing, or IT storage? | 1 (high impact) |
| Overlapping departments | `stationery` vs `office-supplies` vs `school-supplies` | 3 |
| Non-categories in the taxonomy | `all-products`, `trending`, `uncategorized` | 3 |

`office-supplies/*`, `it-products/*` and `paper-products/*` were the only well-formed branches and are largely carried forward in shape, not in URL.

## 3. The new tree

Nine departments. `~` marks a leaf that exists mainly to absorb legacy terms and may be merged if it stays thin.

```
/c/office-furniture/
    seating/                    executive-chairs · ergonomic-task-chairs · conference-chairs
                                visitor-reception-chairs · recliner-lounge-chairs
    desks-tables/               executive-desks · workstations · reception-desks
                                conference-tables · height-adjustable-desks
    storage-furniture/          filing-cabinets · cupboards-lockers · bookcases-shelving
    partitions-screens/

/c/it-products/
    computers/                  laptops · desktops-all-in-ones · workstations-servers
    monitors-displays/          monitors · projectors-screens · tvs-digital-signage
    computer-accessories/       keyboards-mice · laptop-bags-cases · cables-adapters
                                hubs-docking-stations · headsets-audio
                                chargers-power-banks · stands-mounts
    storage-memory/             flash-drives · external-hard-drives · memory-cards · ssds-internal-drives
    networking/                 routers-access-points · switches-adapters · network-cables
    software/                   office-productivity · antivirus-security

/c/printers-consumables/
    printers/                   laser-printers · inkjet-printers · all-in-one-printers · large-format-plotters
    scanners/
    ink-toner/                  toner-cartridges · ink-cartridges · drums-maintenance-kits
    copiers/                    ~

/c/office-supplies/
    writing/                    pens · pencils · markers-highlighters · correction-erasers
    desk-accessories/           staplers-punches · clips-pins · cutting-tools
                                organisers-holders · fasteners
    adhesives-tapes/            tapes · glue · adhesives
    boards-presentation/        whiteboards · glass-boards · bulletin-boards · board-accessories
    office-machines/            shredders · binding-machines · laminators
                                calculators · paper-trimmers · air-purifiers
    labelling-identification/   labels · badges-lanyards · stamps · signs-displays

/c/paper-notebooks/
    printing-paper/
    notebooks-pads/
    sticky-notes/
    envelopes-mailing/
    specialty-paper/            photo-paper · colour-paper · paper-rolls · receipt-rolls

/c/filing-storage/
    files-folders/              lever-arch-files · ring-binders · manila-folders
                                suspension-files · document-files-boxes
                                display-books-clear-files · dividers-index · report-clip-files
    archive-boxes/
    desk-filing/                magazine-holders · document-trays

/c/school-supplies/
    art-craft/                  drawing-colouring · crayons-colour-pencils
    classroom-essentials/
    maths-geometry/             math-sets · rulers-stencils

/c/smart-tech/
    power-backup/               ups-systems · inverters · solar-panels · voltage-stabilisers
    security-surveillance/      cctv-cameras · video-doorbells · access-control · alarms-sensors
    conferencing-av/            video-bars · conference-speakerphones · webcams · interactive-displays
    smart-office/               smart-lighting · smart-plugs-switches · smart-climate · smart-locks
    cameras-imaging/            digital-cameras · camcorders · camera-accessories
    wearables/                  smart-watches · fitness-trackers · power-banks

/c/wpc-doors-interiors/
    wpc-doors/                  internal-doors · door-frames · flush-doors
    wpc-wardrobes/              fitted-wardrobes · sliding-wardrobes
    wpc-kitchens/               kitchen-cabinets · kitchen-islands
    wpc-interiors/              wall-panels · ceiling-panels
    wpc-boards/                 wpc-sheets · wpc-edging
```

**Totals:** 9 departments · 42 subcategories · 119 leaves = **170 categories**.

The tree was signed off at 124. Two departments were added on 2026-08-23, both
for lines of business the Phase 0 legacy crawl never surfaced because the
legacy site did not sell them online: **WPC Doors & Interiors** (a fit-out
business — see docs/BUSINESS_FACTS.md) and **Smart Tech**. Smart Tech is not
the reference site’s consumer version; its `power-backup/` branch is the local
addition that justifies the department, because nothing in a Nigerian office
runs without a UPS, an inverter or a stabiliser behind it.

Counts are asserted by `TaxonomySeeder`, which warns if this document and the seeded tree diverge.

## 4. Resolving the `storage` ambiguity

The legacy `storage` term is split three ways by buyer intent, and cross-linked rather than nested:

| Intent | Destination |
|---|---|
| Filing cabinets, cupboards, lockers, shelving | `/c/office-furniture/storage-furniture/` |
| Files, folders, binders, archive boxes | `/c/filing-storage/` |
| Flash drives, hard drives, memory cards | `/c/it-products/storage-memory/` |

Someone buying lever-arch files is not buying a cabinet. Nesting these together was a large part of why the legacy tree was unnavigable.

## 5. Brands

Brands identified from the legacy catalogue and taxonomy, promoted to `/brands/{slug}` hubs:

`hp` · `asus` · `lenovo` · `dell` · `canon` · `epson` · `brother` · `fellowes` · `lexar` ·
`ugreen` · `wiwu` · `baseus` · `mercury` · `iris` · `deli` · `fis` · `maped` · `targus` · `seagate` · `stabilo` · `zebra` · `post-it`

`wiwu-accessories` was an 18-term category branch in the legacy tree; it collapses entirely to a single brand hub plus correct type categorisation. `hp/*` collapses to the brand hub plus `/c/it-products/*` and `/c/printers-consumables/*` placements.

## 6. Crawl-depth arithmetic (§14.5)

At 15,000 products across 86 leaves, mean occupancy is **~175 products per leaf** — roughly 8 pages at 24 per page.

Reaching the 3-click requirement depends on the fix recorded in `DECISIONS.md` as **D-011**: every category page renders its full child-category list as real crawlable anchors, so the path is home → department (1) → leaf (2) → product (3). The megamenu itself stays at levels 1–2 to protect header weight per §23.1 rule 2.

**Watch item:** 175 products per leaf is high, and deep pagination is where discovery degrades. If a leaf exceeds ~300 products, split it. Reviewed at Phase 8.

## 7. Merchandising taxonomy ("Best for") — proposed, needs approval

Orthogonal to the category tree, applied as a curated facet and the basis for the indexable facet whitelist (§14.5 rule 4):

`reception` · `executive-office` · `open-plan-workstation` · `call-centre` ·
`conference-room` · `school-classroom` · `home-office`

These drive the homepage "Fit out your workspace" solution sets (§23.4 item 4) and are the ~30 curated combinations worth promoting to indexable landing pages. **Open question Q23 — awaiting client approval.**
