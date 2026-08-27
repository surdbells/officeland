<?php

declare(strict_types=1);

namespace App\Quotes;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Anti-abuse for the quote form (§9e).
 *
 * The requirement is "invisible to legitimate users", which rules out a
 * challenge on every submission. These four checks cost a real buyer nothing:
 *
 *   1. honeypot     - a field a human never sees and never fills
 *   2. timing       - a form submitted faster than it can be read
 *   3. per-IP rate  - one source flooding the pipeline
 *   4. per-email    - one address submitting repeatedly
 *
 * ── What this deliberately does NOT do ───────────────────────────────────
 * It does not silently discard. A rejected submission returns an error the
 * caller can see, because the alternative - accepting and dropping - means a
 * real buyer whose submission tripped a heuristic believes their request was
 * sent and waits for a reply that will never come. That is the worst failure
 * this form has, and it is unrecoverable because nobody knows it happened.
 *
 * ── Captcha (Q16) ────────────────────────────────────────────────────────
 * Deliberately absent, with a seam left for it. A captcha is a real cost to
 * every legitimate buyer on a slow Nigerian mobile connection, and it should
 * only be added if these cheaper checks prove insufficient against real
 * traffic. Q16 is the client's decision; the hook is {@see challengePassed()}.
 */
final class AbuseGuard
{
    /**
     * The honeypot field name.
     *
     * Innocuous enough that a naive bot fills it, and not so tempting that a
     * password manager or a browser autofill does - which is why it is not
     * called "email", "name" or "company".
     */
    public const HONEYPOT = 'website_url';

    /** The hidden timestamp the form renders when it loads. */
    public const TIMESTAMP = 'form_loaded_at';

    /**
     * A form submitted faster than this was not read.
     *
     * Three seconds is deliberately generous. Someone who has already typed
     * their details and is pressing submit on a fast connection can be quick,
     * and a false positive here rejects a real order.
     */
    private const MIN_SECONDS = 3;

    /**
     * And one submitted after this has been sitting open all day - a stale tab,
     * or a replayed timestamp. Not a rejection on its own; see check().
     */
    private const MAX_SECONDS = 86400;

    private const IP_ATTEMPTS = 10;

    private const IP_WINDOW = 3600;

    private const EMAIL_ATTEMPTS = 5;

    private const EMAIL_WINDOW = 3600;

    /**
     * @return string|null the reason to reject, or null to accept
     */
    public function check(Request $request, string $email): ?string
    {
        if ($this->honeypotFilled($request)) {
            /*
             * A human never sees this field. The message is deliberately
             * generic: telling a bot operator exactly which check caught them
             * is free tuning information.
             */
            return 'This request could not be accepted. Please call us on +234 707 472 3165.';
        }

        if ($this->tooFast($request)) {
            return 'That was submitted unusually quickly. Please try again.';
        }

        if (! $this->withinIpLimit($request)) {
            return 'Too many requests from this connection. Please try again later, or call us.';
        }

        if (! $this->withinEmailLimit($email)) {
            return 'Several requests have already been sent from this address. Please check your inbox, or call us.';
        }

        if (! $this->challengePassed($request)) {
            return 'Please complete the verification and try again.';
        }

        return null;
    }

    /**
     * Records a successful submission against both limits.
     *
     * Called only on success, so a rejected attempt does not consume a real
     * buyer's allowance - otherwise a few typos would lock someone out.
     */
    public function record(Request $request, string $email): void
    {
        RateLimiter::hit($this->ipKey($request), self::IP_WINDOW);
        RateLimiter::hit($this->emailKey($email), self::EMAIL_WINDOW);
    }

    private function honeypotFilled(Request $request): bool
    {
        return trim((string) $request->input(self::HONEYPOT, '')) !== '';
    }

    private function tooFast(Request $request): bool
    {
        $loadedAt = $request->input(self::TIMESTAMP);

        /*
         * A missing timestamp is NOT a rejection. The form works without
         * JavaScript, and a submission from a client that never ran the script
         * is exactly the traffic this site must not turn away.
         */
        if (! is_numeric($loadedAt)) {
            return false;
        }

        $elapsed = now()->getTimestamp() - (int) $loadedAt;

        // A negative or absurd age means a tampered value, which is a signal.
        if ($elapsed < 0 || $elapsed > self::MAX_SECONDS) {
            return true;
        }

        return $elapsed < self::MIN_SECONDS;
    }

    private function withinIpLimit(Request $request): bool
    {
        return ! RateLimiter::tooManyAttempts($this->ipKey($request), self::IP_ATTEMPTS);
    }

    private function withinEmailLimit(string $email): bool
    {
        return ! RateLimiter::tooManyAttempts($this->emailKey($email), self::EMAIL_ATTEMPTS);
    }

    /**
     * The captcha seam (Q16).
     *
     * Returns true while no provider is configured, so the form stays
     * frictionless by default. Wiring a provider here is the whole change:
     * nothing else in the submission path needs to know.
     */
    private function challengePassed(Request $request): bool
    {
        if (config('services.captcha.provider') === null) {
            return true;
        }

        // A provider is configured but unimplemented: fail CLOSED rather than
        // pretending the check ran.
        return false;
    }

    /**
     * Keyed on a HASH of the IP, not the address.
     *
     * The rate limiter's keys live in Redis and are logged when they trip;
     * neither needs to hold a customer's IP address in the clear. The hash
     * groups a source just as well.
     */
    private function ipKey(Request $request): string
    {
        return 'quote:ip:'.hash('sha256', (string) $request->ip());
    }

    private function emailKey(string $email): string
    {
        return 'quote:email:'.hash('sha256', mb_strtolower(trim($email)));
    }
}
