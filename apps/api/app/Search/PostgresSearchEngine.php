<?php

declare(strict_types=1);

namespace App\Search;

use App\Models\Attribute;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * PostgreSQL full-text search (section 16).
 *
 * Ranking follows section 16 exactly, and the order is the whole point:
 *
 *   1. an exact SKU or MPN match wins OUTRIGHT
 *   2. then name matches
 *   3. then everything else by ts_rank, boosted by commercial signals
 *
 * Rule 1 is not a tie-break, it is a short-circuit. A buyer typing "CE320A"
 * has told you exactly which product they want; showing them a relevance-
 * ranked list with it third is a worse answer than a single row.
 *
 * unaccent() is applied at QUERY time rather than in the stored vector,
 * because it is not IMMUTABLE and PostgreSQL rejects non-immutable functions
 * in a generated column (see the Phase 2 migration).
 */
final class PostgresSearchEngine implements SearchEngine
{
    /** Commercial boosts, applied on top of textual relevance. */
    private const BOOST_IN_STOCK = 0.30;

    private const BOOST_INDEXABLE = 0.10;

    /**
     * Below this trigram similarity a "match" is noise. Postgres defaults to
     * 0.3; model codes are short, so a slightly higher floor avoids returning
     * half the catalogue for a three-character typo.
     */
    private const TRIGRAM_THRESHOLD = 0.35;

    /**
     * A cache generation counter. The facet payload for a broad listing is
     * cached under a key that embeds this number; `catalogue:curate-facets`
     * increments it to invalidate every cached payload at once when the set of
     * facets changes. See facets().
     */
    public const FACET_CACHE_GENERATION_KEY = 'facets:generation';

    public function __construct(private readonly SynonymExpander $synonyms) {}

    public function search(SearchQuery $query): SearchResult
    {
        $start = hrtime(true);

        // An exact identifier match short-circuits everything else.
        if ($query->hasTerm()) {
            $exact = $this->exactIdentifierMatch($query->normalisedTerm());

            if ($exact !== null) {
                return new SearchResult(
                    items: collect([$exact]),
                    total: 1,
                    facets: [],
                    durationMs: $this->elapsed($start),
                );
            }
        }

        $base = $this->baseQuery($query);
        $usedFallback = false;

        if ($query->hasTerm()) {
            // Declared before the call: it is filled by reference, and an
            // undefined variable would arrive as null and fail the array type.
            $bindings = [];

            $matched = (clone $base)->whereRaw(
                'search_vector @@ '.$this->tsQueryExpression($query->normalisedTerm(), $bindings),
                $bindings,
            );

            // Trigram fallback catches typos and partial model codes - "ce302a"
            // for "CE320A". Only consulted when FTS finds nothing, because it
            // is looser and would otherwise dilute good results.
            if (! $matched->exists()) {
                $matched = $this->trigramFallback($base, $query->normalisedTerm());
                $usedFallback = true;
            }

            $base = $matched;
        }

        $total = (clone $base)->count();

        $items = $this->applySort(clone $base, $query)
            ->with(['brand', 'attributeValues.attribute', 'media.derivatives'])
            ->skip($query->offset())
            ->take($query->perPage)
            ->get();

        return new SearchResult(
            items: $items,
            total: $total,
            facets: $this->facets($query),
            durationMs: $this->elapsed($start),
            usedFallback: $usedFallback,
        );
    }

