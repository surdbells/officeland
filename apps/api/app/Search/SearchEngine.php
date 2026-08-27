<?php

declare(strict_types=1);

namespace App\Search;

/**
 * Section 16: "All of this sits behind a SearchEngine interface with a
 * PostgresSearchEngine implementation, so Meilisearch/Typesense can be
 * introduced later without touching callers."
 *
 * The interface is therefore free of anything Postgres-specific - no tsquery,
 * no SQL, no Eloquent builders leaking outward.
 */
interface SearchEngine
{
    public function search(SearchQuery $query): SearchResult;

    /**
     * Typeahead. Separate from search() because it has a different contract:
     * a hard latency budget (p95 <= 50ms, section 16), grouped results, and no
     * facets or pagination.
     */
    public function suggest(string $term, int $limit = 10): SuggestionSet;
}
