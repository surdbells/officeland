<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\QuoteConfirmationMail;
use App\Mail\QuoteReceivedMail;
use App\Models\Product;
use App\Models\Quote;
use App\Quotes\BasketSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * The whole mail chain, end to end (§10a–§10f).
 *
 * Submission → QuoteMailer → Mailable → Blade template → transport → the exact
 * JSON ZeptoMail receives. Every other test in this suite covers one link;
 * this one proves they are actually connected, which is the part that breaks
 * when a template is renamed or a mailable stops being queued.
 *
 * The provider is faked at the HTTP boundary rather than at the Mail facade,
 * so the payload assertions are made against what would really go over the
 * wire.
 */
final class QuoteMailDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('mail.default', 'zeptomail');
        config()->set('mail.mailers.zeptomail.token', 'test-token');
        config()->set('mail.from.address', 'sales@officelandng.com');
        config()->set('mail.from.name', 'OfficeLand');
        config()->set('mail.sales_address', 'sales@officelandng.com');

        RateLimiter::clear('quote:ip:'.hash('sha256', '127.0.0.1'));
        RateLimiter::clear('quote:email:'.hash('sha256', 'buyer@example.com'));
    }

    /** Submits a quote through the real endpoints, carrying the basket cookie. */
    private function submitQuote(): Quote
    {
        $product = Product::factory()->create([
            'status' => 'active',
            'name' => 'M404dn HP LaserJet Pro Mono Printer',
            'sku' => 'OL-M404DN',
            'min_order_qty' => 1,
            'qty_step' => 1,
        ]);

        $added = $this->withCredentials()
            ->postJson('/api/v1/basket/items', ['slug' => $product->slug, 'qty' => 2]);

        $cookie = collect($added->headers->getCookies())
            ->firstWhere(fn ($c): bool => $c->getName() === BasketSession::COOKIE);

        $this->withCredentials()
            ->withUnencryptedCookie(BasketSession::COOKIE, (string) $cookie?->getValue())
            ->postJson('/api/v1/quotes', [
                'email' => 'buyer@example.com',
                'phone' => '+234 707 472 3165',
                'message' => 'Needed by month end.',
            ])->assertCreated();

        return Quote::query()->latest('id')->firstOrFail();
    }

    /**
     * §10c. Never inline in a web request: a provider timeout would make the
     * buyer wait, and an outage would fail a submission we had already
     * captured.
     */
    public function test_both_emails_are_queued_rather_than_sent_inline(): void
    {
        Mail::fake();

        $this->submitQuote();

        Mail::assertQueued(QuoteConfirmationMail::class);
        Mail::assertQueued(QuoteReceivedMail::class);

        Mail::assertNothingSent();
    }

    /**
     * The delays are deliberately long. A provider blip clears in seconds,
     * but a DNS or reputation problem takes minutes, and retrying five times
     * inside a minute burns every attempt before the problem can clear.
     */
    public function test_a_failed_send_is_retried_five_times_with_growing_delays(): void
    {
        $quote = new Quote(['reference' => 'OL-Q-2026-000001']);

        $mailable = new QuoteConfirmationMail($quote);

        $this->assertSame(5, $mailable->tries);
        $this->assertSame([10, 60, 300, 900], $mailable->backoff);
    }

    /**
     * The chain, proven at the wire. Queue::fake is deliberately NOT used:
     * the mail must actually be rendered and handed to the transport.
     */
    public function test_the_confirmation_reaches_the_provider_with_the_documented_payload(): void
    {
        Http::fake(['api.zeptomail.com/*' => Http::response([
            'data' => [['code' => 'EM_104', 'message' => 'OK']],
            'request_id' => 'req-abc',
            'object' => 'Email',
        ], 200)]);

        // Sync queue, so the queued mailable runs within the request.
        config()->set('queue.default', 'sync');

        $quote = $this->submitQuote();

        $payloads = [];

        Http::assertSent(function (ClientRequest $request) use (&$payloads): bool {
            $payloads[] = $request->data();

            return true;
        });

        $confirmation = collect($payloads)
            ->firstWhere(fn (array $p): bool => ($p['to'][0]['email_address']['address'] ?? null) === 'buyer@example.com');

        $this->assertNotNull($confirmation, 'The customer confirmation never reached the provider.');

        $this->assertSame("Your quote request {$quote->reference}", $confirmation['subject']);
        $this->assertSame($quote->reference, $confirmation['client_reference']);

        // Both bodies, because the plain-text alternative is what a text-only
        // client and several spam filters actually read.
        $this->assertNotEmpty($confirmation['htmlbody']);
        $this->assertNotEmpty($confirmation['textbody']);

        // The receipt carries the full detail: D-006 removed the PDF, so the
        // body IS the record the customer keeps.
        $this->assertStringContainsString('M404dn HP LaserJet Pro Mono Printer', $confirmation['htmlbody']);
        $this->assertStringContainsString($quote->reference, $confirmation['htmlbody']);

        // Replies reach a person, not a no-reply address.
        $this->assertSame(
            'sales@officelandng.com',
            $confirmation['reply_to'][0]['email_address']['address'],
        );
    }

    public function test_the_sales_notification_replies_to_the_customer(): void
    {
        Http::fake(['api.zeptomail.com/*' => Http::response(['request_id' => 'req-abc'], 200)]);
        config()->set('queue.default', 'sync');

        $this->submitQuote();

        $payloads = [];

        Http::assertSent(function (ClientRequest $request) use (&$payloads): bool {
            $payloads[] = $request->data();

            return true;
        });

        $internal = collect($payloads)
            ->firstWhere(fn (array $p): bool => ($p['to'][0]['email_address']['address'] ?? null) === 'sales@officelandng.com');

        $this->assertNotNull($internal, 'Sales was never notified.');

        /*
         * Reply-to is the CUSTOMER. A salesperson answers the lead by hitting
         * reply rather than copying an address out of the body, which removes
         * the most common transcription error in the pipeline.
         */
        $this->assertSame(
            'buyer@example.com',
            $internal['reply_to'][0]['email_address']['address'],
        );

        $this->assertStringContainsString('+234 707 472 3165', $internal['htmlbody']);
    }

    /** §10f: the send is recorded against the quote, not only in a log file. */
    public function test_queueing_is_recorded_on_the_quote(): void
    {
        Queue::fake();

        $quote = $this->submitQuote();

        $queued = $quote->events()->where('type', 'email_queued')->pluck('payload');

        $templates = $queued->map(fn ($p) => $p['template'] ?? null)->all();

        $this->assertContains('quote.confirmation', $templates);
        $this->assertContains('quote.received', $templates);
    }
}
