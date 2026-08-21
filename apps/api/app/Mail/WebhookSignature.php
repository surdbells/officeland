<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * Verifies a ZeptoMail webhook signature (§10d).
 *
 * ── Verified against the live documentation ──────────────────────────────
 * Header  `producer-signature`
 * Format  `ts=<milliseconds>;s=<base64 HMAC-SHA256>;s-algorithm=HmacSHA256`
 *
 * The HMAC is computed over the raw request body using the Authentication Key
 * configured in ZeptoMail — a DIFFERENT secret from the send token. Reusing
 * the send token here fails every verification, silently, and the symptom is
 * bounces that never reach the suppression list.
 *
 * ── Why the raw body, not the decoded array ──────────────────────────────
 * JSON decoding and re-encoding does not round-trip byte-for-byte: key order,
 * unicode escaping and number formatting all change. The signature is over the
 * bytes that arrived, so those are the bytes that must be hashed.
 */
final readonly class WebhookSignature
{
    /**
     * How far out of date a signature may be.
     *
     * Bounds replay: a captured webhook cannot be resent days later to
     * suppress an address on demand. Five minutes is generous enough for
     * clock skew between two systems neither of which we control.
     */
    private const MAX_AGE_SECONDS = 300;

    public function __construct(private string $secret) {}

    /**
     * @param  string  $header  the raw `producer-signature` value
     * @param  string  $body  the raw request body, exactly as received
     */
    public function isValid(?string $header, string $body, ?int $nowMs = null): bool
    {
        if ($this->secret === '' || $header === null || $header === '') {
            /*
             * No secret configured means every webhook fails verification, and
             * that is the correct default: an unverified webhook can suppress
             * an arbitrary address, which is a denial-of-service against our
             * own customers.
             */
            return false;
        }

        $parts = $this->parse($header);

        $timestamp = $parts['ts'] ?? null;
        $signature = $parts['s'] ?? null;
        $algorithm = $parts['s-algorithm'] ?? null;

        if ($timestamp === null || $signature === null) {
            return false;
        }

        // Only the algorithm we verify. Accepting an unknown one and hoping
        // is how signature checks become decoration.
        if ($algorithm !== null && strcasecmp($algorithm, 'HmacSHA256') !== 0) {
            return false;
        }

        if (! $this->isFresh($timestamp, $nowMs ?? (int) (microtime(true) * 1000))) {
            return false;
        }

        $expected = base64_encode(hash_hmac('sha256', $body, $this->secret, true));

        // Constant time: a fast comparison leaks how much of the signature
        // matched, which is enough to forge one byte at a time.
        return hash_equals($expected, $signature);
    }

    private function isFresh(string $timestamp, int $nowMs): bool
    {
        if (! ctype_digit($timestamp)) {
            return false;
        }

        $ageSeconds = abs($nowMs - (int) $timestamp) / 1000;

        return $ageSeconds <= self::MAX_AGE_SECONDS;
    }

    /**
     * `ts=123;s=abc;s-algorithm=HmacSHA256` into its parts.
     *
     * @return array<string, string>
     */
    private function parse(string $header): array
    {
        $parts = [];

        foreach (explode(';', $header) as $segment) {
            $segment = trim($segment);

            if ($segment === '') {
                continue;
            }

            // Split on the FIRST '=' only: a base64 signature is very often
            // padded with '=' and splitting on all of them truncates it.
            $at = strpos($segment, '=');

            if ($at === false) {
                continue;
            }

            $parts[strtolower(substr($segment, 0, $at))] = substr($segment, $at + 1);
        }

        return $parts;
    }
}
