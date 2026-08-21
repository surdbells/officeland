<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Rate limits (Phase 13a)
    |--------------------------------------------------------------------------
    |
    | Per minute, per client IP. Set deliberately per surface rather than as one
    | global number, because the surfaces cost wildly different amounts:
    |
    |   catalogue  cheap, ETagged, and mostly consumed by the render worker
    |   search     the most expensive query in the system — FTS, then a trigram
    |              fallback, then facet aggregation. The cheapest way to take
    |              the site down, if it is left open.
    |   basket     writes rows. Unthrottled, it is a storage-exhaustion vector
    |              that costs an attacker one request per row.
    |   webhook    unauthenticated by necessity (section 10d). The signature
    |              check is the security boundary, but an attacker can still
    |              make us compute HMACs, so the volume is capped.
    |
    | Quote SUBMISSION is not here: it has its own tighter, longer-window guard
    | in App\Quotes\AbuseGuard (10/hour per IP, 5/hour per email), because the
    | thing being protected there is the sales team's inbox rather than the
    | server.
    |
    */
    'rate_limits' => [
        'catalogue' => (int) env('API_RATE_CATALOGUE', 300),
        'search' => (int) env('API_RATE_SEARCH', 60),
        'basket' => (int) env('API_RATE_BASKET', 60),
        'webhook' => (int) env('API_RATE_WEBHOOK', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Internal callers exempt from rate limiting
    |--------------------------------------------------------------------------
    |
    | The render worker walks every route in the manifest during a full build —
    | roughly 15,150 requests at launch scale, far above any limit a human
    | should ever reach. Throttling it would turn a routine template fix into a
    | half-finished site, so internal traffic is exempt.
    |
    | ── Why private ranges are a safe default ────────────────────────────────
    | PHP runs INSIDE Caddy under FrankenPHP, so there is no proxy hop in front
    | of the application and `$request->ip()` is the real client socket address.
    | An internet client cannot present a private source address to it. If a
    | load balancer is ever put in front, it must be added to Caddy's
    | `trusted_proxies` AND its address must not be private, or this exemption
    | would hand the limit-free path to everyone.
    |
    | Prefixes are matched literally against the start of the address, which is
    | enough for the container network this is protecting and avoids pulling in
    | a CIDR library for four constants.
    |
    */
    'trusted_prefixes' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('API_TRUSTED_PREFIXES', '127.,10.,172.,192.168.,::1')),
    ))),
];
