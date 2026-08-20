<?php

declare(strict_types=1);

namespace App\Search;

use App\Models\SearchQueryLog;
use Illuminate\Support\Facades\DB;

/**
 * Records every search and its result count (section 14.10).
 *
 * Upserts on the normalised query rather than inserting a row per search: the
 * useful question is "how many people asked for this", not "when did each of
 * them ask". One row per distinct query also keeps the zero-result report a
 * cheap query rather than an aggregation over millions of rows.
 */
final class SearchQueryLogger
{
    public function record(string $rawTerm, int $resultCount): void
    {
        $normalised = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $rawTerm) ?? ''));

        if ($normalised === '') {
            return;
        }

        // Logging must never break search. A failed insert here is worth a
        // lost data point, not a 500 on a buyer's search.
        try {
            DB::table('search_queries_log')->upsert(
                [[
                    'query_normalised' => $normalised,
                    'query_raw' => mb_substr(trim($rawTerm), 0, 255),
                    'result_count' => $resultCount,
                    'occurrences' => 1,
                    'first_seen_at' => now(),
                    'last_seen_at' => now(),
                ]],
                ['query_normalised'],
                [
                    'result_count' => DB::raw('excluded.result_count'),
                    'query_raw' => DB::raw('excluded.query_raw'),
                    'occurrences' => DB::raw('search_queries_log.occurrences + 1'),
                    'last_seen_at' => DB::raw('excluded.last_seen_at'),
                ],
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function recordClickthrough(string $rawTerm): void
    {
        $normalised = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $rawTerm) ?? ''));

        if ($normalised === '') {
            return;
        }

        SearchQueryLog::query()
            ->where('query_normalised', $normalised)
            ->increment('clickthroughs');
    }
}
