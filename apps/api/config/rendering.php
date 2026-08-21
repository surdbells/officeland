<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Render worker
    |--------------------------------------------------------------------------
    |
    | Where the Angular server bundle is reachable. The queue consumer asks it
    | for a route's HTML and writes the result into the static root; only the
    | Angular bundle can produce the markup, and only this side should be
    | touching the filesystem Caddy serves.
    |
    */
    'worker_origin' => env('RENDER_WORKER_ORIGIN', 'http://render:4000'),

    'timeout' => (int) env('RENDER_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | Static root
    |--------------------------------------------------------------------------
    |
    | The directory Caddy serves (docs/ARCHITECTURE.md section 4). Shared with
    | the render container through the `static` volume.
    |
    */
    'static_root' => env('STATIC_ROOT', '/app/static'),
];
