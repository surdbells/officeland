<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Assigns a trace_id to every request and propagates it.
 *
 * The trace_id ties a storefront request to its API call, its queued jobs and
 * its log lines (MASTER BUILD PROMPT section 19.1). It is also returned in the
 * error envelope of section 8.3, so a customer reporting a failure gives
 * support a value that resolves directly to the log entry.
 *
 * An inbound X-Trace-Id is honoured when it looks like one of ours, so a trace
 * started by the storefront survives into the API. It is validated rather than
 * trusted: an unvalidated header would let a caller poison log correlation or
 * inject terminal control sequences into log output.
 */
final class TraceId
{
    public const HEADER = 'X-Trace-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $traceId = $this->resolve($request);

        // Context is Octane-safe: it is reset between requests, unlike a
        // static property, which section 9.1 forbids.
        Context::add('trace_id', $traceId);

        $request->attributes->set('trace_id', $traceId);

        $response = $next($request);
        $response->headers->set(self::HEADER, $traceId);

        return $response;
    }

    private function resolve(Request $request): string
    {
        $inbound = $request->headers->get(self::HEADER);

        if (is_string($inbound) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $inbound) === 1) {
            return strtolower($inbound);
        }

        return (string) Str::uuid();
    }
}
