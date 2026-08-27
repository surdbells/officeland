<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Quote;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * The customer's confirmation receipt (§10e, D-006).
 *
 * ── HTML body, no PDF ────────────────────────────────────────────────────
 * D-006 removed Dompdf. The full quote detail lives in the email body itself,
 * which is better anyway: it is searchable in the customer's inbox, readable on
 * a phone without downloading anything, and does not depend on a PDF renderer
 * staying patched.
 *
 * ── Why this is queued ───────────────────────────────────────────────────
 * §10c: never inline in a web request. A provider timeout would otherwise make
 * the buyer wait, and a provider outage would fail the submission itself -
 * losing a lead we had already captured. The quote is committed first and the
 * email follows.
 *
 * ── The reference header ─────────────────────────────────────────────────
 * Carried into ZeptoMail's `client_reference`, so their logs can be searched
 * by the same string the customer quotes on the phone.
 */
final class QuoteConfirmationMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    /**
     * Five attempts with exponential backoff (§10c).
     *
     * The delays are deliberately long: a provider blip resolves in seconds,
     * but a DNS or reputation problem takes minutes to tens of minutes, and
     * retrying five times inside a minute just burns the attempts before the
     * problem has had a chance to clear.
     */
    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 60, 300, 900];

    public function __construct(public readonly Quote $quote) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Your quote request {$this->quote->reference}",

            /*
             * Replies go to sales, not to a no-reply address. A customer
             * replying to a receipt with "actually, make that twenty" is a
             * good outcome, and bouncing it would be a self-inflicted lost
             * order.
             */
            replyTo: [config('mail.sales_address', 'sales@officelandng.com')],
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.quote.confirmation',
            with: [
                'quote' => $this->quote,
                'items' => $this->quote->items,
            ],
        );
    }

    public function headers(): Headers
    {
        return new Headers(
            text: [ZeptoMailApiTransport::REFERENCE_HEADER => $this->quote->reference],
        );
    }
}
