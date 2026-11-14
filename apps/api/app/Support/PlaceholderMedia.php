<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Images that stand in for a missing product photograph.
 *
 * Icecat (and some supplier feeds) attach the brand's logo when they have no
 * photo of the product. Ingestion de-duplicates by checksum, so one logo file
 * ends up as a single media row attached to hundreds of products - which is
 * also how it is recognised: no real product photograph is shared by twenty
 * different products. Same rule as catalogue:audit-placeholder-images, which
 * detaches them for good; this is the read-side guard for merchandised
 * surfaces (the homepage) until that has been run, and for whatever the next
 * import brings in.
 */
final class PlaceholderMedia
{
    public const CACHE_KEY = 'catalogue:placeholder-media:v1';

    /** An image on this many products or more is a stand-in, not a photograph. */
    private const SHARED_BY = 20;

    /**
     * Media ids of stand-in images. Cached: the GROUP BY reads the whole pivot.
     *
     * @return list<int>
     */
    public static function ids(): array
    {
        return Cache::remember(self::CACHE_KEY, now()->addHour(), fn (): array => DB::table('product_media')
            ->select('media_id')
            ->groupBy('media_id')
            ->havingRaw('count(distinct product_id) >= ?', [self::SHARED_BY])
            ->pluck('media_id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all());
    }
}
