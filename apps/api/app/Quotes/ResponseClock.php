<?php

declare(strict_types=1);

namespace App\Quotes;

use App\Models\Quote;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * How long a lead has been waiting, in WORKING hours (§11c).
 *
 * ── Why not just `diffInHours` ───────────────────────────────────────────
 * §11c asks to highlight anything unanswered beyond four working hours. A
 * naive elapsed-time subtraction makes every quote submitted on Friday evening
 * look catastrophically late by Monday morning, and a queue where most rows
 * are red is a queue nobody reads. Worse, it hides the one that genuinely IS
 * late: a Monday-morning lead untouched since 9am.
 *
 * So the clock only runs during business hours. A quote submitted at 16:30 on
 * Friday and answered at 09:30 on Monday took one working hour, which is the
 * honest number and the one the promise is measured against.
 *
 * ── Lagos, explicitly ────────────────────────────────────────────────────
 * The timezone is configured rather than inherited from the server, because a
 * server that moves to a different region must not silently change what "four
 * working hours" means. The business, its customers and its promise are all in
 * West Africa Time.
 *
 * ── Public holidays are NOT modelled ─────────────────────────────────────
 * Nigerian public holidays are partly declared at short notice, so a hardcoded
 * list would be wrong within a year and wrong in the direction that matters -
 * flagging a lead as late when the office was shut. Weekends are handled; a
 * holiday shows as one day of overrun, which a salesperson can read correctly.
 * If this ever needs to be exact, it needs a maintained holiday table, not a
 * constant in a class.
 */
final class ResponseClock
{
    /** The promise made on every page and in the announcement bar. */
    public const TARGET_WORKING_HOURS = 4;

    /** 08:00–17:00, Monday to Friday. */
    private const DAY_START_HOUR = 8;

    private const DAY_END_HOUR = 17;

    /**
     * Working hours elapsed between two instants.
     *
     * Returns a float so a 30-minute response reads as 0.5 rather than
     * rounding to zero and looking like it never happened.
     */
    public function workingHoursBetween(CarbonInterface $from, CarbonInterface $to): float
    {
        $timezone = $this->timezone();

        $start = Carbon::instance($from->toDateTime())->setTimezone($timezone);
        $end = Carbon::instance($to->toDateTime())->setTimezone($timezone);

        if ($end->lessThanOrEqualTo($start)) {
            return 0.0;
        }

        $minutes = 0;
        $cursor = $start->copy();

        /*
         * Walked a day at a time rather than computed in closed form. The
         * arithmetic for partial first and last days, weekends in between and
         * submissions outside business hours is where a closed-form version
         * gets subtly wrong, and this runs on a queue of tens of rows.
         */
        while ($cursor->lessThan($end)) {
            $dayEnd = $cursor->copy()->setTime(self::DAY_END_HOUR, 0);
            $dayStart = $cursor->copy()->setTime(self::DAY_START_HOUR, 0);

            if ($this->isWorkingDay($cursor)) {
                $windowStart = $cursor->greaterThan($dayStart) ? $cursor->copy() : $dayStart;
                $windowEnd = $end->lessThan($dayEnd) ? $end->copy() : $dayEnd;

                if ($windowEnd->greaterThan($windowStart)) {
                    $minutes += $windowStart->diffInMinutes($windowEnd);
                }
            }

            // Next day at 00:00, so the loop cannot stall inside one day.
            $cursor = $cursor->copy()->addDay()->startOfDay();
        }

        return round($minutes / 60, 2);
    }

    /**
     * Working hours a quote has been waiting, or waited before its first reply.
     *
     * Once answered the clock stops: an answered lead is not getting later,
     * and a queue that keeps ageing closed rows is a queue that cannot be
     * triaged at a glance.
     */
    public function waitingHours(Quote $quote): ?float
    {
        $submitted = $quote->submitted_at ?? $quote->created_at;

        if ($submitted === null) {
            return null;
        }

        return $this->workingHoursBetween($submitted, $quote->first_response_at ?? now());
    }

    public function isOverdue(Quote $quote): bool
    {
        if ($quote->first_response_at !== null) {
            return false;
        }

        // Closed leads are not waiting on anyone, whatever the clock says.
        if (in_array($quote->status, ['won', 'lost', 'spam'], true)) {
            return false;
        }

        return ($this->waitingHours($quote) ?? 0.0) > self::TARGET_WORKING_HOURS;
    }

    /** "3.5 working hours", or "answered in 1.2 working hours". */
    public function describe(Quote $quote): string
    {
        $hours = $this->waitingHours($quote);

        if ($hours === null) {
            return 'unknown';
        }

        $label = $hours === 1.0 ? 'working hour' : 'working hours';

        return $quote->first_response_at !== null
            ? "answered in {$hours} {$label}"
            : "{$hours} {$label}";
    }

    private function isWorkingDay(CarbonInterface $day): bool
    {
        return ! $day->isWeekend();
    }

    private function timezone(): string
    {
        return (string) config('quotes.timezone', 'Africa/Lagos');
    }
}
