<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\QuoteConfirmationMail;
use App\Mail\QuoteReceivedMail;
use App\Models\EmailSuppression;
use App\Models\Product;
use App\Models\Quote;
use App\Quotes\BasketSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Bounce and complaint webhooks (§10d).
 *
 * ── Why the signature check is the whole security boundary ───────────────
 * This endpoint is unauthenticated by necessity: the caller is ZeptoMail, and
 * it cannot hold our credentials. An unverified webhook could suppress any
 * address an attacker names — a denial of service against our own customers,
 * delivered through our own bounce handling. So the signature is not a
 * formality, and these tests treat it as the feature.
 */
final class MailWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('mail.mailers.zeptomail.webhook_secret', self::SECRET);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function deliver(array $payload, ?string $secret = self::SECRET, ?int $timestampMs = null): TestResponse
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $ts = $timestampMs ?? (int) (microtime(true) * 1000);

        $headers = [];

        if ($secret !== null) {
            $signature = base64_encode(hash_hmac('sha256', $body, $secret, true));
            $headers['producer-signature'] = "ts={$ts};s={$signature};s-algorithm=HmacSHA256";
        }

        return $this->call(
            'POST',
            '/api/v1/webhooks/mail',
            [],
            [],
            [],
            $this->transformHeadersToServerVars($headers + ['Content-Type' => 'application/json']),
            $body,
        );
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function event(string $name, string $email = 'bounced@example.com', array $extra = []): array
    {
        return [
            'event_name' => $name,
            'webhook_request_id' => 'wh-123',
            'mailagent_key' => 'agent-456',
            'event_message' => [
                'email_info' => [
                    'to' => [['email_address' => ['address' => $email, 'name' => 'A Buyer']]],
                ],
                'event_data' => ['details' => [['reason' => 'mailbox does not exist']]],
                ...$extra,
            ],
        ];
    }

    // ── signature ─────────────────────────────────────────────────────────

    public function test_a_correctly_signed_webhook_is_accepted(): void
    {
        $this->deliver($this->event('hard_bounce'))->assertOk();
    }

    public function test_an_unsigned_webhook_is_rejected(): void
    {
        $this->deliver($this->event('hard_bounce'), secret: null)->assertUnauthorized();

        $this->assertSame(0, EmailSuppression::query()->count());
    }

    public function test_a_webhook_signed_with_the_wrong_secret_is_rejected(): void
    {
        $this->deliver($this->event('hard_bounce'), secret: 'not-the-secret')->assertUnauthorized();

        $this->assertSame(0, EmailSuppression::query()->count());
    }

    /**
     * Bounds replay: a captured webhook must not be resendable days later to
     * suppress an address on demand.
     */
    public function test_a_stale_signature_is_rejected(): void
    {
        $tenMinutesAgo = (int) ((microtime(true) - 600) * 1000);

        $this->deliver($this->event('hard_bounce'), timestampMs: $tenMinutesAgo)->assertUnauthorized();

        $this->assertSame(0, EmailSuppression::query()->count());
    }

    /**
     * The HMAC is over the bytes that arrived. Signing one payload and sending
     * another must not pass, or the signature protects nothing.
     */
    public function test_a_tampered_body_is_rejected(): void
    {
        $signed = json_encode($this->event('hard_bounce', 'real@example.com'), JSON_THROW_ON_ERROR);
        $tampered = json_encode($this->event('hard_bounce', 'victim@example.com'), JSON_THROW_ON_ERROR);

        $ts = (int) (microtime(true) * 1000);
        $signature = base64_encode(hash_hmac('sha256', $signed, self::SECRET, true));

        $this->call(
            'POST',
            '/api/v1/webhooks/mail',
            [],
            [],
            [],
            $this->transformHeadersToServerVars([
                'producer-signature' => "ts={$ts};s={$signature};s-algorithm=HmacSHA256",
                'Content-Type' => 'application/json',
            ]),
            $tampered,
        )->assertUnauthorized();

        $this->assertSame(0, EmailSuppression::query()->count());
    }

    /**
     * With no secret configured every webhook must fail. Accepting them would
     * be worse than rejecting them: an unverified caller could suppress
     * anyone.
     */
    public function test_webhooks_fail_closed_when_no_secret_is_configured(): void
    {
        config()->set('mail.mailers.zeptomail.webhook_secret', null);

        $this->deliver($this->event('hard_bounce'))->assertUnauthorized();
    }

    // ── suppression ───────────────────────────────────────────────────────

    public function test_a_hard_bounce_suppresses_the_address(): void
    {
        $this->deliver($this->event('hard_bounce', 'gone@example.com'))->assertOk();

        $suppression = EmailSuppression::query()->firstOrFail();

        $this->assertSame('gone@example.com', $suppression->email);
        $this->assertSame(EmailSuppression::REASON_HARD_BOUNCE, $suppression->reason);
        $this->assertTrue($suppression->isActive());
    }

    public function test_a_complaint_suppresses_the_address(): void
    {
        $this->deliver($this->event('feedback_loop', 'angry@example.com'))->assertOk();

        $this->assertSame(
            EmailSuppression::REASON_COMPLAINT,
            EmailSuppression::query()->firstOrFail()->reason,
        );
    }

    /**
     * A full mailbox clears on its own. Suppressing on the first soft bounce
     * would permanently stop writing to a customer whose inbox was briefly
     * full — losing orders to fix a problem that fixed itself.
     */
    public function test_a_soft_bounce_does_not_suppress(): void
    {
        $this->deliver($this->event('soft_bounce', 'full@example.com'))
            ->assertOk()
            ->assertJsonPath('data.status', 'ignored');

        $this->assertSame(0, EmailSuppression::query()->count());
    }

    public function test_opens_and_clicks_are_ignored(): void
    {
        $this->deliver($this->event('email opens'))->assertOk()->assertJsonPath('data.status', 'ignored');
        $this->deliver($this->event('email clicks'))->assertOk()->assertJsonPath('data.status', 'ignored');

        $this->assertSame(0, EmailSuppression::query()->count());
    }

    /** Providers retry; a duplicate must not fail on the unique index. */
    public function test_a_repeated_bounce_does_not_duplicate_the_suppression(): void
    {
        $this->deliver($this->event('hard_bounce', 'gone@example.com'))->assertOk();
        $this->deliver($this->event('hard_bounce', 'gone@example.com'))->assertOk();

        $this->assertSame(1, EmailSuppression::query()->count());
    }

    /** Suppression is a property of the mailbox, not of its capitalisation. */
    public function test_addresses_are_suppressed_case_insensitively(): void
    {
        $this->deliver($this->event('hard_bounce', 'Mixed.Case@Example.com'))->assertOk();

        $this->assertSame('mixed.case@example.com', EmailSuppression::query()->firstOrFail()->email);
    }

    public function test_a_payload_with_no_recipient_is_acknowledged_not_retried(): void
    {
        $this->deliver(['event_name' => 'hard_bounce', 'event_message' => []])
            ->assertOk()
            ->assertJsonPath('data.status', 'ignored');
    }

    // ── enforcement (§10d) ────────────────────────────────────────────────

    /**
     * The point of the list. A suppressed customer gets no confirmation — but
     * sales is STILL told the lead exists, because a bad email address must
     * not become a lost order.
     */
    public function test_a_suppressed_customer_still_produces_a_sales_notification(): void
    {
        Mail::fake();

        EmailSuppression::query()->create([
            'email' => 'buyer@example.com',
            'reason' => EmailSuppression::REASON_HARD_BOUNCE,
            'suppressed_at' => now(),
        ]);

        $product = Product::factory()->create(['status' => 'active']);

        $added = $this->withCredentials()->postJson('/api/v1/basket/items', ['slug' => $product->slug]);

        /*
         * The basket cookie has to be carried by hand: Laravel's JSON test
         * helpers send none without withCredentials(), and the test client
         * does not persist Set-Cookie between calls the way a browser does.
         * Without this the submission arrives with an empty basket and the
         * test passes for the wrong reason.
         */
        $cookie = collect($added->headers->getCookies())
            ->firstWhere(fn ($c): bool => $c->getName() === BasketSession::COOKIE);

        $this->withCredentials()
            ->withUnencryptedCookie(BasketSession::COOKIE, (string) $cookie?->getValue())
            ->postJson('/api/v1/quotes', [
                'email' => 'buyer@example.com',
                'phone' => '08012345678',
            ])->assertCreated();

        Mail::assertNotQueued(QuoteConfirmationMail::class);
        Mail::assertQueued(QuoteReceivedMail::class);

        $quote = Quote::query()->firstOrFail();

        $this->assertTrue(
            $quote->events()->where('type', 'confirmation_suppressed')->exists(),
            'The suppressed confirmation must be recorded on the quote, so sales phones instead.',
        );
    }
}
