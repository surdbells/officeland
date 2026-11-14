<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Media;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Find "product photos" that are really a stand-in - a brand logo or a generic
 * placeholder the supplier feed served where it had no picture.
 *
 * Icecat answers a data-sheet with no product shot by sending the brand's logo
 * (the big blue HP disc) as the image. Media is content-addressed, so every one
 * of those products ends up pointing at the SAME media row. That is the tell: a
 * genuine product photograph belongs to one product, or to a handful of colour
 * or pack variants - never to dozens of unrelated items. An image shared past a
 * threshold is listed with sample names so it can be eyeballed, and --detach
 * removes it from those products so the storefront shows its honest "no photo
 * yet" state instead of a logo pretending to be the product.
 *
 *   php artisan catalogue:audit-placeholder-images                 # report
 *   php artisan catalogue:audit-placeholder-images --min=15        # stricter/looser
 *   php artisan catalogue:audit-placeholder-images --detach --apply
 */
final class AuditPlaceholderImages extends Command
{
    protected $signature = 'catalogue:audit-placeholder-images
        {--min=20 : An image attached to at least this many products is treated as a stand-in.}
        {--detach : Detach the flagged images from their products.}
        {--apply : Write (with --detach). Without it, nothing changes.}
        {--except=* : Media ids to leave alone (a real shared photo you have checked).}';

    protected $description = 'Find (and optionally detach) logo/placeholder images shared across many products';

    public function handle(): int
    {
        $min = max(2, (int) $this->option('min'));
        $except = array_map('intval', (array) $this->option('except'));

        $shared = DB::table('product_media')
            ->select('media_id', DB::raw('count(distinct product_id) as products'))
            ->groupBy('media_id')
            ->havingRaw('count(distinct product_id) >= ?', [$min])
            ->orderByDesc('products')
            ->get()
            ->reject(fn ($row): bool => in_array((int) $row->media_id, $except, true));

        if ($shared->isEmpty()) {
            $this->info("No image is shared by {$min} or more products.");

            return self::SUCCESS;
        }

        $rows = [];
        $totalProducts = 0;
        foreach ($shared as $row) {
            $media = Media::query()->find($row->media_id);
            $names = Product::query()
                ->whereIn('id', DB::table('product_media')->where('media_id', $row->media_id)->limit(3)->pluck('product_id'))
                ->pluck('name')
                ->map(fn (string $n): string => mb_strimwidth($n, 0, 38, '…'))
                ->implode(' | ');

            $rows[] = [
                $row->media_id,
                $row->products,
                $media ? "{$media->width}x{$media->height}" : '?',
                $media ? 'https://officelandng.com/media/'.ltrim((string) $media->path, '/') : '-',
                $names,
            ];
            $totalProducts += (int) $row->products;
        }

        $this->table(['Media', 'Products', 'Size', 'Image', 'Sample products'], $rows);
        $this->line(sprintf('%d shared image(s) across %d product attachments.', count($rows), $totalProducts));

        if (! $this->option('detach')) {
            $this->comment('Open the image URLs to confirm they are logos/placeholders, then re-run with --detach --apply (use --except=ID for any real shared photo).');

            return self::SUCCESS;
        }

        if (! $this->option('apply')) {
            $this->comment('Dry run. Add --apply to detach.');

            return self::SUCCESS;
        }

        $ids = $shared->pluck('media_id')->map(fn ($id): int => (int) $id)->all();
        $productIds = DB::table('product_media')->whereIn('media_id', $ids)->distinct()->pluck('product_id');
        $detached = DB::table('product_media')->whereIn('media_id', $ids)->delete();

        // A bulk update fires no model events, so pages are not re-rendered here:
        // bump updated_at for the sitemap lastmod and let render:full rebuild them.
        Product::query()->whereIn('id', $productIds)->update(['updated_at' => now()]);

        $this->info("Detached {$detached} stand-in image attachment(s) from {$productIds->count()} product(s).");
        $this->line('Then run catalogue:finalise-products --apply --index (a product left with no real photo drops out of the index until it gets one) and render:full.');

        return self::SUCCESS;
    }
}
