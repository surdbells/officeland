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
 * The internal notification (§10e).
 *
 * This is the message that turns a database row into somebody's job. The
 * business promises a response within one business day, and that promise is
 * kept by this email arriving — so it is deliberately plain, complete, and
 * actionable from a phone.
 *
 * Reply-to is the CUSTOMER, so a salesperson can answer the lead by hitting
 * reply rather than copying an address out of the body. That single detail
 * removes the most common source of transcription errors in the pipeline.
 */
final class QuoteReceivedMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 60, 300, 900];

    public function __construct(public readonly Quote $quote) {}

    public function envelope(): Envelope
    {
        $lines = $this->quote->items()->count();

        return new Envelope(
            // The subject is the triage surface: reference, size and who from,
            // so a phone notification is enough to decide whether to act now.
            subject: sprintf(
                'New quote %s — %d line%s from %s',
                $this->quote->reference,
                $lines,
                $lines === 1 ? '' : 's',
                $this->quote->email,
            ),
            replyTo: [$this->quote->email],
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.quote.received',
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