    public function suggest(string $term, int $limit = 10): SuggestionSet
    {
        $start = hrtime(true);
        $term = trim($term);

        if (mb_strlen($term) < 2) {
            return new SuggestionSet(durationMs: $this->elapsed($start));
        }

        $like = mb_strtolower($term).'%';
        $contains = '%'.mb_strtolower($term).'%';

        // Deliberately narrow: prefix and contains matching on indexed columns
        // only, no ranking, no joins. The budget is p95 <= 50ms and this runs
        // on every keystroke.
        // Over-fetch, because identical product names are collapsed below. The
        // imported catalogue carries families of same-named rows (e.g. 94 "New
        // office chairs collection"); without de-duplication the typeahead is
        // ten identical lines that all point somewhere different. A wider limit
        // leaves enough distinct names after the collapse.
        $products = Product::query()
            ->active()
            ->where(function (Builder $q) use ($like, $contains): void {
                $q->whereRaw('lower(name) like ?', [$contains])
                    ->orWhereRaw('lower(mpn) like ?', [$like])
                    ->orWhereRaw('lower(sku) like ?', [$like]);
            })
            ->orderByRaw('case when lower(mpn) like ? then 0 when lower(name) like ? then 1 else 2 end', [$like, $like])
            ->limit($limit * 5)
            ->get(['name', 'slug', 'mpn']);

        // One suggestion per distinct name (first wins, so ordering is kept),
        // then back down to the requested limit.
        $products = $products
            ->unique(fn (Product $p): string => mb_strtolower((string) $p->name))
            ->take($limit)
            ->values();

        $categories = Category::query()
            ->whereRaw('lower(name) like ?', [$contains])
            ->limit(4)
            ->get(['name', 'path']);

        $brands = Brand::query()
            ->whereRaw('lower(name) like ?', [$contains])
            ->limit(4)
            ->get(['name', 'slug']);

        return new SuggestionSet(
            products: array_values($products->map(fn (Product $p): array => [
                'label' => $p->name,
                'url' => $p->url(),
                'meta' => $p->mpn,
            ])->all()),
            categories: array_values($categories->map(fn (Category $c): array => [
                'label' => $c->name,
                'url' => $c->url(),
                'meta' => null,
            ])->all()),
            brands: array_values($brands->map(fn (Brand $b): array => [
                'label' => (string) $b->name,
                'url' => $b->url(),
                'meta' => null,
            ])->all()),
            durationMs: $this->elapsed($start),
        );
    }

