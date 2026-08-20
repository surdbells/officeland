<?php

declare(strict_types=1);

namespace App\Search;

use App\Models\Attribute;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
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

            // Trigram fallback catches typos and partial model codes — "ce302a"
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
            ->with(['brand', 'attributeValues.attribute'])
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
        $products = Product::query()
            ->active()
            ->where(function (Builder $q) use ($like, $contains): void {
                $q->whereRaw('lower(name) like ?', [$contains])
                    ->orWhereRaw('lower(mpn) like ?', [$like])
                    ->orWhereRaw('lower(sku) like ?', [$like]);
            })
            ->orderByRaw('case when lower(mpn) like ? then 0 when lower(name) like ? then 1 else 2 end', [$like, $like])
            ->limit($limit)
            ->get(['name', 'slug', 'mpn']);

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
            ->with(['brand', 'attributeValues.attribute'])
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
                // tsquery syntax error — the words must be joined with the
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
                $builder->whereHas('categories', fn (Builder $q) => $q->whereIn('categories.id', $ids));
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
     * after choosing one — counting with all filters applied would show every
     * unselected value as zero, and counting with none would offer dead ends.
     *
     * @return list<FacetGroup>
     */
    private function facets(SearchQuery $query): array
    {
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

    private function elapsed(int $start): float
    {
        return round((hrtime(true) - $start) / 1e6, 2);
    }
}
