# Icecat → OfficeLand data mapping

**Status:** Phase 0k deliverable. Profiled from the Open/Full Icecat XML manual, 2026-08-20.
**Tier:** Open Icecat only (D-022). `access_level = freexml`.

This document exists so the Phase 2 schema absorbs Icecat's real shape rather than a guess. Three findings below changed the data model.

---

## 1. Findings that changed the schema

### F-1 — Specifications carry *two* values, and both must be stored

Icecat is explicit:

> Use the `Presentation_Value` parameter in localized data-sheets for display of a data-sheet, and use the `Value` parameter for search and compare.

A single `value` column would force a choice between a spec table that displays correctly and facets that filter correctly. `product_attribute_values` therefore stores:

| Column | Source | Used for |
|---|---|---|
| `value_raw` | `ProductFeature@Value` | search, compare, exact-match facets |
| `value_presentation` | `ProductFeature@Presentation_Value` | the spec table shown to a buyer |
| `value_numeric` | extracted from `value_raw` | range facets (screen size, page yield, weight) |

### F-2 — Specifications are already grouped, and the groups are the PDP accordion

`CategoryFeatureGroup` → `FeatureGroup` gives Icecat's own grouping (OVERVIEW, PERFORMANCE, SCREEN, CONNECTIVITY…). §23.3 called the grouped specifications accordion *"the most important thing to copy"* from the reference site — it arrives with the data. Modelled as a first-class `attribute_groups` table rather than derived at render time.

`ProductFeature@No` is a buyer-orientation priority (higher = more important), which drives both `display_order` and the selection of the 4–6 key-spec bullets above the fold.

### F-3 — Product relationships are supplied, not hand-curated

`ProductRelated` carries typed relations: **Compatibles · Alternatives · Accessories · Consumables · Services**.

This is exactly §23.3's *"Complete your setup"* rail and the printer → compatible toner relationship, and §14.7's internal-linking requirement — data-driven from day one rather than manually maintained. Modelled as `product_relations` with a matching enum.

---

## 2. Endpoints

```
Export (by Icecat ID):
  https://data.icecat.biz/export/freexml/{lang}/{icecat_id}.xml

Search (by MPN + brand, or GTIN):
  https://data.icecat.biz/xml_s3/xml_server3.cgi
      ?lang={lang}&prod_id={mpn}&vendor={brand}&output=productxml
```

Auth: account credentials, plus IP whitelisting or dynamic tokens. **Non-whitelisted requests return 404, not 403** — worth knowing when debugging, since it looks like a missing product.

**Rate limit:** 100 concurrent connections per IP; Icecat recommends 50–75. We throttle to **50**, shared across product fetches and image mirroring.

**Incremental sync:** index files carry `Updated` (`YYYYMMDDHHMMSS`); `HEAD` returns `Last-Modified`. Sync compares against `icecat_synced_at` and fetches only what changed.

**Locale:** `en`, metric. `LocalValue` performs metric/imperial conversion by locale — Nigeria is metric, so an imperial locale would silently produce wrong specifications.

---

## 3. Field mapping

### Product identity

| Icecat | OfficeLand | Note |
|---|---|---|
| `Product@ID` | `products.icecat_id` | Icecat's own key; used for cheap re-fetch |
| `Product@Prod_id` | `products.mpn` | **Primary match key** (with brand) |
| `EANCode@EAN` | `products.ean` | Multiple possible, each with a `VariantID` |
| `Supplier@Name` | `brands.name` | Brand is a facet, never a URL path segment |
| `Category@ID` | — | **Not used as our category.** Mapped through our own taxonomy per TAXONOMY.md; Icecat's tree is not our IA. |

### Naming and copy — ingested as draft only (D-015)

| Icecat | OfficeLand | Policy |
|---|---|---|
| `Title` / `GeneratedLocalTitle` | `products.source_title` | Retained for reference; **never published**. `products.name` is rewritten. |
| `ShortDesc` / `LongDesc` | `products.source_description` | Draft. Rewritten before publish. |
| Bullet points / Reasons to buy | key-spec bullets, "Good to know" | Factual; reviewed, not republished verbatim |
| `WarrantyInfo` | `products.warranty_text` | Factual |

Rationale: every Icecat subscriber receives identical prose. Publishing it verbatim recreates legacy defect §4B/C at scale (SEO_SPEC.md §7). Facts are safe to duplicate; prose is not.

### Specifications

| Icecat | OfficeLand |
|---|---|
| `CategoryFeatureGroup` → `FeatureGroup@Name` | `attribute_groups.name` |
| `CategoryFeatureGroup@No` | `attribute_groups.display_order` |
| `Feature@ID` | `attributes.icecat_feature_id` |
| `Feature` → `Name` | `attributes.name` |
| `Measure` → `Signs` | `attributes.unit_symbol` |
| `ProductFeature@Value` | `product_attribute_values.value_raw` |
| `ProductFeature@Presentation_Value` | `product_attribute_values.value_presentation` |
| `ProductFeature@Searchable` | `attributes.is_filterable` |
| `ProductFeature@No` | `attributes.display_order` |

### Media

| Icecat | OfficeLand |
|---|---|
| `ProductGallery` → `ProductPicture` | `media` + `product_media` |
| `HighPic` (largest) | ingestion source for the derivative pipeline |

Icecat warns: *"Icecat may change links for pictures, they are not constant. Make sure you download the images."* Images are therefore **mirrored into the content-addressed store**, never hotlinked — which the §12 derivative pipeline and the CWV budgets require anyway.

### Relations and variants

| Icecat | OfficeLand |
|---|---|
| `ProductRelated` (Compatibles/Alternatives/Accessories/Consumables/Services) | `product_relations.relation_type` |
| `Variants` → `VariantIdentifiers` / `VariantFeatures` / `VariantImages` | `product_variants` |

---

## 4. Brand coverage

Open Icecat covers **600+ sponsoring brands**. Verified present: HP, ASUS, Lenovo, Canon, Dell, Epson, Brother.

**Baseus is not an Open Icecat sponsor.** It exists in the wider catalogue at 50% data health — community-sourced rather than brand-authorised — so it is unavailable at this tier and would be incomplete even at Full. **Baseus is populated via the CSV importer or manual entry** (D-022).

The same applies to any non-sponsoring brand OfficeLand stocks. `products.source` records provenance (`icecat` | `csv` | `manual`) so coverage gaps are queryable rather than invisible.
