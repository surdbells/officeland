<?php

declare(strict_types=1);

namespace App\Http\Concerns;

use App\Search\FacetGroup;
use App\Search\SearchQuery;
use App\Search\SearchResult;
use Illuminate\Http\Request;

/**
 * The listing envelope shared by the PLP, search results and brand hubs.
 *
 * These three surfaces render with one card component (§23.5), so they must
 * answer with one payload shape. Written once here because the alternative —
 * three controllers each assembling `meta` and `facets` by hand — drifts the
 * first time a field is added to one of them, and the storefront cannot tell
 * which surface it is on.
 */
trait BuildsListingResponses
{
    /** Section 23.2's grid size. Capped so a caller cannot request the catalogue. */
    private function perPage(Request $request): int
    {
        return min(max($request->integer('per_page', 24), 1), 50);
    }

    /**
     * Attribute filters from the query string.
     *
     * Accepts `filters[capacity]=480&filters[colour]=black` and the
     * comma-delimited `filters[colour]=black,grey`. Values are strings because
     * they are compared against `value_raw`, which is text for every data
     * type — a numeric attribute still filters on its raw representation.
     *
     * @return array<string, list<string>>
     */
    private function filters(Request $request): array
    {
        $raw = $request->query('filters');

        if (! is_array($raw)) {
            return [];
        }

        $filters = [];

        foreach ($raw as $code => $values) {
            if (! is_string($code)) {
                continue;
            }

            $list = is_array($values) ? $values : explode(',', (string) $values);

            $clean = array_values(array_filter(
                array_map('strval', $list),
                static fn (string $value): bool => $value !== '',
            ));

            if ($clean !== []) {
                $filters[$code] = $clean;
            }
        }

        return $filters;
    }

    /**
     * @return array<string, mixed>
     */
    private function listingMeta(SearchQuery $query, SearchResult $result): array
    {
        $lastPage = max(1, (int) ceil($result->total / max(1, $query->perPage)));

        return [
            'total' => $result->total,
            'page' => $query->page,
            'per_page' => $query->perPage,

            /*
             * The storefront renders numbered pagination as real anchors, and
             * it cannot know how many to render without this. Computing it
             * client-side from total and per_page would put the arithmetic in
             * two places and produce an off-by-one on an exact multiple.
             */
            'last_page' => $lastPage,
            'has_more' => $query->page < $lastPage,

            'sort' => $query->sort,
            'duration_ms' => $result->durationMs,

            // Surfaced so the storefront can say "showing results for
            // something similar" rather than pretending it was exact.
            'used_fallback' => $result->usedFallback,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function facetPayload(SearchResult $result): array
    {
        return array_map(static fn (FacetGroup $group): array => [
            'code' => $group->code,
            'label' => $group->label,
            // Only an indexable facet may ever become a landing page, and only
            // by an editorial decision. The storefront needs to know which,
            // because everything else is noindex.
            'indexable' => $group->indexable,
            'values' => $group->values,
        ], $result->facets);
    }
}
