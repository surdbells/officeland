<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Media\MediaStorage;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Find product images that carry the Snimay watermark.
 *
 * Snimay's door/windows photos are stamped with a solid-red "SNIMAY" badge in
 * the top-left corner (and a URL along the bottom). Legacy products imported
 * from the old catalogue reused those promo images, so the mark is now live on
 * the storefront. This samples the top-left corner of each product's primary
 * image and flags the ones whose corner is largely that Snimay red - a reliable
 * signal for the badge without trying to read the text.
 *
 * Read-only by default (an audit). --clear detaches the flagged image so the
 * product falls back to its placeholder until a clean photo is added; the media
 * row and file are left for media:reap to remove if nothing else uses them.
 *
 *   php artisan catalogue:audit-watermarks
 *   php artisan catalogue:audit-watermarks --category=/pvc-doors-interiors/
 *   php artisan catalogue:audit-watermarks --clear        # detach flagged images
 */
final class AuditWatermarks extends Command
{
    protected $signature = 'catalogue:audit-watermarks
        {--category= : Limit to a category subtree by path prefix.}
        {--clear : Detach the flagged image from each product (otherwise read-only).}
        {--samples=25 : How many flagged products to list.}
        {--limit=0 : Cap how many products to scan (0 = all).}';

    protected $description = 'Flag (and optionally clear) product images carrying the Snimay watermark';

    public function handle(MediaStorage $storage): int
    {
        @ini_set('memory_limit', '512M');

        if (! function_exists('imagecreatefromstring')) {
            $this->error('GD is not available; cannot inspect images.');

            return self::FAILURE;
        }

        $clear = (bool) $this->option('clear');
        $limit = (int) $this->option('limit');
        $category = (string) $this->option('category');

        $query = Product::query()
            ->whereHas('media')
            ->when($category !== '', fn (Builder $q) => $q->whereHas(
                'categories',
                fn (Builder $c) => $c->where('path', 'like', $category.'%'),
            ))
            ->with(['media' => fn ($q) => $q->orderByDesc('product_media.role')])
            ->orderBy('id');

        $scanned = 0;
        $flagged = 0;
        $missing = 0;
        $cleared = 0;
        $sampleList = [];
        $samples = (int) $this->option('samples');

        $query->chunkById($limit > 0 ? min($limit, 200) : 200, function ($products) use ($storage, $clear, &$scanned, &$flagged, &$missing, &$cleared, &$sampleList, $samples, $limit): bool {
            foreach ($products as $product) {
                if ($limit > 0 && $scanned >= $limit) {
                    return false;
                }
                $scanned++;

                $media = $product->media->first();
                if ($media === null) {
                    continue;
                }

                $path = $storage->absolute($media->path);
                if (! is_file($path)) {
                    $missing++;

                    continue;
                }

                if (! $this->looksWatermarked($path)) {
                    continue;
                }

                $flagged++;
                if (count($sampleList) < $samples) {
                    $sampleList[] = '/p/'.$product->slug.'/';
                }

                if ($clear) {
                    $product->media()->detach($media->id);
                    $cleared++;
                }
            }

            return true;
        }, 'id');

        $this->newLine();
        $this->table(['Outcome', 'Count'], [
            ['Products scanned', $scanned],
            ['Watermark detected', $flagged],
            [$clear ? 'Images cleared' : 'Would clear (run --clear)', $clear ? $cleared : $flagged],
            ['Original missing on disk', $missing],
        ]);

        if ($sampleList !== []) {
            $this->newLine();
            $this->line('Flagged products (spot-check on the site):');
            foreach ($sampleList as $slug) {
                $this->line('  https://officelandng.com'.$slug);
            }
        }

        if (! $clear && $flagged > 0) {
            $this->newLine();
            $this->comment('Read-only audit. Re-run with --clear to detach the watermarked images.');
        }

        return self::SUCCESS;
    }

    /**
     * True when the image's top-left corner is largely the solid Snimay red -
     * the badge. Sampled on a coarse grid so it is cheap across the catalogue.
     */
    private function looksWatermarked(string $path): bool
    {
        $data = @file_get_contents($path);
        if ($data === false) {
            return false;
        }

        $image = @imagecreatefromstring($data);
        if ($image === false) {
            return false;
        }

        $w = imagesx($image);
        $h = imagesy($image);

        // The badge sits just inside the top-left corner. Sample a band there.
        $x0 = (int) ($w * 0.015);
        $x1 = (int) ($w * 0.17);
        $y0 = (int) ($h * 0.04);
        $y1 = (int) ($h * 0.15);

        $red = 0;
        $total = 0;

        for ($x = $x0; $x < $x1; $x += 2) {
            for ($y = $y0; $y < $y1; $y += 2) {
                $rgb = imagecolorat($image, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                $total++;

                // The Snimay badge red is a strong, desaturated-dark red.
                if ($r > 140 && $g < 75 && $b < 75 && ($r - $g) > 70) {
                    $red++;
                }
            }
        }

        imagedestroy($image);

        if ($total === 0) {
            return false;
        }

        // A real badge fills a solid block of that corner band.
        return ($red / $total) >= 0.28;
    }
}
