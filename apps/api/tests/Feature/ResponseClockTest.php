<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Quote;
use App\Quotes\ResponseClock;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The SLA clock (§11c).
 *
 * The arithmetic is the whole feature. If it is wrong the queue is either
 * permanently red — in which case nobody reads it, and the genuinely late lead
 * is invisible — or permanently green, in which case the four-hour promise on
 * every page is not measured by anything.
 *
 * Everything here is a pure calculation, so it runs without touching the
 * database except where a Quote is needed for the overdue rules.
 */
final class ResponseClockTest extends TestCase
{
    private function clock(): ResponseClock
    {
        config()->set('quotes.timezone', 'Africa/Lagos');

        return new ResponseClock;
    }

    private function lagos(string $expression): Carbon
    {
        return Carbon::parse($expression, 'Africa/Lagos');
    }

    // -----------------------------------------------------------------
    // Working-hours arithmetic
    // -----------------------------------------------------------------

    /**
     * @return array<string, array{string, string, float}>
     */
    public static function intervals(): array
    {
        return [
            'inside one working day' => ['2026-08-19 09:00', '2026-08-19 12:30', 3.5],

            'a whole working day' => ['2026-08-19 08:00', '2026-08-19 17:00', 9.0],

            // The overnight gap must not count. 16:00 Wednesday to 09:00
            // Thursday is one hour of Wednesday plus one of Thursday.
            'across a night' => ['2026-08-19 16:00', '2026-08-20 09:00', 2.0],

            // The case that makes elapsed time useless: Friday evening to
            // Monday morning is one working hour, not sixty-five.
            'across a weekend' => ['2026-08-21 16:30', '2026-08-24 09:30', 2.0],

            // Submitted at 22:00 on a Friday: the clock has not started, so
            // Monday 08:30 is half an hour.
            'submitted out of hours' => ['2026-08-21 22:00', '2026-08-24 08:30', 0.5],

            // Submitted on a Saturday. Nothing counts until Monday opens.
            'submitted at the weekend' => ['2026-08-22 11:00', '2026-08-24 10:00', 2.0],

            // Answered after close: the clock stopped at 17:00.
            'answered out of hours' => ['2026-08-19 16:00', '2026-08-19 23:00', 1.0],

            // A full working week, Monday 08:00 to Friday 17:00.
            'a whole week' => ['2026-08-17 08:00', '2026-08-21 17:00', 45.0],

            // Half an hour must survive as 0.5, not round to zero and read as
            // though the reply never happened.
            'sub-hour' => ['2026-08-19 09:00', '2026-08-19 09:30', 0.5],

            // Time cannot run backwards.
            'end before start' => ['2026-08-19 12:00', '2026-08-19 09:00', 0.0],

            'identical instants' => ['2026-08-19 09:00', '2026-08-19 09:00', 0.0],
        ];
    }

    #[DataProvider('intervals')]
    public function test_working_hours_between(string $from, string $to, float $expected): void
    {
        $this->assertSame(
            $expected,
            $this->clock()->workingHoursBetween($this->lagos($from), $this->lagos($to)),
        );
    }

    /**
     * The timezone is configured, not inherited (§11c).
     *
     * A server in UTC must produce the same answer as one in Lagos, because
     * the promise is made to customers in Lagos.
     */
    public function test_the_clock_uses_lagos_regardless_of_the_input_timezone(): void
    {
        // 07:00 UTC is 08:00 in Lagos — the moment the office opens.
        $from = Carbon::parse('2026-08-19 07:00', 'UTC');
        $to = Carbon::parse('2026-08-19 10:00', 'UTC');

        $this->assertSame(3.0, $this->clock()->workingHoursBetween($from, $to));
    }

    /**
     * A weekend on its own is zero, not a negative or a partial day.
     */
    public function test_a_weekend_alone_counts_nothing(): void
    {
        $this->assertSame(
            0.0,
            $this->clock()->workingHoursBetween(
                $this->lagos('2026-08-22 08:00'),
                $this->lagos('2026-08-23 17:00'),
            ),
        );
    }

    // -----------------------------------------------------------------
    // What counts as overdue
    // -----------------------------------------------------------------