    /**
     * An exact SKU or MPN match, case-insensitive.
     *
     * Only returns when EXACTLY ONE product matches. Two products sharing an
     * MPN across brands is a data problem, and silently picking one of them
     * would hide it.
     */
    private function exactIdentifierMatch(string $term): ?Product
    {
        $normalised = mb_strtoupper(preg_replace('/[\s-]+/', '', $term) ?? $term);

        if (mb_strlen($normalised) < 3) {
            return null;
        }

        $matches = Product::query()
            ->active()
            ->whereRaw("upper(replace(replace(coalesce(sku,''), ' ', ''), '-', '')) = ?", [$normalised])
            ->orWhereRaw("upper(replace(replace(coalesce(mpn,''), ' ', ''), '-', '')) = ?", [$normalised])
            ->with(['brand', 'attributeValues.attribute', 'media.derivatives'])
            ->limit(2)
            ->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /**
     * Builds a tsquery from the synonym-expanded term.
     *
     * Tokens are ANDed, synonyms within a token are ORed: "hp toner" requires
     * both, but "toner" also matches "cartridge". Doing it the other way round
     * makes every multi-word search return the whole catalogue.
     *
     * @param  list<string>  $bindings
     */
    private function tsQueryExpression(string $term, array &$bindings = []): string
    {
        $bindings = [];
        $groups = [];

        foreach ($this->synonyms->expand($term) as $group) {
            $alternatives = [];

            foreach ($group as $token) {
                // A multi-word synonym such as "flash drive" is a PHRASE.
                // Interpolated raw it produces `flash drive:*`, which is a
                // tsquery syntax error - the words must be joined with the
                // phrase operator so they match adjacently.
                $words = preg_split('/[^\p{L}\p{N}]+/u', $token, -1, PREG_SPLIT_NO_EMPTY) ?: [];

                if ($words === []) {
                    continue;
                }

                $parts = [];

                foreach ($words as $word) {
                    $bindings[] = $word;
                    // Prefix matching so "ton" matches "toner" as they type.
                    $parts[] = "unaccent(?) || ':*'";
                }

                $alternatives[] = count($parts) === 1
                    ? $parts[0]
                    : implode(" || ' <-> ' || ", $parts);
            }

            $groups[] = '('.implode(" || ' | ' || ", $alternatives).')';
        }

        if ($groups === []) {
            $bindings[] = '';

            return "to_tsquery('english', ?)";
        }

        return "to_tsquery('english', ".implode(" || ' & ' || ", $groups).')';
    }

    /**
     * @param  Builder<Product>  $base
     * @return Builder<Product>
     */
    private function trigramFallback(Builder $base, string $term): Builder
    {
        $candidates = $this->synonyms->flatten($term);

        return $base->where(function (Builder $q) use ($candidates): void {
            foreach ($candidates as $candidate) {
                // word_similarity, not similarity: "ergonomik" against
                // "Ergonomic Mesh Office Chair" scores low across the whole
                // string but high against the single word it mistypes.
                $q->orWhereRaw('word_similarity(?, name) > ?', [$candidate, self::TRIGRAM_THRESHOLD])
                    ->orWhereRaw("similarity(coalesce(mpn,''), ?) > ?", [$candidate, self::TRIGRAM_THRESHOLD])
                    ->orWhereRaw("similarity(coalesce(sku,''), ?) > ?", [$candidate, self::TRIGRAM_THRESHOLD]);
            }
        });
    }

    /** @return Builder<Product> */
    private function baseQuery(SearchQuery $query): Builder
    {
        $builder = Product::query()->active();

        if ($query->categoryId !== null) {
            $category = Category::find($query->categoryId);

            if ($category instanceof Category) {
                // A department listing includes its whole subtree.
                $ids = Category::query()->subtreeOf($category->path)->pluck('id');

                // Filter on the product's primary category rather than pivot
                // membership. The pivot form - whereHas('categories', ...) with
                // ORDER BY + LIMIT - has no supporting index, so for a narrow
                // category at catalogue scale the planner scans a long way to
                // collect a page (the count() and items() each ~15s, together
                // past the 30s request limit - the homepage fires one per
                // department rail). This uses products_active_idx
                // (primary_category_id, published_at DESC) WHERE status='active'.
                // Products carry a single category here, so primary == membership
                // for all but rare cross-listings, which show on their own
                // department's page.
                $builder->whereIn('primary_category_id', $ids);
            }
        }

        if ($query->brandId !== null) {
            $builder->where('brand_id', $query->brandId);
        }

        foreach ($query->filters as $code => $values) {
            if ($values === []) {
                continue;
            }

            // Each attribute filter is its own EXISTS: a product matching
            // "black" on colour AND "2000" on yield must satisfy both, which a
            // single joined IN() would not express.
            $builder->whereHas(
                'attributeValues',
                fn (Builder $q) => $q
                    ->whereIn('value_raw', $values)
                    ->whereHas('attribute', fn (Builder $a) => $a->where('code', $code)),
            );
        }

        return $builder;
    }

    /**
     * @param  Builder<Product>  $builder
     * @return Builder<Product>
     */
    private function applySort(Builder $builder, SearchQuery $query): Builder
    {
        return match ($query->sort) {
            'newest' => $builder->orderByDesc('published_at'),
            'name' => $builder->orderBy('name'),
            default => $this->applyRelevance($builder, $query),
        };
    }

    /**
     * @param  Builder<Product>  $builder
     * @return Builder<Product>
     */
    private function applyRelevance(Builder $builder, SearchQuery $query): Builder
    {
        if (! $query->hasTerm()) {
            return $builder->orderByDesc('published_at');
        }

        $term = $query->normalisedTerm();
        $bindings = [];
        $expression = $this->tsQueryExpression($term, $bindings);

        // Textual relevance, then commercial signals. Section 16: "boost
        // in-stock, priced and recently-quoted products". Pricing is absent
        // under D-002, so stock and indexability carry it for now.
        // The boosts are inlined rather than bound. PostgreSQL infers the
        // type of a `then ?` from its `else` branch, so `else 0` made it try
        // to read 0.30 as an integer and the whole query failed with
        // SQLSTATE[22P02]. They are class constants, not user input, so there
        // is nothing to parameterise.
        $inStock = sprintf('%.2f', self::BOOST_IN_STOCK);
        $indexable = sprintf('%.2f', self::BOOST_INDEXABLE);

        return $builder->orderByRaw(
            "ts_rank(search_vector, {$expression})
             + case when availability = 'in_stock' then {$inStock}::real else 0.0 end
             + case when is_indexable then {$indexable}::real else 0.0 end DESC",
            $bindings,
        );
    }

    /**
     * Facets with live counts.
     *
     * Each group is counted with every OTHER active filter applied but not its
     * own. That is what lets a buyer see the other colours still available
     * after choosing one - counting with all filters applied would show every
     * unselected value as zero, and counting with none would offer dead ends.
     *
     * The computation is one aggregate per filterable attribute over the scoped
     * set, so an unfiltered listing (homepage, a category rail, a brand hub) is
     * the heaviest case AND the most repeated. Those - the ones with no
     * attribute filter - are cached under a generation-stamped key, keyed only
     * by category and brand so the key space stays bounded (Redis runs
     * noeviction here). A listing that DOES carry attribute filters is scoped
     * small and has an unbounded key space, so it is computed live.
     * `catalogue:curate-facets` bumps the generation to invalidate the cache.
     *
     * @return list<FacetGroup>
     */
    private function facets(SearchQuery $query): array
    {
        // A filtered listing is scoped small, so its facets are computed live.
        if ($query->filters !== []) {
            return $this->computeFacets($query);
        }

        // The broad cases (homepage, a category, a brand) are counted over the
        // whole active catalogue and are far too expensive for the request path -
        // computing them here is what repeatedly 500'd the PLP. They are
        // PRE-COMPUTED into the cache by catalogue:warm-facets (off the request
        // path, with no web time limit); the request only READS. A cold key shows
        // no sidebar rather than a timeout, and the warmer fills it.
        return Cache::get($this->facetCacheKey($query)) ?? [];
    }

    private function facetCacheKey(SearchQuery $query): string
    {
        $generation = (int) Cache::get(self::FACET_CACHE_GENERATION_KEY, 0);

        return 'facets:'.$generation.':'.md5(serialize([$query->categoryId, $query->brandId]));
    }

    /**
     * Pre-compute a broad listing's facets off the request path and store them in
     * the cache the request reads. Runs on the direct connection with the web
     * timeout lifted, so even a whole-catalogue aggregate completes. Returns the
     * number of facet values warmed. Driven by catalogue:warm-facets.
     */
    public function warmFacets(SearchQuery $query): int
    {
        $groups = $this->computeFacetsUnfiltered($query, 'pgsql_direct');
        // Two days, refreshed daily by the scheduler: a single missed run still
        // leaves the sidebar populated rather than lapsing to empty.
        Cache::put($this->facetCacheKey($query), $groups, now()->addDays(2));

        return array_sum(array_map(static fn (FacetGroup $g): int => count($g->values), $groups));
    }

    /**
     * The live facet computation behind facets(), one aggregate per filterable
     * attribute. Kept separate so facets() can cache the broad cases.
     *
     * @return list<FacetGroup>
     */
    private function computeFacets(SearchQuery $query): array
    {
        // Only the FILTERED path reaches here - the broad, unfiltered cases are
        // served from the warmed cache (see facets()). Each facet is counted with
        // the OTHER selected filters applied but not its own, so the scopes
        // genuinely differ - but a filtered set is small, so this is cheap.
        $filterable = Attribute::query()
            ->filterable()
            ->orderByDesc('display_order')
            ->get();

        $groups = [];

        foreach ($filterable as $attribute) {
            $others = $query->filters;
            unset($others[$attribute->code]);

            $scoped = $this->baseQuery($query->withFilters($others));

            $rows = DB::table('product_attribute_values as pav')
                ->join('attributes as a', 'a.id', '=', 'pav.attribute_id')
                ->where('a.code', $attribute->code)
                ->whereIn('pav.product_id', $scoped->select('products.id'))
                ->groupBy('pav.value_raw', 'pav.value_presentation')
                ->orderByDesc(DB::raw('count(*)'))
                ->limit(50)
                ->get([
                    'pav.value_raw',
                    DB::raw('max(pav.value_presentation) as value_presentation'),
                    DB::raw('count(distinct pav.product_id) as total'),
                ]);

            $selected = $query->filters[$attribute->code] ?? [];

            $values = $rows
                // A zero count cannot occur here (grouping only sees rows that
                // exist), which is precisely why counting this way satisfies
                // "facets must not offer a zero-result combination".
                ->map(fn ($row): array => [
                    'value' => (string) $row->value_raw,
                    'label' => (string) ($row->value_presentation ?: $row->value_raw),
                    'count' => (int) $row->total,
                    'selected' => in_array((string) $row->value_raw, $selected, true),
                ])
                ->values()
                ->all();

            $values = array_values($values);

            if ($values === []) {
                continue;
            }

            $groups[] = new FacetGroup(
                code: $attribute->code,
                label: $attribute->name,
                values: $values,
                indexable: $attribute->is_indexable_facet,
            );
        }

        return $groups;
    }

    /**
     * Every facet for a listing with no attribute filter selected, in one
     * aggregate. One scan of the spec rows for filterable attributes, scoped to
     * the active set once, grouped by attribute and value - instead of a
     * separate query per attribute. Curated facets are low-cardinality, so the
     * result is at most a few hundred rows however large the catalogue.
     *
     * @return list<FacetGroup>
     */
    private function computeFacetsUnfiltered(SearchQuery $query, ?string $connection = null): array
    {
        $db = DB::connection($connection);

        if ($connection !== null) {
            // Off the request path: lift the role's web statement_timeout so the
            // aggregate is allowed to finish. unprepared() runs the SET as a
            // simple query so it actually applies to the session.
            $db->unprepared("SET statement_timeout = '600s'");
        }

        // The active product scope, built on the SAME connection as the aggregate
        // so the subquery is not cross-connection. Category filters on
        // primary_category_id (its own department), as the listing query does.
        $scope = $db->table('products')
            ->where('status', 'active')
            ->whereNull('deleted_at');

        if ($query->brandId !== null) {
            $scope->where('brand_id', $query->brandId);
        }

        if ($query->categoryId !== null) {
            $category = Category::find($query->categoryId);
            if ($category instanceof Category) {
                $ids = Category::query()->subtreeOf($category->path)->pluck('id')->all();
                $scope->whereIn('primary_category_id', $ids);
            }
        }

        $rows = $db->table('product_attribute_values as pav')
            ->join('attributes as a', 'a.id', '=', 'pav.attribute_id')
            ->where('a.is_filterable', true)
            ->whereIn('pav.product_id', $scope->select('id'))
            ->groupBy('a.code', 'a.name', 'a.is_indexable_facet', 'a.display_order', 'pav.value_raw')
            ->get([
                'a.code',
                'a.name',
                'a.is_indexable_facet',
                'a.display_order',
                'pav.value_raw',
                DB::raw('max(pav.value_presentation) as value_presentation'),
                DB::raw('count(distinct pav.product_id) as total'),
            ]);

        return $this->foldFacetRows($rows);
    }

    /**
     * Fold the flat (attribute, value, count) rows of the one-pass aggregate into
     * one FacetGroup per attribute, highest display_order first and each group's
     * most-common value first, capped at 50.
     *
     * @param  Collection<int, \stdClass>  $rows
     * @return list<FacetGroup>
     */
    private function foldFacetRows($rows): array
    {
        $byCode = [];
        foreach ($rows as $row) {
            $code = (string) $row->code;
            $byCode[$code]['order'] ??= (int) $row->display_order;
            $byCode[$code]['name'] ??= (string) $row->name;
            $byCode[$code]['indexable'] ??= (bool) $row->is_indexable_facet;
            $byCode[$code]['values'][] = [
                'value' => (string) $row->value_raw,
                'label' => (string) ($row->value_presentation ?: $row->value_raw),
                'count' => (int) $row->total,
                'selected' => false,
            ];
        }

        uasort($byCode, static fn (array $a, array $b): int => $b['order'] <=> $a['order']);

        $groups = [];
        foreach ($byCode as $code => $data) {
            $values = $data['values'];
            usort($values, static fn (array $x, array $y): int => $y['count'] <=> $x['count']);
            $values = array_slice($values, 0, 50);

            if ($values === []) {
                continue;
            }

            $groups[] = new FacetGroup(
                code: (string) $code,
                label: $data['name'],
                values: array_values($values),
                indexable: $data['indexable'],
            );
        }

        return $groups;
    }

    private function elapsed(int $start): float
    {
        return round((hrtime(true) - $start) / 1e6, 2);
    }
}
