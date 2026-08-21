<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Mail\QuoteMailer;
use App\Quotes\AbuseGuard;
use App\Quotes\BasketSession;
use App\Quotes\QuoteSubmitter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Quote submission — the three-field form (D-004, §9d).
 *
 * Email, phone, optional message. No name, no company, no address, no account.
 * Every additional field is a reason not to submit, and sales can ask for
 * anything else in the reply they are about to send anyway.
 */
final class QuoteController extends Controller
{
    public function __construct(
        private readonly BasketSession $session,
        private readonly QuoteSubmitter $submitter,
        private readonly AbuseGuard $guard,
        private readonly QuoteMailer $mailer,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            /*
             * `email:rfc,dns` is deliberately NOT used. A DNS lookup on the
             * submit path adds latency the buyer waits through, fails for
             * legitimate domains behind slow resolvers, and rejects nothing an
             * attacker cannot trivially satisfy. A bounced confirmation is
             * cheaper and more honest than a rejected submission.
             */
            'email' => ['required', 'email:rfc', 'max:255'],

            /*
             * Loose on purpose. Nigerian numbers are written +234..., 0..., or
             * with spaces and dashes, and a salesperson can read any of them.
             * A strict pattern here rejects real customers to satisfy a format
             * nobody downstream depends on.
             */
            'phone' => ['required', 'string', 'min:7', 'max:32'],

            'message' => ['nullable', 'string', 'max:2000'],

            // Anti-abuse fields. Both optional: the form must work without
            // JavaScript, and a client that never ran the script sends neither.
            AbuseGuard::HONEYPOT => ['nullable', 'string', 'max:255'],
            AbuseGuard::TIMESTAMP => ['nullable', 'numeric'],
        ]);

        $basket = $this->session->resolve($request);

        if ($basket === null || $basket->items()->count() === 0) {
            return $this->error(
                'Your quote request is empty. Add the items you need and try again.',
                'BasketEmpty',
                409,
            );
        }

        $rejection = $this->guard->check($request, $data['email']);

        if ($rejection !== null) {
            /*
             * 429 rather than 422: this is a rate or abuse decision, not a
             * validation failure, and the storefront shows it differently.
             * The submission is NOT silently dropped — a real buyer caught by
             * a heuristic must know their request did not arrive.
             */
            return $this->error($rejection, 'QuoteRejected', 429);
        }

        try {
            $quote = $this->submitter->submit($basket, [
                'email' => $data['email'],
                'phone' => $data['phone'],
                'message' => $data['message'] ?? null,
            ], $this->attribution($request));
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage(), 'QuoteRejected', 422);
        }

        $this->guard->record($request, $data['email']);

        /*
         * After the quote is committed, never before. Both emails are queued
         * (section 10c), so a provider outage delays a receipt rather than
         * failing a submission we have already captured.
         */
        $this->mailer->sendForQuote($quote);

        /*
         * The basket cookie is cleared: the basket is submitted, and a buyer
         * who presses back should see an empty one rather than the request
         * they just sent.
         */
        return response()->json([
            'data' => [
                'reference' => $quote->reference,
                'email' => $quote->email,
                'submitted_at' => $quote->submitted_at?->toAtomString(),
                'line_count' => $quote->items()->count(),
                // Set the expectation the confirmation page repeats and the
                // announcement bar promises. One place, one promise.
                'response_expectation' => 'We respond within one business day.',
            ],
        ], 201)->withCookie($this->session->forgetCookie());
    }

    /**
     * Marketing attribution and abuse-investigation context.
     *
     * @return array<string, string|null>
     */
    private function attribution(Request $request): array
    {
        return [
            'utm_source' => $this->param($request, 'utm_source'),
            'utm_medium' => $this->param($request, 'utm_medium'),
            'utm_campaign' => $this->param($request, 'utm_campaign'),
            'utm_term' => $this->param($request, 'utm_term'),
            'utm_content' => $this->param($request, 'utm_content'),
            'referrer' => $this->param($request, 'referrer'),
            'landing_page' => $this->param($request, 'landing_page'),

            // Hashed, never raw — see QuoteSubmitter.
            'ip_hash' => hash('sha256', (string) $request->ip()),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
        ];
    }

    private function param(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return mb_substr(strip_tags(trim($value)), 0, 255);
    }

    private function error(string $message, string $code, int $status): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => [],
                'trace_id' => request()->attributes->get('trace_id'),
            ],
        ], $status);
    }
}
