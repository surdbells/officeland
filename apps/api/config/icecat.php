<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Icecat ingestion (Phase 5b)
|--------------------------------------------------------------------------
|
| Credentials live only in the environment and are entered by the client on
| their own server (docs/BACKLOG.md, HANDOVER-phase-2). The development team
| never sees them. Access is by account credentials plus IP whitelisting or a
| dynamic token; a NON-whitelisted request returns 404, not 403, which looks
| exactly like a missing product - see docs/ICECAT_MAPPING.md §2.
|
| Tier is Open Icecat (D-022): access_level = freexml. Locale is en, metric -
| an imperial locale would silently return wrong specifications for Nigeria.
|
*/

return [
    // Account shortname / login and password. Sent as HTTP Basic auth to the
    // XML interface when both are present; otherwise the request relies on IP
    // whitelisting alone.
    'username' => env('ICECAT_USERNAME'),
    'password' => env('ICECAT_PASSWORD'),

    // Open Icecat is 'freexml'. Full Icecat uses a numeric level in the export
    // path; this value is substituted into the export URL verbatim.
    'access_level' => env('ICECAT_ACCESS_LEVEL', 'freexml'),

    // en + metric. See the locale note in docs/ICECAT_MAPPING.md §2.
    'lang' => env('ICECAT_LANG', 'en'),

    // Icecat allows 100 concurrent connections per IP and recommends 50-75; the
    // enrich command is sequential, so this is only an upper bound for any
    // future parallel sync.
    'max_connections' => (int) env('ICECAT_MAX_CONNECTIONS', 50),

    'endpoints' => [
        // Export by Icecat ID: {export}/{access_level}/{lang}/{id}.xml
        'export' => env('ICECAT_EXPORT_BASE', 'https://data.icecat.biz/export'),
        // Search by MPN+brand or GTIN.
        'search' => env('ICECAT_SEARCH_BASE', 'https://data.icecat.biz/xml_s3/xml_server3.cgi'),
    ],

    'timeout' => (int) env('ICECAT_TIMEOUT', 45),
];
