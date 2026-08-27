<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The admin panel must never be indexed (MASTER BUILD PROMPT section 18).
 *
 * Caddy already sets X-Robots-Tag on every non-production response. This
 * covers production, where the storefront is indexable and the admin panel
 * still must not be - and it is set in the application so it survives a
 * change of edge, a misconfigured proxy, or someone serving the panel from a
 * different host.
 */
final class NoIndexAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet');

        return $response;
    }
}
