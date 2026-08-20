<?php

declare(strict_types=1);

namespace App\Search;

/**
 * Typeahead results, grouped as section 16 requires — products, categories
 * and brands are different kinds of answer and a flat list buries the one the
 * buyer wanted.
 */
final readonly class SuggestionSet
{
    /**
     * @param  list<array{label: string, url: string, meta: string|null}>  $products
     * @param  list<array{label: string, url: string, meta: string|null}>  $categories
     * @param  list<array{label: string, url: string, meta: string|null}>  $brands
     */
    public function __construct(
        public array $products = [],
        public array $categories = [],
        public array $brands = [],
        public float $durationMs = 0.0,
    ) {}

    public function isEmpty(): bool
    {
        return $this->products === [] && $this->categories === [] && $this->brands === [];
    }
}
