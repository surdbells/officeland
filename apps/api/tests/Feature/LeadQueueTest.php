<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Resources\Quotes\Pages\ListQuotes;
use App\Filament\Resources\Quotes\Pages\ViewQuote;
use App\Filament\Resources\Quotes\QuoteResource;
use App\Models\Product;
use App\Models\Quote;
use App\Models\QuoteEvent;
use App\Models\QuoteItem;
use App\Models\User;
use App\Quotes\ResponseClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Phase 11 gate: "the sales team completes a full lead cycle."
 *
 * Driven through the actual Filament pages rather than the models, because the
 * gate is about the screens. A queue that only works if you already know which
 * column to set does not pass.
 *
 * The lead cycle asserted here is the real one: a lead arrives, somebody takes
 * it, replies, leaves a note explaining what happened, and closes it won or
 * lost — with every step visible afterwards in the timeline.
 */
final class LeadQueueTest extends TestCase
{
    use RefreshDatabase;

    private User $salesperson;

    protected function setUp(): void
    {
        parent::setUp();

        $this->salesperson = User::factory()->create([
            'name' => 'Amaka Okafor',
            'role' => Role::Sales->value,
            'is_active' => true,
        ]);

        $this->actingAs($this->salesperson);
    }

    private function lead(array $attributes = []): Quote
    {
        $quote = Quote::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'reference' => 'OL-Q-2026-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
            'email' => 'buyer@example.com',
            'phone' => '+2348000000000',
            'message' => 'We need these before the end of the month.',
            'status' => 'new',
            'source' => 'web',
            'submitted_at' => now()->subHours(1),
        ], $attributes));

        $product = Product::factory()->create(['status' => 'active']);

        QuoteItem::create([
            'quote_id' => $quote->id,
            'product_id' => $product->id,
            'product_name_snapshot' => $product->name,
            'qty' => 10,
            'sort_order' => 0,
        ]);

        return $quote;
    }

    /** @return array<int, string> */
    private function eventTypes(Quote $quote): array
    {
        return QuoteEvent::query()
            ->where('quote_id', $quote->id)
            ->orderBy('id')
            ->pluck('type')
            ->all();
    }

    // -----------------------------------------------------------------
    // The gate
    // -----------------------------------------------------------------

    public function test_a_salesperson_completes_a_full_lead_cycle(): void
    {
        $quote = $this->lead();

        $page = Livewire::test(ViewQuote::class, ['record' => $quote->getRouteKey()]);

        // 1. Take it.
        $page->callAction('assign', ['assigned_to_user_id' => $this->salesperson->id]);

        // 2. Reply — the only thing that stops the SLA clock, because the
        //    reply itself happens outside this system (D-005).
        $page->callAction('markAnswered');

        // 3. Record what was said, for whoever picks it up next.
        $page->callAction('note', ['note' => 'Called; sending prices for 10 units.']);

        // 4. Close it.
        $page->callAction('status', ['status' => 'won', 'note' => 'PO received.']);

        $quote->refresh();

        $this->assertSame($this->salesperson->id, $quote->assigned_to_user_id);
        $this->assertNotNull($quote->first_response_at);
        $this->assertSame('won', $quote->status);
        $this->assertNotNull($quote->closed_at);

        // Every step is on the record, in order (§11b).
        $this->assertSame(
            ['assigned', 'first_response', 'note', 'status_changed'],
            $this->eventTypes($quote),
        );
    }

    /**
     * Replying takes ownership. The default must never be "answered by
     * nobody" — that is precisely the state the queue exists to eliminate.
     */
    public function test_replying_assigns_an_unowned_lead_to_whoever_replied(): void
    {
        $quote = $this->lead();

        Livewire::test(ViewQuote::class, ['record' => $quote->getRouteKey()])
            ->callAction('markAnswered');

        $quote->refresh();

        $this->assertSame($this->salesperson->id, $quote->assigned_to_user_id);
        $this->assertSame('contacted', $quote->status);
    }

    /**
     * Assigning to somebody who cannot open the queue produces a lead that
     * looks owned and behaves abandoned, which is the worst of both.
     */
    public function test_a_lead_cannot_be_assigned_to_someone_who_cannot_see_it(): void
    {
        $catalogueManager = User::factory()->create([
            'role' => Role::CatalogueManager->value,
            'is_active' => true,
        ]);

        $inactiveSalesperson = User::factory()->create([
            'role' => Role::Sales->value,
            'is_active' => false,
        ]);

        $quote = $this->lead();

        Livewire::test(ViewQuote::class, ['record' => $quote->getRouteKey()])
            ->callAction('assign', ['assigned_to_user_id' => $catalogueManager->id])
            ->assertHasActionErrors(['assigned_to_user_id']);

        Livewire::test(ViewQuote::class, ['record' => $quote->getRouteKey()])
            ->callAction('assign', ['assigned_to_user_id' => $inactiveSalesperson->id])
            ->assertHasActionErrors(['assigned_to_user_id']);

        $this->assertNull($quote->refresh()->assigned_to_user_id);
    }

    /**
     * A lead already owned by someone else stays theirs — a colleague chasing
     * it must not silently steal it.
     */
    public function test_replying_does_not_reassign_an_owned_lead(): void
    {
        $owner = User::factory()->create(['role' => Role::Sales->value, 'is_active' => true]);

        $quote = $this->lead();
        $quote->forceFill(['assigned_to_user_id' => $owner->id])->save();

        Livewire::test(ViewQuote::class, ['record' => $quote->getRouteKey()])
            ->callAction('markAnswered');

        $this->assertSame($owner->id, $quote->refresh()->assigned_to_user_id);
    }

    /**
     * Reopening a closed lead clears the closing timestamp, so "how long did
     * this take" stays answerable rather than recording the first close.
     */
    public function test_reopening_a_lead_clears_the_closing_timestamp(): void
    {
        $quote = $this->lead();

        $page = Livewire::test(ViewQuote::class, ['record' => $quote->getRouteKey()]);

        $page->callAction('status', ['status' => 'lost', 'note' => 'Went elsewhere.']);
        $this->assertNotNull($quote->refresh()->closed_at);

        $page->callAction('status', ['status' => 'contacted', 'note' => 'They came back.']);
        $this->assertNull($quote->refresh()->closed_at);
    }

    /**
     * The note is the only record of why a lead was lost, so it has to survive
     * verbatim rather than being summarised into the status.
     */
    public function test_a_note_is_recorded_verbatim(): void
    {
        $quote = $this->lead();

        Livewire::test(ViewQuote::class, ['record' => $quote->getRouteKey()])
            ->callAction('note', ['note' => 'Budget pulled; revisit in Q1.']);

        $event = QuoteEvent::query()->where('quote_id', $quote->id)->latest('id')->first();

        $this->assertSame('note', $event?->type);
        $this->assertSame('Budget pulled; revisit in Q1.', $event?->payload['note'] ?? null);
        $this->assertSame('Amaka Okafor', $event?->actor_label);
        $this->assertSame('Budget pulled; revisit in Q1.', $event?->describe());
    }

    // -----------------------------------------------------------------
    // The queue itself
    // -----------------------------------------------------------------

    /**
     * The default tab shows what needs answering and nothing else — an
     * answered lead and a closed one both drop out.
     */
    public function test_the_waiting_tab_hides_answered_and_closed_leads(): void
    {
        $waiting = $this->lead();

        $answered = $this->lead();
        $answered->forceFill(['first_response_at' => now()])->save();

        $closed = $this->lead(['status' => 'spam']);

        Livewire::test(ListQuotes::class)
            ->assertCanSeeTableRecords([$waiting])
            ->assertCanNotSeeTableRecords([$answered, $closed]);
    }

    /**
     * Oldest first. Newest-first buries the lead that has waited longest under
     * every new submission, which is the opposite of what a queue is for.
     */
    public function test_the_queue_puts_the_oldest_lead_first(): void
    {
        $older = $this->lead(['submitted_at' => now()->subDays(2)]);
        $newer = $this->lead(['submitted_at' => now()->subMinutes(5)]);

        Livewire::test(ListQuotes::class)
            ->assertCanSeeTableRecords([$older, $newer], inOrder: true);
    }

    /**
     * §11c on the screen rather than in the clock class: a lead past four
     * working hours appears on the Late tab, and one inside them does not.
     */
    public function test_the_late_tab_holds_only_overdue_leads(): void
    {
        // A Wednesday, so no weekend arithmetic is involved in the assertion.
        Carbon::setTestNow(Carbon::parse('2026-08-19 13:00', 'Africa/Lagos'));

        $late = $this->lead(['submitted_at' => Carbon::parse('2026-08-19 08:00', 'Africa/Lagos')->utc()]);
        $inTime = $this->lead(['submitted_at' => Carbon::parse('2026-08-19 12:00', 'Africa/Lagos')->utc()]);

        Livewire::test(ListQuotes::class)
            ->set('activeTab', 'overdue')
            ->assertCanSeeTableRecords([$late])
            ->assertCanNotSeeTableRecords([$inTime]);

        Carbon::setTestNow();
    }

    /**
     * The badge is the point of the whole screen: a salesperson should know
     * there is work without opening it, and know when it is late.
     */
    public function test_the_navigation_badge_counts_and_colours_the_backlog(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-19 09:00', 'Africa/Lagos'));

        $this->lead(['submitted_at' => Carbon::parse('2026-08-19 08:30', 'Africa/Lagos')->utc()]);

        $this->assertSame('1', QuoteResource::getNavigationBadge());
        $this->assertSame('warning', QuoteResource::getNavigationBadgeColor());

        // Four working hours later the same lead is late, and the badge says so.
        Carbon::setTestNow(Carbon::parse('2026-08-19 14:00', 'Africa/Lagos'));

        $this->assertSame('danger', QuoteResource::getNavigationBadgeColor());

        Carbon::setTestNow();
    }

    /**
     * With nothing waiting there is no badge at all. A permanent "0" is noise
     * that teaches people to stop looking at the number.
     */
    public function test_there_is_no_badge_when_nothing_is_waiting(): void
    {
        $this->assertNull(QuoteResource::getNavigationBadge());
    }

    // -----------------------------------------------------------------
    // Access
    // -----------------------------------------------------------------

    /**
     * Quotes carry customer contact details and buying intent, so the queue is
     * limited to the people who handle them.
     */
    public function test_a_catalogue_manager_cannot_reach_the_lead_queue(): void
    {
        $this->actingAs(User::factory()->create([
            'role' => Role::CatalogueManager->value,
            'is_active' => true,
        ]));

        $this->assertFalse(QuoteResource::canAccess());
    }

    public function test_sales_can_reach_the_lead_queue(): void
    {
        $this->assertTrue(QuoteResource::canAccess());
    }

    /**
     * A lead is a record of what a customer asked for. One typed by hand would
     * be indistinguishable from a real submission afterwards.
     */
    public function test_leads_cannot_be_created_by_hand(): void
    {
        $this->assertFalse(QuoteResource::canCreate());
    }

    /**
     * The SLA class and the screen must agree — the column reads from the same
     * clock the filters and the badge use.
     */
    public function test_the_waiting_column_and_the_clock_agree(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-19 11:00', 'Africa/Lagos'));

        $quote = $this->lead(['submitted_at' => Carbon::parse('2026-08-19 08:00', 'Africa/Lagos')->utc()]);

        $this->assertSame('3 working hours', app(ResponseClock::class)->describe($quote));

        Carbon::setTestNow();
    }
}
