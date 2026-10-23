<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Business timezone
    |--------------------------------------------------------------------------
    |
    | Used by the SLA clock (section 11c). Configured rather than inherited
    | from the server, because a server that moves region must not silently
    | change what "four working hours" means. The business, its customers and
    | its response promise are all in West Africa Time.
    |
    */
    'timezone' => env('QUOTES_TIMEZONE', 'Africa/Lagos'),

    /*
    |--------------------------------------------------------------------------
    | Routing (section 11d)
    |--------------------------------------------------------------------------
    |
    | Where quote notifications go. `default` is the fallback; `categories`
    | maps a department path prefix to an address, so furniture enquiries can
    | reach the furniture team without every salesperson reading everything.
    |
    | Matched on the PRIMARY category of the first line, by longest prefix.
    | A quote spanning departments has to land somewhere, and the first line
    | is what the buyer reached for first.
    |
    */
    'routing' => [
        // The fallback inbox for quote notifications, from the environment.
        // QUOTE_DEFAULT_RECIPIENTS is the intended knob; it falls back to the
        // sales/reply-to address and then the From address so a notification
        // always has somewhere to land, but never a hardcoded address.
        'default' => env('QUOTE_DEFAULT_RECIPIENTS')
            ?: env('MAIL_SALES_ADDRESS')
            ?: env('MAIL_FROM_ADDRESS'),

        'categories' => [
            // '/office-furniture/' => 'furniture@officelandng.com',
            // '/it-products/'      => 'it@officelandng.com',
        ],
    ],
];
