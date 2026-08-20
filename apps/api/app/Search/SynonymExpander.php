<?php

declare(strict_types=1);

namespace App\Search;

use App\Models\SearchSynonym;
use Illuminate\Support\Facades\Cache;

/**
 * Expands a query using the admin-editable synonym table (section 16).
 *
 * Section 16 calls synonyms "essential for this catalogue and market", and
 * they cover two genuinely different problems:
 *
 *   Nigerian-English usage   biro/pen, flash drive/pendrive, photocopier/copier
 *   Model-code equivalence   CE320A / 128A / HP 128A
 *
 * The second is the commercially valuable one. A buyer who knows the cartridge
 * as "128A" and a buyer who reads "CE320A" off the old cartridge are looking
 * for the same product, and without this only one of them finds it.
 *
 * Stored in the database rather than compiled into a PostgreSQL dictionary so
 * merchandising staff can add a term without a deploy — the terms are learned
 * from the zero-result report, which is a weekly activity, not a release.
 */
final class SynonymExpander
{
    private const CACHE_KEY = 'search:synonyms:v1';

    private const CACHE_TTL = 3600;

    /**
     * Expands each token to itself plus its synonyms.
     *
     * @return list<list<string>> one group per input token
     */
    public function expand(string $term): array
    {
        $map = $this->map();
        $tokens = $this->tokenise($term);
        $groups = [];

        foreach ($tokens as $token) {
            $key = mb_strtolower($token);
            $group = [$token];

            foreach ($map[$key] ?? [] as $synonym) {
                $group[] = $synonym;
            }

            $groups[] = array_values(array_unique($group));
        }

        return $groups;
    }

    /**
     * The full expanded term set, flattened — used for trigram fallback where
     * token structure does not matter.
     *
     * @return list<string>
     */
    public function flatten(string $term): array
    {
        $flat = [];

        foreach ($this->expand($term) as $group) {
            foreach ($group as $value) {
                $flat[] = $value;
            }
        }

        return array_values(array_unique($flat));
    }

    /**
     * term => [synonyms]
     *
     * Cached because it is read on every search and changes rarely. Cleared by
     * the SearchSynonym model on save.
     *
     * @return array<string, list<string>>
     */
    public function map(): array
    {
        /** @var array<string, list<string>> $map */
        $map = Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function (): array {
            $map = [];

            foreach (SearchSynonym::query()->where('is_active', true)->get() as $row) {
                $term = mb_strtolower($row->term);
                $synonym = mb_strtolower($row->synonym);

                $map[$term][] = $synonym;

                // One-way entries exist for typo corrections: a misspelling
                // should find the correct term, but searching the correct term
                // must not drag in the misspelling's matches.
                if ($row->bidirectional) {
                    $map[$synonym][] = $term;
                }
            }

            return array_map(
                static fn (array $values): array => array_values(array_unique($values)),
                $map,
            );
        });

        return $map;
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return list<string>
     */
    private function tokenise(string $term): array
    {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', $term, -1, PREG_SPLIT_NO_EMPTY);

        return $parts === false ? [] : $parts;
    }
}
