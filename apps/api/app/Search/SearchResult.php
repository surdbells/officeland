<?php

declare(strict_types=1);

namespace App\Search;

use Illuminate\Support\Collection;

/**
 * What came back, plus the facets that make sense for it.
 */
final readonly class SearchResult
{
    /**
     * @param  Collection<int, mixed>  $items
     * @param  list<FacetGroup>  $facets
     */
    public function __construct(
        public Collection $items,
        public int $total,
        public array $facets = [],
        public float $durationMs = 0.0,
        public bool $usedFallback = false,
    ) {}

    public function isEmpty(): bool
    {
        return $this->total === 0;
    }
}
