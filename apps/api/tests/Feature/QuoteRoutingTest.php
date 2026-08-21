<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Resources\QuoteRoutingRules\Pages\ListQuoteRoutingRules;
use App\Filament\Resources\QuoteRoutingRules\QuoteRoutingRuleResource;
use App\Mail\QuoteReceivedMail;
use App\Models\Category;
use App\Models\Product;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\QuoteRoutingRule;
use App\Models\User;
use App\Quotes\QuoteRouting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Where a lead lands (§11d).
 *
 * The property that matters most is the one asserted last: routing can narrow
 * but never drop. Every failure mode of this class — no rule, a paused rule, a
 * deleted category, a product with no category, an empty quote — has to end
 * with the lead in somebody's inbox, because the feature exists to make leads
 * MORE likely to be answered.
 */
final class QuoteRoutingTest extends TestCase
{
    use RefreshDatabase;

    private const DEFAULT_INBOX = 'sales@officelandng.com';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('quotes.routing.default', self::DEFAULT_INBOX);
        config()->set('quotes.routing.categories', []);
    }

    private function routing(): QuoteRouting
    {
        return new QuoteRouting;
    }

    /** A quote whose first line sits in the given category. */
    private function quoteIn(?Category $category): Quote
    {
        $quote = Quote::create([
            'uuid' => (string) Str::uuid(),
            'reference' => 'OL-Q-2026-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
            'email' => 'buyer@example.com',
            'phone' => '+2348000000000',
            'status' => 'new',
            'source' => 'web',
            'submitted_at' => now(),
        ]);

        $product = Product::factory()->create([
            'status' => 'active',
            'primary_category_id' => $category?->id,
        ]);

        QuoteItem::create([
            'quote_id' => $quote->id,
            'product_id' => $product->id,
            'product_name_snapshot' => $product->name,
            'qty' => 1,
            'sort_order' => 0,
        ]);

        return $quote;
    }

    // -----------------------------------------------------------------
    // Matching
    // -----------------------------------------------------------------

    public function test_a_rule_routes_its_category(): void
    {
        $furniture = Category::factory()->root('office-furniture')->create();

        QuoteRoutingRule::create([
            'category_id' => $furniture->id,
            'recipient' => 'furniture@officelandng.com',
        ]);

        $this->assertSame(
            'furniture@officelandng.com',
            $this->routing()->recipientFor($this->quoteIn($furniture)),
        );
    }

    /**
     * A rule covers everything beneath it, which is the whole reason the match
     * is on the path rather than on the id: nobody is going to add a rule for
     * every one of two hundred leaf categories.
     */
    public function test_a_rule_covers_descendant_categories(): void
    {
        $furniture = Category::factory()->root('office-furniture')->create();
        $chairs = Category::factory()->childOf($furniture, 'chairs')->create();

        QuoteRoutingRule::create([
            'category_id' => $furniture->id,
            'recipient' => 'furniture@officelandng.com',
        ]);

        $this->assertSame(
            'furniture@officelandng.com',
            $this->routing()->recipientFor($this->quoteIn($chairs)),
        );
    }

    /**
     * The specific rule wins over the general one — and it wins because it is
     * longer, not because of the order somebody happened to create them in.
     * Created deliberately in the "wrong" order to prove that.
     */
    public function test_the_longest_matching_path_wins(): void
    {
        $it = Category::factory()->root('it-products')->create();
        $printers = Category::factory()->childOf($it, 'printers')->create();

        QuoteRoutingRule::create(['category_id' => $printers->id, 'recipient' => 'printers@officelandng.com']);
        QuoteRoutingRule::create(['category_id' => $it->id, 'recipient' => 'it@officelandng.com']);

        $this->assertSame(
            'printers@officelandng.com',
            $this->routing()->recipientFor($this->quoteIn($printers)),
        );

        // …and the parent rule still covers the rest of the department.
        $laptops = Category::factory()->childOf($it, 'laptops')->create();

        $this->assertSame(
            'it@officelandng.com',
            $this->routing()->recipientFor($this->quoteIn($laptops)),
        );
    }

    /**
     * A rule set in the admin overrides one left in config for the same path.
     * Otherwise a change made in the UI appears to do nothing, which is worse
     * than having no UI at all.
     */
    public function test_a_stored_rule_beats_a_configured_one(): void
    {
        $furniture = Category::factory()->root('office-furniture')->create();

        config()->set('quotes.routing.categories', [
            '/office-furniture/' => 'old-address@officelandng.com',
        ]);

        QuoteRoutingRule::create([
            'category_id' => $furniture->id,
            'recipient' => 'furniture@officelandng.com',
        ]);

        $this->assertSame(
            'furniture@officelandng.com',
            $this->routing()->recipientFor($this->quoteIn($furniture)),
        );
    }

    public function test_a_configured_rule_still_works_with_no_stored_rules(): void
    {
        $furniture = Category::factory()->root('office-furniture')->create();

        config()->set('quotes.routing.categories', [
            '/office-furniture/' => 'furniture@officelandng.com',
        ]);

        $this->assertSame(
            'furniture@officelandng.com',
            $this->routing()->recipientFor($this->quoteIn($furniture)),
        );
    }

    // -----------------------------------------------------------------
    // Never drops a lead
    // -----------------------------------------------------------------

    public function test_an_unmatched_category_falls_back_to_the_default(): void
    {
        $stationery = Category::factory()->root('stationery')->create();

        $this->assertSame(
            self::DEFAULT_INBOX,
            $this->routing()->recipientFor($this->quoteIn($stationery)),
        );
    }

    /**
     * A paused rule falls back rather than routing nowhere. Pausing is for a
     * holiday, and a lead arriving during one still has to reach somebody.
     */
    public function test_an_inactive_rule_is_ignored(): void
    {
        $furniture = Category::factory()->root('office-furniture')->create();

        QuoteRoutingRule::create([
            'category_id' => $furniture->id,
            'recipient' => 'furniture@officelandng.com',
            'is_active' => false,
        ]);

        $this->assertSame(
            self::DEFAULT_INBOX,
            $this->routing()->recipientFor($this->quoteIn($furniture)),
        );
    }

    public function test_a_product_with_no_category_falls_back(): void
    {
        $this->assertSame(
            self::DEFAULT_INBOX,
            $this->routing()->recipientFor($this->quoteIn(null)),
        );
    }

    /**
     * A quote with no lines at all — a free-text enquiry, or a line whose
     * product was deleted. It still has to reach somebody.
     */
    public function test_a_quote_with_no_lines_falls_back(): void
    {
        $quote = Quote::create([
            'uuid' => (string) Str::uuid(),
            'reference' => 'OL-Q-2026-000001',
            'email' => 'buyer@example.com',
            'phone' => '+2348000000000',
            'status' => 'new',
            'source' => 'web',
            'submitted_at' => now(),
        ]);

        $this->assertSame(self::DEFAULT_INBOX, $this->routing()->recipientFor($quote));
    }

    /**
     * Categories soft-delete, so the FK cascade never fires and the rule row
     * survives. What must NOT survive is the routing: a trashed category is
     * not part of the taxonomy any more, and a rule that kept matching it
     * would send leads to a team for a department that no longer exists.
     *
     * It falls back rather than erroring, which is the safe direction.
     */
    public function test_a_rule_for_a_trashed_category_stops_matching(): void
    {
        $furniture = Category::factory()->root('office-furniture')->create();

        QuoteRoutingRule::create([
            'category_id' => $furniture->id,
            'recipient' => 'furniture@officelandng.com',
        ]);

        $quote = $this->quoteIn($furniture);

        $this->assertSame('furniture@officelandng.com', $this->routing()->recipientFor($quote));

        $furniture->delete();

        $this->assertSame(self::DEFAULT_INBOX, $this->routing()->recipientFor($quote));
    }

    // -----------------------------------------------------------------
    // The test-send sample (§11d)
    // -----------------------------------------------------------------

    /**
     * The sample must never be saved and must never carry real data: the
     * destination address is typed into a form, so a real quote would let
     * anybody with admin access forward a customer's details anywhere.
     */
    public function test_the_sample_quote_is_never_persisted(): void
    {
        $sample = QuoteRouting::sampleQuote();

        $this->assertFalse($sample->exists);
        $this->assertSame(0, Quote::query()->count());
        $this->assertCount(1, $sample->items);
        $this->assertStringContainsString('SAMPLE', (string) $sample->items->first()?->product_name_snapshot);

        // Distinguishable from a real reference, so nobody chases it.
        $this->assertSame('OL-Q-TEST', $sample->reference);
    }

    /**
     * The test-send goes to the address typed into the form, carries the real
     * notification template, and leaves nothing behind in the queue.
     */
    public function test_the_test_send_uses_the_sample_and_creates_no_lead(): void
    {
        Mail::fake();

        $this->actingAs(User::factory()->create([
            'role' => Role::Sales->value,
            'is_active' => true,
        ]));

        Livewire::test(ListQuoteRoutingRules::class)
            ->callAction('testSend', ['to' => 'furniture@officelandng.com']);

        Mail::assertSent(
            QuoteReceivedMail::class,
            fn (QuoteReceivedMail $mail): bool => $mail->hasTo('furniture@officelandng.com')
                && $mail->quote->reference === 'OL-Q-TEST',
        );

        $this->assertSame(0, Quote::query()->count());
    }

    /**
     * A quote's contact details and buying intent are not catalogue data.
     */
    public function test_only_quote_handlers_can_change_routing(): void
    {
        $this->actingAs(User::factory()->create([
            'role' => Role::CatalogueManager->value,
            'is_active' => true,
        ]));

        $this->assertFalse(QuoteRoutingRuleResource::canAccess());

        $this->actingAs(User::factory()->create([
            'role' => Role::Sales->value,
            'is_active' => true,
        ]));

        $this->assertTrue(QuoteRoutingRuleResource::canAccess());
    }
}
