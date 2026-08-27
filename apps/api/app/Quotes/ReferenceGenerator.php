<?php

declare(strict_types=1);

namespace App\Quotes;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Issues the customer-facing quote reference, `OL-Q-YYYY-NNNNNN` (§9f).
 *
 * This is the string a buyer quotes on the phone and a salesperson searches
 * for, so it is short, unambiguous when read aloud, and carries the year.
 *
 * ── Gapless, and why that needs a lock ───────────────────────────────────
 * `SELECT ... FOR UPDATE` on the year's counter row, inside the caller's
 * transaction. The number is consumed only if that transaction commits, so a
 * failed submission releases it rather than burning it.
 *
 * A sequence cannot do this. `nextval()` is deliberately non-transactional -
 * that is what makes it lock-free - so a rollback leaves a permanent gap. See
 * the migration for the full reasoning.
 *
 * ── This MUST be called inside a transaction ─────────────────────────────
 * Outside one, `FOR UPDATE` locks for the duration of the statement only, and
 * two concurrent callers can both read the same value. The guard below is not
 * defensive decoration: without a surrounding transaction this silently
 * produces duplicate references, and the unique index turns that into a failed
 * submission for a real customer.
 */
final class ReferenceGenerator
{
    private const PREFIX = 'OL-Q';

    /** Six digits carries a million quotes a year; the format is not the limit. */
    private const DIGITS = 6;

    public function next(?int $year = null): string
    {
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException(
                'ReferenceGenerator::next() must run inside a transaction, or the counter lock cannot hold.'
            );
        }

        $year ??= (int) now()->format('Y');

        /*
         * Create the year's row if this is the first quote of the year, then
         * lock it. `insertOrIgnore` rather than firstOrCreate: two callers
         * racing on the first quote of the year would otherwise both try to
         * insert and one would fail on the primary key.
         */
        DB::table('quote_reference_counters')->insertOrIgnore([
            'year' => $year,
            'last_number' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('quote_reference_counters')
            ->where('year', $year)
            ->lockForUpdate()
            ->first();

        if ($row === null) {
            throw new RuntimeException("Could not lock the quote counter for {$year}.");
        }

        $number = (int) $row->last_number + 1;

        DB::table('quote_reference_counters')
            ->where('year', $year)
            ->update(['last_number' => $number, 'updated_at' => now()]);

        return sprintf('%s-%d-%0'.self::DIGITS.'d', self::PREFIX, $year, $number);
    }
}
