<?php

declare(strict_types=1);

namespace App\Icecat;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use RuntimeException;
use Throwable;

/**
 * Fetches a product's XML from the Icecat interface.
 *
 * Three lookup keys, in the order docs/ICECAT_MAPPING.md §3 establishes:
 * Icecat ID (cheapest re-fetch) → MPN + brand (primary match) → GTIN/EAN. Each
 * returns the parsed {@see IcecatDocument} or null when Icecat has nothing.
 *
 * ── 404 is ambiguous on purpose ──────────────────────────────────────────
 * Icecat returns 404 both for a genuinely unknown product AND for a request
 * from a non-whitelisted IP (§2). The client cannot tell them apart from one
 * response, so it treats 404 as "not found" and leaves it to the caller to
 * notice that EVERY lookup 404s - the signature of a whitelist/credentials
 * problem rather than a thin catalogue. {@see sawOnlyNotFound()} exposes that.
 */
final class IcecatClient
{
    /** Whether any lookup has returned something other than a 404/empty miss. */
    private bool $hadNonMiss = false;

    public function __construct(private readonly HttpFactory $http) {}

    /** Export by Icecat's own product ID: the cheapest re-fetch. */
    public function fetchById(int $icecatId): ?IcecatDocument
    {
        $base = rtrim((string) config('icecat.endpoints.export'), '/');
        $level = (string) config('icecat.access_level');
        $lang = (string) config('icecat.lang');

        return $this->get("{$base}/{$level}/{$lang}/{$icecatId}.xml");
    }

    /** The primary match: manufacturer part number scoped to its brand. */
    public function fetchByMpn(string $mpn, string $brand): ?IcecatDocument
    {
        return $this->get((string) config('icecat.endpoints.search'), [
            'lang' => (string) config('icecat.lang'),
            'prod_id' => $mpn,
            'vendor' => $brand,
            'output' => 'productxml',
        ]);
    }

    /** GTIN/EAN/UPC fallback when MPN + brand found nothing. */
    public function fetchByEan(string $ean): ?IcecatDocument
    {
        return $this->get((string) config('icecat.endpoints.search'), [
            'lang' => (string) config('icecat.lang'),
            'ean_upc' => $ean,
            'output' => 'productxml',
        ]);
    }

    /**
     * True when every lookup so far has missed (404 or empty). When a whole run
     * ends this way, the likeliest cause is the IP is not whitelisted or the
     * credentials are wrong - not that the catalogue is entirely absent from
     * Icecat (§2).
     */
    public function sawOnlyNotFound(): bool
    {
        return ! $this->hadNonMiss;
    }

    /**
     * @param  array<string, string>  $query
     */
    private function get(string $url, array $query = []): ?IcecatDocument
    {
        $response = $this->request()->get($url, $query);

        // 404 is a miss (unknown product OR non-whitelisted IP). Anything else
        // in the 4xx/5xx range is a real error worth surfacing.
        if ($response->status() === 404) {
            return null;
        }

        if (! $response->successful()) {
            throw new RuntimeException(
                "Icecat returned {$response->status()} for {$url}. ".
                'Check credentials and that this server\'s IP is whitelisted.'
            );
        }

        $body = trim($response->body());

        if ($body === '') {
            return null;
        }

        $document = IcecatDocument::fromXml($body);

        // The XML interface answers a miss with a 200 whose body carries an
        // <ICECAT-interface> error code rather than a <Product>. Treat that as
        // not-found too.
        if ($document === null) {
            return null;
        }

        $this->hadNonMiss = true;

        return $document;
    }

    private function request(): PendingRequest
    {
        $request = $this->http
            ->timeout((int) config('icecat.timeout', 45))
            // Retry with EXPONENTIAL backoff, and only on the errors worth
            // retrying - a 429 (rate limit) or a 5xx/timeout. A 404 is a
            // definitive "not in this tier" and must not be retried; the retry
            // callback below returns false for it so the miss is fast. When the
            // server sends Retry-After on a 429, honour it.
            ->retry(3, function (int $attempt, Throwable $exception): int {
                $response = $exception instanceof RequestException ? $exception->response : null;
                $retryAfter = (int) ($response?->header('Retry-After') ?: 0);

                return $retryAfter > 0
                    ? min($retryAfter, 60) * 1000
                    : (int) (min(2 ** $attempt, 30) * 1000);
            }, function (Throwable $exception): bool {
                $status = $exception instanceof RequestException ? (int) $exception->response->status() : 0;

                // 429 and 5xx are transient; connection errors (status 0) too.
                return $status === 429 || $status >= 500 || $status === 0;
            }, throw: false)
            ->acceptJson() // harmless; the server returns XML regardless
            ->withHeaders(['Accept' => 'application/xml, text/xml, */*']);

        $username = (string) config('icecat.username');
        $password = (string) config('icecat.password');

        // Basic auth when credentials are configured; otherwise the request
        // relies on IP whitelisting alone, which is a valid Icecat setup.
        if ($username !== '') {
            $request = $request->withBasicAuth($username, $password);
        }

        return $request;
    }
}
