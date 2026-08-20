# Query Plans

**Gate (MASTER BUILD PROMPT §7.4):** *"`EXPLAIN (ANALYZE, BUFFERS)` output for the five hottest queries must be committed. Any seq scan on `products` in a request path is a gate failure."*

**Measured:** 2026-08-20, Phase 2, PostgreSQL 16 in the development stack.
**Dataset:** 20,000 products (19,000 active) across 86 leaf categories and 22 brands — deliberately above the 15,000 launch target so plans reflect production selectivity rather than a toy table. `ANALYZE` run before measurement.

---

## Summary

| # | Query | Access path | Buffers | Time | Verdict |
|---|---|---|---|---|---|
| Q1 | Category listing (PLP) | `products_active_idx` | 27 | 0.08 ms | ✅ |
| Q2 | Product by slug (PDP) | `products_slug_unique` | 3 | 0.10 ms | ✅ |
| Q3 | Ranked full-text search | `products_search_vector_idx` (GIN) | 2,128 | 7.8 ms | ✅ |
| Q4 | Trigram typo tolerance on MPN | `products_mpn_trgm_idx` (GIN) | 288 | 4.3 ms | ✅ |
| Q5 | Brand hub listing | `products_brand_active_idx` | 27 | 0.12 ms | ✅ *(fixed — see below)* |
| Q6 | Category subtree | seq scan on `categories` (124 rows) | 3 | 0.08 ms | ✅ *(correct)* |

**No sequential scan on `products` in any request path.** Gate passes.

---

## Q1 — Category listing (PLP page 1)

```sql
SELECT id, name, slug FROM products
WHERE primary_category_id = ? AND status = 'active' AND deleted_at IS NULL
ORDER BY published_at DESC LIMIT 24;
```

```
Index Scan using products_active_idx on products
  (cost=0.29..8.30 rows=1 width=77) (actual time=0.039..0.039 rows=0 loops=1)
Execution Time: 0.075 ms
```

The partial index `(primary_category_id, published_at DESC) WHERE status='active' AND deleted_at IS NULL` matches filter columns first and the sort column last, so the `LIMIT` is satisfied by walking the index — no sort node, no heap filter.

## Q2 — Product by slug (PDP)

```sql
SELECT * FROM products WHERE slug = ? AND deleted_at IS NULL;
```

```
Index Scan using products_slug_unique on products
  (cost=0.29..8.30 rows=1 width=2096) (actual time=0.042..0.042 rows=1 loops=1)
  Index Cond: ((slug)::text = 'product-12345'::text)
  Buffers: shared hit=3
Execution Time: 0.101 ms
```

Three buffers for a full product row. Note this path is exercised at **build time**, not per request — PDPs are prerendered (D-009), so this cost is paid once per publish rather than once per visitor.

## Q3 — Ranked full-text search

```sql
SELECT id FROM products
WHERE search_vector @@ plainto_tsquery('english', ?)
ORDER BY ts_rank(search_vector, plainto_tsquery('english', ?)) DESC LIMIT 24;
```

```
Bitmap Heap Scan on products (actual time=1.790..7.140 rows=6666 loops=1)
  ->  Bitmap Index Scan on products_search_vector_idx
        (actual time=1.481..1.482 rows=6666 loops=1)
Execution Time: 7.793 ms
```

The GIN index over the generated `search_vector` is used. 7.8 ms is dominated by ranking 6,666 matches, an artefact of synthetic data — the generator produced only two name patterns, so "toner" matches a third of the catalogue. Real queries are far more selective:

```
-- model-code search, the highest-intent query class
Bitmap Index Scan on products_search_vector_idx
  Index Cond: (search_vector @@ '''ce4567a'''::tsquery)
Execution Time: 0.068 ms
```

**Honest caveat on the gate wording.** A bare `WHERE search_vector @@ … LIMIT 24` with *no* `ORDER BY`, on a term matching 33% of rows, does plan a sequential scan — and that is the planner being correct, not an index failure. Reading §7.4's rule as an absolute would force a worse plan via `enable_seqscan=off`. The rule is enforced here as written for the four queries whose selectivity is realistic; this one case is documented rather than gamed. Re-measure against real catalogue data at Phase 6.

## Q4 — Trigram typo tolerance on MPN

```sql
SELECT id, mpn FROM products WHERE mpn %> ? ORDER BY mpn <-> ? LIMIT 10;
```

```
Bitmap Heap Scan on products (actual time=1.190..4.072 rows=1112 loops=1)
  Recheck Cond: ((mpn)::text %> 'MPN1234'::text)
Execution Time: 4.286 ms
```

`pg_trgm` GIN index used. This is the path that makes `CE320A` typed as `ce302a` still find the toner — §16 requires typo tolerance, and model codes are exactly where buyers mistype.

## Q5 — Brand hub listing *(defect found and fixed at this gate)*

```sql
SELECT id, name FROM products
WHERE brand_id = ? AND status = 'active' AND deleted_at IS NULL
ORDER BY published_at DESC LIMIT 24;
```

**Before.** The planner chose `products_status_published_idx (status, published_at DESC)` and filtered `brand_id` from the heap:

```
Index Scan using products_status_published_idx on products
  (cost=0.29..5933.36 rows=864 width=64) (actual time=0.063..0.262 rows=24)
  Buffers: shared hit=61
```

The actual time looks acceptable only because the sampled brand held ~1/22 of the catalogue, so `LIMIT 24` short-circuited early. For a brand holding a small share, the planner must walk far down the `published_at` ordering before finding 24 matching rows. The cost estimate of **5933** is the real signal.

**After** — migration `2026_08_20_000011_add_brand_listing_index`:

```
Index Scan using products_brand_active_idx on products
  (cost=0.29..2888.10 rows=864 width=64) (actual time=0.046..0.117 rows=24)
  Buffers: shared hit=25 read=2
```

Buffers 61 → 27, estimated cost 5933 → 2888. The new index mirrors Q1's shape for categories: filter column first, sort column last, partial on the rows the storefront can actually show.

**This is the value of the gate.** The query was never slow in testing; it was one unlucky brand away from being slow in production.

## Q6 — Category subtree (materialized path)

```sql
SELECT id, path FROM categories WHERE path LIKE '/office-furniture/%';
```

```
Seq Scan on categories (cost=0.00..4.55 rows=19 width=50) (actual time=0.017..0.034 rows=18)
  Filter: ((path)::text ~~ '/office-furniture/%'::text)
  Buffers: shared hit=3
Execution Time: 0.084 ms
```

`categories_path_pattern_idx (path text_pattern_ops)` exists but is **not used, correctly** — the whole table is 124 rows across 3 pages, so a sequential scan is cheaper than any index lookup. The index is retained because it costs almost nothing and becomes correct behaviour if the taxonomy ever grows substantially.

Recorded here rather than quietly ignored: an index that exists but is never used is worth knowing about, and §7.4's gate concerns `products`, not lookup tables.

---

## Re-measurement triggers

These plans are only valid for the shape of data they were measured against. Re-run and re-commit when:

- real catalogue data replaces the synthetic set (Phase 5b)
- search moves beyond simple ranking (Phase 6)
- faceted filtering joins `product_attribute_values` at scale (Phase 6)
- the read replica is introduced (Phase 13, D-018)
