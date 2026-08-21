<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Quote;
use App\Models\QuoteBasket;
use App\Quotes\AbuseGuard;
use App\Quotes\BasketSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The quote journey (Phase 9).
 *
 * This is the second most important thing the system does after organic search
 * and the only write path a customer touches. Its failure mode is the worst
 * available: a buyer believes their request was sent, and nobody ever learns
 * otherwise.
 */
final class QuoteJourneyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * The limiter is backed by Redis, which is NOT reset by
         * RefreshDatabase — so without this the per-email allowance is shared
         * across the whole suite and later tests fail with 429 for reasons
         * that have nothing to do with what they assert.
         */
        RateLimiter::clear('quote:ip:'.hash('sha256', '127.0.0.1'));

        foreach (['buyer@example.com', 'nojs@example.com', 'bot@example.com'] as $email) {
            RateLimiter::clear('quote:email:'.hash('sha256', $email));
        }

        foreach (range(1, 5) as $i) {
            RateLimiter::clear('quote:email:'.hash('sha256', "buyer{$i}@example.com"));
        }
    }

    /**
     * The plaintext basket token, carried between requests in a test.
     *
     * ── Why this is not automatic ────────────────────────────────────────
     * Laravel's JSON test helpers send NO cookies unless withCredentials() is
     * called (see MakesHttpRequests::prepareCookiesForJsonRequest), and the
     * test client does not persist Set-Cookie between calls the way a browser
     * does. Without this, every multi-step basket test silently starts a fresh
     * basket and the assertions become meaningless rather than failing loudly.
     *
     * The real client is same-origin, where fetch sends cookies by default.
     */
    private ?string $token = null;

    /** A request carrying whatever basket cookie this test has acquired. */
    private function client(): static
    {
        $test = $this->withCredentials();

        return $this->token === null
            ? $test
            : $test->withUnencryptedCookie(BasketSession::COOKIE, $this->token);
    }

    /**
     * Captures the basket cookie a response issued.
     *
     * The value is used as-is. EncryptCookies belongs to the `web` middleware
     * group and these are `api` routes, so the cookie is NOT encrypted — see
     * BasketSession for why that is safe for an opaque high-entropy token that
     * is only ever compared against a stored hash.
     */
    private function capture(TestResponse $response): TestResponse
    {
        $cookie = collect($response->headers->getCookies())
            ->firstWhere(fn ($c): bool => $c->getName() === BasketSession::COOKIE);

        if ($cookie === null) {
            return $response;
        }

        $value = (string) $cookie->getValue();

        if ($value === '') {
            // A cleared cookie: the basket was submitted.
            $this->token = null;

            return $response;
        }

        $this->token = $value;

        return $response;
    }

    /** Adds a product and remembers the basket it created. */
    private function addProduct(string $slug, array $payload = []): TestResponse
    {
        return $this->capture(
            $this->client()->postJson('/api/v1/basket/items', ['slug' => $slug, ...$payload])
        );
    }

    private function product(array $attributes = []): Product
    {
        return Product::factory()->create([
            'status' => 'active',
            'min_order_qty' => 1,
            'qty_step' => 1,
            ...$attributes,
        ]);
    }

    // ── basket ────────────────────────────────────────────────────────────

    public function test_an_empty_basket_reads_as_empty_rather_than_erroring(): void
    {
        $this->getJson('/api/v1/basket')
            ->assertOk()
            ->assertJsonPath('data.line_count', 0)
            ->assertJsonPath('data.items', []);
    }

    /** A GET must not create a row, or every crawler leaves one behind. */
    public function test_reading_a_basket_does_not_create_one(): void
    {
        $this->getJson('/api/v1/basket')->assertOk();

        $this->assertSame(0, QuoteBasket::query()->count());
    }

    public function test_adding_a_product_creates_a_basket_and_sets_a_cookie(): void
    {
        $product = $this->product();

        $response = $this->postJson('/api/v1/basket/items', ['slug' => $product->slug, 'qty' => 2]);

        $response->assertOk()->assertJsonPath('data.line_count', 1);
        $response->assertCookie(BasketSession::COOKIE);

        $this->assertSame(1, QuoteBasket::query()->count());
    }

    /**
     * The cookie must not be readable by script: it is the only credential
     * standing between a visitor and someone else's basket, which is about to
     * hold an email address and a phone number.
     */
    public function test_the_basket_cookie_is_http_only(): void
    {
        $product = $this->product();

        $response = $this->postJson('/api/v1/basket/items', ['slug' => $product->slug]);

        $cookie = collect($response->headers->getCookies())
            ->firstWhere(fn ($c) => $c->getName() === BasketSession::COOKIE);

        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly(), 'The basket cookie must be HttpOnly.');
        $this->assertSame('lax', $cookie->getSameSite());
    }

    /** The cookie holds a token, never the basket id or its contents. */
    public function test_the_cookie_does_not_leak_the_basket_identifier(): void
    {
        $product = $this->product();

        $response = $this->postJson('/api/v1/basket/items', ['slug' => $product->slug]);

        $cookie = collect($response->headers->getCookies())
            ->firstWhere(fn ($c) => $c->getName() === BasketSession::COOKIE);

        $basket = QuoteBasket::query()->firstOrFail();

        $this->assertNotSame($basket->uuid, $cookie?->getValue());
        $this->assertNotSame((string) $basket->id, $cookie?->getValue());
        $this->assertNotSame($basket->session_token_hash, $cookie?->getValue());
    }

    public function test_a_draft_product_cannot_be_added(): void
    {
        $draft = Product::factory()->draft()->create();

        $this->postJson('/api/v1/basket/items', ['slug' => $draft->slug])->assertNotFound();
    }

    public function test_adding_the_same_product_twice_merges_the_lines(): void
    {
        $product = $this->product();

        $this->addProduct($product->slug, ['qty' => 2]);

        $this->addProduct($product->slug, ['qty' => 3])
            ->assertOk()
            ->assertJsonPath('data.line_count', 1)
            ->assertJsonPath('data.unit_count', 5);
    }

    /**
     * The basket is a GET that returns 200 and sets a cookie, so it looks
     * exactly like a cacheable catalogue read. It is not: a shared cache
     * holding this would serve one visitor's basket, and their cookie, to the
     * next person who asked.
     */
    public function test_the_basket_is_never_publicly_cacheable(): void
    {
        $this->addProduct($this->product()->slug);

        $response = $this->client()->getJson('/api/v1/basket');

        $cacheControl = (string) $response->headers->get('Cache-Control');

        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringNotContainsString('public', $cacheControl);
    }

    // ── quantity rules (§9b) ──────────────────────────────────────────────

    /**
     * Coerced UP, not rejected. A buyer who types 7 against a product sold in
     * fives wants about seven; an error message loses the quote, and rounding
     * down silently gives them less than they asked for.
     */
    public function test_quantity_is_coerced_up_to_the_next_allowed_step(): void
    {
        $product = $this->product(['min_order_qty' => 5, 'qty_step' => 5]);

        $this->postJson('/api/v1/basket/items', ['slug' => $product->slug, 'qty' => 7])
            ->assertOk()
            ->assertJsonPath('data.items.0.qty', 10);
    }

    public function test_quantity_is_raised_to_the_minimum(): void
    {
        $product = $this->product(['min_order_qty' => 12, 'qty_step' => 1]);

        $this->postJson('/api/v1/basket/items', ['slug' => $product->slug, 'qty' => 1])
            ->assertOk()
            ->assertJsonPath('data.items.0.qty', 12);
    }

    /**
     * Steps count FROM the minimum. A product with a minimum of 4 sold in
     * fives allows 4, 9, 14 — not 5, 10, 15, which would exclude the minimum
     * the product itself declares.
     */
    public function test_steps_are_measured_from_the_minimum(): void
    {
        $product = $this->product(['min_order_qty' => 4, 'qty_step' => 5]);

        $this->postJson('/api/v1/basket/items', ['slug' => $product->slug, 'qty' => 4])
            ->assertJsonPath('data.items.0.qty', 4);

        $this->postJson('/api/v1/basket', []);
    }

    /** The rules travel with the line so the stepper can agree with the server. */
    public function test_the_line_carries_its_quantity_rules(): void
    {
        $product = $this->product(['min_order_qty' => 5, 'qty_step' => 5]);

        $this->postJson('/api/v1/basket/items', ['slug' => $product->slug])
            ->assertJsonPath('data.items.0.min_order_qty', 5)
            ->assertJsonPath('data.items.0.qty_step', 5);
    }

    // ── free text ─────────────────────────────────────────────────────────

    public function test_a_free_text_line_is_stripped_of_markup(): void
    {
        $response = $this->capture($this->client()->postJson('/api/v1/basket/items/free-text', [
            'description' => '<script>alert(1)</script>Whiteboard 1200x900',
            'qty' => 3,
        ]));

        $response->assertOk()->assertJsonPath('data.items.0.is_free_text', true);

        $name = $this->client()->getJson('/api/v1/basket')->json('data.items.0.name');

        $this->assertStringNotContainsString('<script>', (string) $name);
        $this->assertStringContainsString('Whiteboard', (string) $name);
    }

    // ── isolation ─────────────────────────────────────────────────────────

    /**
     * Line ids are sequential, so scoping every mutation to the caller's own
     * basket is what stops one visitor editing another's by guessing.
     */
    public function test_a_visitor_cannot_touch_another_visitors_line(): void
    {
        $product = $this->product();

        $victim = $this->addProduct($product->slug);
        $victimLineId = $victim->json('data.items.0.id');

        // A second visitor: no basket cookie at all.
        $this->token = null;

        $this->client()
            ->patchJson("/api/v1/basket/items/{$victimLineId}", ['qty' => 999])
            ->assertNotFound();
    }

    // ── submission (§9d, §9f) ─────────────────────────────────────────────

    private function submit(array $overrides = []): TestResponse
    {
        return $this->capture($this->client()->postJson('/api/v1/quotes', [
            'email' => 'buyer@example.com',
            'phone' => '+234 707 472 3165',
            // Old enough to pass the timing check.
            AbuseGuard::TIMESTAMP => now()->getTimestamp() - 60,
            ...$overrides,
        ]));
    }

    public function test_an_empty_basket_cannot_be_submitted(): void
    {
        $this->submit()->assertStatus(409)->assertJsonPath('error.code', 'BasketEmpty');
    }

    public function test_a_submission_returns_a_reference(): void
    {
        $this->addProduct($this->product()->slug);

        $this->submit()
            ->assertCreated()
            ->assertJsonPath('data.reference', fn (string $ref): bool => (bool) preg_match('/^OL-Q-\d{4}-\d{6}$/', $ref));
    }

    /**
     * The quote records what was asked for at the moment it was asked. If it
     * rendered live, renaming a product would retroactively change what a
     * customer is on record as having requested.
     */
    public function test_the_quote_snapshots_the_product(): void
    {
        $product = $this->product(['name' => 'Original Name', 'sku' => 'SKU-1']);

        $this->addProduct($product->slug, ['qty' => 2]);
        $this->submit()->assertCreated();

        $product->update(['name' => 'Renamed Later']);

        $item = Quote::query()->firstOrFail()->items()->firstOrFail();

        $this->assertSame('Original Name', $item->product_name_snapshot);
        $this->assertSame('SKU-1', $item->sku_snapshot);
    }

    public function test_submitting_closes_the_basket_and_clears_the_cookie(): void
    {
        $this->addProduct($this->product()->slug);

        $response = $this->submit()->assertCreated();

        $this->assertSame('submitted', QuoteBasket::query()->firstOrFail()->status);
        $response->assertCookieExpired(BasketSession::COOKIE);
    }

    /** The IP is kept for abuse investigation, hashed — never in the clear. */
    public function test_the_ip_is_stored_hashed(): void
    {
        $this->addProduct($this->product()->slug);
        $this->submit()->assertCreated();

        $quote = Quote::query()->firstOrFail();

        $this->assertNotNull($quote->ip_hash);
        $this->assertSame(64, strlen((string) $quote->ip_hash));
        $this->assertStringNotContainsString('127.0.0.1', (string) $quote->ip_hash);
    }

    public function test_a_submission_records_an_event(): void
    {
        $this->addProduct($this->product()->slug);
        $this->submit()->assertCreated();

        $this->assertSame('submitted', Quote::query()->firstOrFail()->events()->firstOrFail()->type);
    }

    // ── anti-abuse (§9e) ──────────────────────────────────────────────────

    public function test_a_filled_honeypot_is_rejected(): void
    {
        $this->addProduct($this->product()->slug);

        $this->submit([AbuseGuard::HONEYPOT => 'http://spam.example'])
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'QuoteRejected');

        $this->assertSame(0, Quote::query()->count());
    }

    public function test_a_submission_faster_than_a_human_is_rejected(): void
    {
        $this->addProduct($this->product()->slug);

        $this->submit([AbuseGuard::TIMESTAMP => now()->getTimestamp()])->assertStatus(429);

        $this->assertSame(0, Quote::query()->count());
    }

    /**
     * The form must work without JavaScript, so a client that never ran the
     * script — and therefore sends no timestamp — must not be turned away.
     */
    public function test_a_missing_timestamp_is_accepted(): void
    {
        $this->addProduct($this->product()->slug);

        $this->client()->postJson('/api/v1/quotes', [
            'email' => 'nojs@example.com',
            'phone' => '08012345678',
        ])->assertCreated();
    }

    /**
     * A rejected submission must NOT be silently accepted. A buyer who trips a
     * heuristic and is told nothing waits for a reply that never comes, and
     * nobody ever learns it happened.
     */
    public function test_a_rejection_is_reported_rather_than_swallowed(): void
    {
        $this->addProduct($this->product()->slug);

        $response = $this->submit([AbuseGuard::HONEYPOT => 'spam']);

        $response->assertStatus(429);
        $this->assertNotEmpty($response->json('error.message'));
    }

    // ── reference (§9f) ───────────────────────────────────────────────────

    public function test_references_increment_without_gaps(): void
    {
        $references = [];

        foreach (range(1, 3) as $i) {
            // A fresh visitor each round: no carried cookie, so each gets its
            // own basket, which is what a real sequence of submissions is.
            $this->token = null;

            $this->addProduct($this->product()->slug);

            // A distinct email each time, so the per-email limit does not trip.
            $references[] = $this->submit(['email' => "buyer{$i}@example.com"])->json('data.reference');
        }

        $year = now()->format('Y');

        $this->assertSame([
            "OL-Q-{$year}-000001",
            "OL-Q-{$year}-000002",
            "OL-Q-{$year}-000003",
        ], $references);
    }
}
