<?php

declare(strict_types=1);

return [
    /*
    | A predictable admin path is free reconnaissance. Configurable so it can
    | be moved without a code change (MASTER BUILD PROMPT section 18).
    */
    'path' => env('ADMIN_PATH', 'admin'),

    /*
    | Optional IP allowlist. Empty means no restriction — appropriate while the
    | team works from changing addresses, and a meaningful hardening step once
    | office egress is stable. Comma-separated IPs or CIDR ranges.
    */
    'ip_allowlist' => array_values(array_filter(
        array_map('trim', explode(',', (string) env('ADMIN_IP_ALLOWLIST', '')))
    )),

    /*
    | Admin rate limit: attempts per minute per IP. Deliberately far below the
    | storefront's — this is the credentialed surface.
    */
    'rate_limit' => (int) env('ADMIN_RATE_LIMIT', 60),
];
