<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\EmailSuppression;
use App\Models\Quote;
use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Facades\Log;

/**
 * Sends the two quote emails, and is the one place suppression is enforced.
 *
 * ── Why a service rather than Mail::to(...) at the call site ─────────────
 * Suppression has to be checked before every send, and a check that lives at
 * the call site is a check somebody will forget the next time a new email is
 * added. Routing every quote email through here means "never write to a
 * bounced address" is true by construction rather than by discipline.
 *
 * ── The internal notification is never suppressed by a customer bounce ───
 * The two messages have different recipients and different consequences. If a
 * customer's address hard-bounced, sales must STILL be told the lead exists —
 * they can phone. Dropping both would turn a bad email address into a lost
 * order, which is precisely backwards.
 */
final class QuoteMailer
{
    public function __construct(private readonly Mailer $mailer) {}

    /**
     * Queues both emails for a submitted quote.
     *
     * Failures are caught and logged rather than thrown. The quote is already
     * committed by the time this runs (§10c), and letting a mail failure
     * bubble into the submission response would tell a customer their request
     * failed when it did not.
     */
    public function sendForQuote(Quote $quote): void
    {
        $this->sendConfirmation($quote);
        $this->notifySales($quote);
    }

    private function sendConfirmation(Quote $quote): void
    {
        if ($this->isSuppressed($quote->email)) {
            /*
             * Recorded on the quote itself, not just in a log. Sales opening
             * this lead needs to know the customer never got a receipt, so
             * their first move is the phone rather than "did you not see my
             * email?".
             */
            $quote->events()->create([
                'type' => 'confirmation_suppressed',
                'actor_label' => 'system',
                'payload' => ['email' => $quote->email, 'reason' => 'suppression_list'],
            ]);

            Log::warning('mail.suppressed', [
                'template' => 'quote.confirmation',
                'reference' => $quote->reference,
            ]);

            return;
        }

        $this->dispatch($quote, new QuoteConfirmationMail($quote), $quote->email, 'quote.confirmation');
    }

    private function notifySales(Quote $quote): void
    {
        $recipient = (string) config('mail.sales_address', 'sales@officelandng.com');

        $this->dispatch($quote, new QuoteReceivedMail($quote), $recipient, 'quote.received');
    }

    private function dispatch(Quote $quote, Mailable $mailable, string $to, string $template): void
    {
        try {
            $this->mailer->to($to)->queue($mailable);

            $quote->events()->create([
                'type' => 'email_queued',
                'actor_label' => 'system',
                'payload' => ['template' => $template, 'to' => $to],
            ]);
        } catch (\Throwable $e) {
            /*
             * Queueing itself failing means Redis is unreachable, which is a
             * different and more serious problem than a provider rejection —
             * and one that will not retry, because nothing was enqueued.
             */
            $quote->events()->create([
                'type' => 'email_queue_failed',
                'actor_label' => 'system',
                'payload' => ['template' => $template, 'to' => $to, 'error' => $e->getMessage()],
            ]);

            Log::error('mail.queue_failed', [
                'template' => $template,
                'reference' => $quote->reference,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function isSuppressed(string $email): bool
    {
        return EmailSuppression::query()
            ->active()
            ->where('email', EmailSuppression::normalise($email))
            ->exists();
    }
}
