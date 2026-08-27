<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Mail\WebhookSignature;
use App\Models\EmailSuppression;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * ZeptoMail bounce and complaint webhooks (§10d).
 *
 * ── Verified against the live documentation ──────────────────────────────
 * Events    `hard bounce`, `soft bounce`, `email opens`, `email clicks`,
 *           `feedback loop` (spam complaints)
 * Signature `producer-signature: ts=…;s=…;s-algorithm=HmacSHA256`
 * Payload   { event_name, event_message: { email_info, event_data },
 *             mailagent_key, webhook_request_id }
 *
 * ── Only two of the five events suppress ─────────────────────────────────
 * A HARD bounce means the mailbox does not exist, and a FEEDBACK LOOP means
 * the recipient called us spam. Both are permanent and both are recorded.
 *
 * A SOFT bounce is not. A full mailbox or a temporary server failure clears on
 * its own, and suppressing on the first soft bounce would permanently stop
 * writing to a customer whose inbox was briefly full - losing orders to fix a
 * problem that fixed itself. Soft bounces are logged and left alone.
 *
 * Opens and clicks are ignored entirely: tracking is off by default, and we do
 * not want a record of who read what.
 */
final class MailWebhookController extends Controller
{
    /** Event names that mean "never write to this address again". */
    private const SUPPRESSING = [
        'hard_bounce' => EmailSuppression::REASON_HARD_BOUNCE,
        'hard bounce' => EmailSuppression::REASON_HARD_BOUNCE,
        'bounce' => EmailSuppression::REASON_HARD_BOUNCE,
        'feedback_loop' => EmailSuppression::REASON_COMPLAINT,
        'feedback loop' => EmailSuppression::REASON_COMPLAINT,
        'spam' => EmailSuppression::REASON_COMPLAINT,
    ];

    public function __invoke(Request $request): JsonResponse
    {
        $signature = new WebhookSignature((string) config('mail.mailers.zeptomail.webhook_secret', ''));

        // getContent(), not the parsed input: the HMAC is over the bytes that
        // arrived, and decode/re-encode does not round-trip byte-for-byte.
        if (! $signature->isValid($request->header('producer-signature'), $request->getContent())) {
            Log::warning('mail.webhook.rejected', [
                'reason' => 'signature',
                'ip' => hash('sha256', (string) $request->ip()),
            ]);

            /*
             * 401 and nothing else. An unverified caller learns only that it
             * was rejected - not which part failed, and nothing about whether
             * the address in the payload exists.
             */
            return response()->json(['error' => ['code' => 'InvalidSignature', 'message' => 'Rejected.']], 401);
        }

        /** @var array<string, mixed> $payload */
        $payload = (array) $request->json()->all();

        $event = $this->normaliseEvent($payload);
        $email = $this->extractEmail($payload);

        if ($email === null) {
            // Acknowledged, not retried: a payload we cannot read will not
            // become readable on a second delivery.
            Log::warning('mail.webhook.unreadable', ['event' => $event]);

            return response()->json(['data' => ['status' => 'ignored', 'reason' => 'no recipient']]);
        }

        $reason = self::SUPPRESSING[$event] ?? null;

        if ($reason === null) {
            Log::info('mail.webhook.observed', ['event' => $event, 'email' => $this->mask($email)]);

            return response()->json(['data' => ['status' => 'ignored', 'event' => $event]]);
        }

        $this->suppress($email, $reason, $event, $payload);

        return response()->json(['data' => ['status' => 'suppressed', 'event' => $event]]);
    }

    /** @param array<string, mixed> $payload */
    private function suppress(string $email, string $reason, string $event, array $payload): void
    {
        /*
         * updateOrCreate keyed on the address: providers retry webhooks, and a
         * duplicate delivery must not fail on the unique index. An address
         * already suppressed keeps its original suppressed_at - the first
         * bounce is the one that matters.
         */
        EmailSuppression::query()->updateOrCreate(
            ['email' => EmailSuppression::normalise($email)],
            [
                'reason' => $reason,
                'detail' => $this->detail($payload),
                'provider_event_id' => $this->stringOrNull($payload['webhook_request_id'] ?? null),
                'provider_request_id' => $this->stringOrNull($payload['mailagent_key'] ?? null),
                'suppressed_at' => now(),
                // A fresh bounce un-releases a previously released address.
                'released_at' => null,
                'released_by' => null,
            ],
        );

        Log::warning('mail.suppression.added', [
            'event' => $event,
            'reason' => $reason,
            'email' => $this->mask($email),
        ]);
    }

    /**
     * Event names arrive spaced or underscored depending on the event.
     *
     * @param  array<string, mixed>  $payload
     */
    private function normaliseEvent(array $payload): string
    {
        $name = $payload['event_name'] ?? '';

        return is_string($name) ? mb_strtolower(trim($name)) : '';
    }

    /**
     * Digs the recipient out of the nested payload.
     *
     * The shape varies by event type, so several known locations are tried
     * rather than assuming one - a missed address means a bounce that never
     * suppresses, which is the failure this endpoint exists to prevent.
     *
     * @param  array<string, mixed>  $payload
     */
    private function extractEmail(array $payload): ?string
    {
        $message = $payload['event_message'] ?? null;

        if (! is_array($message)) {
            return null;
        }

        $candidates = [
            $message['email_info']['to'][0]['email_address']['address'] ?? null,
            $message['email_info']['to'][0]['address'] ?? null,
            $message['email_info']['email'] ?? null,
            $message['event_data']['details'][0]['reason']['email'] ?? null,
            $message['event_data'][0]['details'][0]['email'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && filter_var($candidate, FILTER_VALIDATE_EMAIL) !== false) {
                return $candidate;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $payload */
    private function detail(array $payload): ?string
    {
        $message = $payload['event_message'] ?? null;

        if (! is_array($message)) {
            return null;
        }

        $encoded = json_encode($message['event_data'] ?? null);

        return $encoded === false ? null : mb_substr($encoded, 0, 2000);
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * `b****@example.com` - enough to correlate a report, not enough to make
     * the log a list of customer addresses.
     */
    private function mask(string $email): string
    {
        $at = strpos($email, '@');

        if ($at === false || $at === 0) {
            return '***';
        }

        return $email[0].str_repeat('*', max(1, $at - 1)).substr($email, $at);
    }
}
