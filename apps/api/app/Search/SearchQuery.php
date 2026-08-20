<?php

declare(strict_types=1);

namespace App\Search;

/**
 * What was asked for. Immutable, and deliberately free of SQL.
 *
 * Keeping the request separate from the engine is what makes the
 * SearchEngine interface of section 16 meaningful — Meilisearch would consume
 * this same object without any caller changing.
 */
final readonly class SearchQuery
{
    /**
     * @param  array<string, list<string>>  $filters  attribute code => selected values
     */
    public function __construct(
        public string $term = '',
        public array $filters = [],
        public ?int $categoryId = null,
        public ?int $brandId = null,
        public int $page = 1,
        public int $perPage = 24,
        public string $sort = 'relevance',
    ) {}

    public function hasTerm(): bool
    {
        return trim($this->term) !== '';
    }

    public function normalisedTerm(): string
    {
        // Collapse whitespace so "hp   toner" and "hp toner" are one logged
        // query rather than two, and so the zero-result report groups properly.
        return trim(preg_replace('/\s+/u', ' ', $this->term) ?? '');
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    /** @param array<string, list<string>> $filters */
    public function withFilters(array $filters): self
    {
        return new self(
            $this->term, $filters, $this->categoryId, $this->brandId,
            $this->page, $this->perPage, $this->sort,
        );
    }
}
