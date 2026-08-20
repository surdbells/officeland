<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    |
    | Content-addressed, never date-based. WordPress's /YYYY/MM/ layout is part
    | of what the legacy site is being replaced for: it makes deduplication
    | impossible and URLs mutable. Addressing by SHA-256 means a derivative URL
    | is immutable, which is what permits max-age=31536000, immutable at the
    | edge (MASTER BUILD PROMPT section 12).
    |
    */
    'root' => env('MEDIA_ROOT', '/app/storage-media'),

    'public_prefix' => '/media',

    /*
    |--------------------------------------------------------------------------
    | Derivatives
    |--------------------------------------------------------------------------
    |
    | 5 variants x 3 formats = 15 derivatives per original. At ~75,000
    | originals that is ~1.1M files and ~50 GB (docs/ARCHITECTURE.md 8).
    |
    | Ordered smallest first so a resumable job produces the useful sizes early:
    | if generation is interrupted, having thumbs and cards for everything beats
    | having every size for the first few hundred products.
    |
    */
    'variants' => [
        'thumb' => ['width' => 160],
        'card' => ['width' => 400],
        'pdp' => ['width' => 800],
        'og' => ['width' => 1200, 'height' => 630, 'crop' => true],
        'zoom' => ['width' => 1600],
    ],

    /*
    | AVIF first, WebP second, JPEG last — the <picture> fallback order of
    | section 12.5. Quality values are per-format because they are not
    | comparable: AVIF at 50 is visually close to JPEG at 82.
    */
    'formats' => [
        'avif' => ['quality' => 50, 'mime' => 'image/avif'],
        'webp' => ['quality' => 80, 'mime' => 'image/webp'],
        'jpeg' => ['quality' => 82, 'mime' => 'image/jpeg'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Upload validation
    |--------------------------------------------------------------------------
    |
    | MIME is determined by content, never by extension or by the client's
    | declared type — both are attacker-controlled (section 12.1).
    |
    | SVG is absent deliberately. It is an XML document that can carry script
    | and external references; accepting it means owning a sanitiser
    | indefinitely. Nothing in this catalogue needs vector uploads.
    |
    */
    'accepted_mimes' => [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/avif',
        'image/gif',
    ],

    'max_bytes' => 20 * 1024 * 1024,

    // Below this, an "image" is a tracking pixel or a broken download rather
    // than product photography.
    'min_dimension' => 200,

    /*
    |--------------------------------------------------------------------------
    | Generation
    |--------------------------------------------------------------------------
    */
    'queue' => env('MEDIA_QUEUE', 'media'),

    // libvips is already internally threaded. Letting each worker spawn threads
    // as well oversubscribes the CPU and makes a bulk run slower, not faster.
    'vips_concurrency' => (int) env('MEDIA_VIPS_CONCURRENCY', 1),

    // Shared with Icecat image mirroring, which is bounded by Icecat's own
    // 50-75 connection guidance (docs/ICECAT_MAPPING.md section 2).
    'download_concurrency' => (int) env('MEDIA_DOWNLOAD_CONCURRENCY', 50),
];
