<?php

declare(strict_types=1);

namespace App\Search;

/**
 * One filter group and its selectable values, each with a live count.
 *
 * Counts are mandatory (section 23.2: "Facet counts are mandatory. They
 * prevent dead-end filtering"), and only values with count > 0 are ever
 * returned - which is how section 16's "facets must not offer a combination
 * that returns zero results" is actually satisfied rather than merely
 * intended.
 */
final readonly class FacetGroup
{
    /**
     * @param  list<array{value: string, label: string, count: int, selected: bool}>  $values
     */
    public function __construct(
        public string $code,
        public string $label,
        public array $values,
        public bool $indexable = false,
    ) {}

    public function isEmpty(): bool
    {
        return $this->values === [];
    }
}
