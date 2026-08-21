<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Strong ETags and cache headers on API reads (MASTER BUILD PROMPT 8.3).
 *
 * Catalogue reads:      public, max-age=60, stale-while-revalidate=600
 * Basket and quotes:    private, no-store
 *
 * The ETag matters more here than the max-age. Under D-009 the storefront is
 * prerendered, so these endpoints are consumed mostly by the render worker and
 * by client-side facet filtering — both of which repeat identical requests. A
 * 304 costs a hash comparison instead of a full serialisation.
 */
final class SetCachePolicy
{
    private const CATALOGUE_MAX_AGE = 60;

    private const CATALOGUE_SWR = 600;

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->isMethodCacheable()) {
            return $response;
        }

        if ($this->isPrivate($request)) {
            $response->headers->set('Cache-Control', 'private, no-store');

            return $response;
        }

        if ($response->getStatusCode() !== 200) {
            return $response;
        }

        $response->headers->set(
            'Cache-Control',
            sprintf(
                'public, max-age=%d, stale-while-revalidate=%d',
                self::CATALOGUE_MAX_AGE,
                self::CATALOGUE_SWR,
            ),
        );

        // Strong validator over the exact bytes returned. Weak ETags would
        // permit a proxy to serve a semantically-equivalent-but-different
        // payload, which breaks byte-level client caching for no benefit.
        $content = $response->getContent();

        if ($content === false || $content === '') {
            return $response;
        }

        $etag = '"'.hash('xxh128', $content).'"';
        $response->headers->set('ETag', $etag);

        if ($this->matches($request->headers->get('If-None-Match'), $etag)) {
            $response->setNotModified();
        }

        return $response;
    }

    /**
     * Per-visitor endpoints, which must never enter a shared cache.
     *
     * The basket is the important one and the easiest to miss: it is a GET, it
     * returns 200, and its response carries a Set-Cookie. Without this it
     * would be marked `public, max-age=60` like any other catalogue read, and
     * a shared cache could hand one visitor's basket — and their cookie — to
     * the next person who asked.
     */
    private function isPrivate(Request $request): bool
    {
        return $request->is('api/*/quote*', 'api/*/quotes*', 'api/*/basket', 'api/*/basket/*');
    }

    /**
     * RFC 9110: If-None-Match may carry a list, and `*` matches anything.
     * Comparing the raw header against a single ETag would fail every request
     * from a client that sends more than one.
     */
    private function matches(?string $header, string $etag): bool
    {
        if ($header === null || $header === '') {
            return false;
        }

        if (trim($header) === '*') {
            return true;
        }

        foreach (explode(',', $header) as $candidate) {
            $candidate = trim($candidate);

            // Tolerate a weak prefix from intermediaries that added one.
            if (str_starts_with($candidate, 'W/')) {
                $candidate = substr($candidate, 2);
            }

            if ($candidate === $etag) {
                return true;
            }
        }

        return false;
    }
}