    /**
     * A quote carrying UTC timestamps, exactly as the database holds them.
     *
     * The conversion matters. Assigning a Lagos-offset Carbon to a datetime
     * cast formats it without its offset, so 08:00 Lagos would be read back as
     * 08:00 UTC — an hour of drift that only shows up near the edges of the
     * working day. Storing UTC mirrors what `submitted_at => now()` really
     * writes on submission.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function quote(array $attributes = []): Quote
    {
        $attributes = array_map(
            fn (mixed $value): mixed => $value instanceof Carbon ? $value->copy()->utc() : $value,
            $attributes,
        );

        // `first_response_at` is not fillable — it is set by the act of
        // replying, never by a form — so it is assigned rather than passed in.
        $unfillable = array_intersect_key($attributes, array_flip(['first_response_at', 'closed_at']));

        $quote = new Quote(array_merge([
            'reference' => 'OL-Q-TEST',
            'email' => 'buyer@example.com',
            'phone' => '+2348000000000',
            'status' => 'new',
            'source' => 'web',
            'submitted_at' => $this->lagos('2026-08-19 08:00')->utc(),
        ], array_diff_key($attributes, $unfillable)));

        foreach ($unfillable as $key => $value) {
            $quote->setAttribute($key, $value);
        }

        return $quote;
    }

    public function test_a_quote_past_four_working_hours_is_overdue(): void
    {
        Carbon::setTestNow($this->lagos('2026-08-19 13:00'));

        $this->assertTrue($this->clock()->isOverdue($this->quote()));

        Carbon::setTestNow();
    }

    public function test_a_quote_inside_four_working_hours_is_not_overdue(): void
    {
        Carbon::setTestNow($this->lagos('2026-08-19 11:59'));

        $this->assertFalse($this->clock()->isOverdue($this->quote()));

        Carbon::setTestNow();
    }

    /**
     * Exactly four hours is not yet late — the promise is "within four".
     */
    public function test_exactly_on_target_is_not_overdue(): void
    {
        Carbon::setTestNow($this->lagos('2026-08-19 12:00'));

        $this->assertFalse($this->clock()->isOverdue($this->quote()));

        Carbon::setTestNow();
    }

    /**
     * The weekend case, stated as the rule it protects: a Friday-evening lead
     * is not late on Saturday morning, however many hours have elapsed.
     */
    public function test_a_friday_evening_lead_is_not_late_on_saturday(): void
    {
        Carbon::setTestNow($this->lagos('2026-08-22 10:00'));

        $quote = $this->quote(['submitted_at' => $this->lagos('2026-08-21 16:30')]);

        $this->assertFalse($this->clock()->isOverdue($quote));

        Carbon::setTestNow();
    }

    public function test_an_answered_quote_is_never_overdue(): void
    {
        Carbon::setTestNow($this->lagos('2026-08-26 13:00'));

        $quote = $this->quote(['first_response_at' => $this->lagos('2026-08-19 09:00')]);

        $this->assertFalse($this->clock()->isOverdue($quote));

        Carbon::setTestNow();
    }

    /**
     * An answered quote's clock stops at the reply, so the queue does not keep
     * ageing rows nobody is waiting on.
     */
    public function test_the_clock_stops_at_the_reply(): void
    {
        Carbon::setTestNow($this->lagos('2026-08-26 13:00'));

        $quote = $this->quote(['first_response_at' => $this->lagos('2026-08-19 09:30')]);

        $this->assertSame(1.5, $this->clock()->waitingHours($quote));

        Carbon::setTestNow();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function closedStatuses(): array
    {
        return ['won' => ['won'], 'lost' => ['lost'], 'spam' => ['spam']];
    }

    /**
     * A closed lead is not waiting on anyone. Spam especially: a spam run
     * would otherwise turn the whole queue red and hide the real leads.
     */
    #[DataProvider('closedStatuses')]
    public function test_a_closed_quote_is_never_overdue(string $status): void
    {
        Carbon::setTestNow($this->lagos('2026-08-26 13:00'));

        $this->assertFalse($this->clock()->isOverdue($this->quote(['status' => $status])));

        Carbon::setTestNow();
    }

    public function test_describe_reads_as_english(): void
    {
        Carbon::setTestNow($this->lagos('2026-08-19 09:30'));

        $clock = $this->clock();

        $this->assertSame('1.5 working hours', $clock->describe($this->quote()));

        $answered = $this->quote(['first_response_at' => $this->lagos('2026-08-19 09:00')]);

        $this->assertSame('answered in 1 working hour', $clock->describe($answered));

        Carbon::setTestNow();
    }
}
