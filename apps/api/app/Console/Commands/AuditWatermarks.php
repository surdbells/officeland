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
 *   php artisan catalogue:audit-watermarks --csv=storage/app/watermarks.csv  # shortlist for review
 *
 * Confidence in the CSV: "high" when the second Snimay badge (bottom-right) is
 * also present or the product is known Snimay stock (source, brand, name, or
 * the PVC doors department); "review" otherwise - those are the red-logo
 * products (Maped, Norton…) that trip the corner check without a watermark,
 * and must not be sent for AI removal unchecked.
 */
final class AuditWatermarks extends Command
{
    protected $signature = 'catalogue:audit-watermarks
        {--category= : Limit to a category subtree by path prefix.}
        {--clear : Detach the flagged image from each product (otherwise read-only).}
        {--all : Also scan Icecat products (skipped by default - they are clean).}
        {--samples=25 : How many flagged products to list.}
        {--limit=0 : Cap how many products to scan (0 = all).}
        {--csv= : Write every flagged product to this CSV (slug, brand, source, confidence, image URL) for review.}';

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
        $all = (bool) $this->option('all');

        $base = Product::query()
            ->whereHas('media')
            // Icecat products use clean syndicated photography; skipping them
            // keeps the scan to the legacy and manually imported products where
            // the watermark actually lives - hundreds, not fifteen thousand.
            ->when(! $all, fn (Builder $q) => $q->whereNull('icecat_id'))
            ->when($category !== '', fn (Builder $q) => $q->whereHas(
                'categories',
                fn (Builder $c) => $c->where('path', 'like', $category.'%'),
            ));

        $total = (clone $base)->count();
        if ($limit > 0) {
            $total = min($total, $limit);
        }

        if ($total === 0) {
            $this->info('No products to scan'.($all ? '.' : ' (Icecat products are skipped; use --all to include them).'));

            return self::SUCCESS;
        }

        $this->info("Scanning {$total} product image(s) for the Snimay watermark…");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $scanned = 0;
        $flagged = 0;
        $missing = 0;
        $cleared = 0;
        $sampleList = [];
        $rows = [];
        $csv = (string) $this->option('csv');
        $samples = (int) $this->option('samples');

        $base->with(['primaryCategory', 'media' => fn ($q) => $q->with('derivatives')->orderByDesc('product_media.role')])
            ->orderBy('id')
            ->chunkById($limit > 0 ? min($limit, 200) : 200, function ($products) use ($storage, $clear, $bar, &$scanned, &$flagged, &$missing, &$cleared, &$sampleList, &$rows, $csv, $samples, $limit): bool {
            foreach ($products as $product) {
                if ($limit > 0 && $scanned >= $limit) {
                    return false;
                }
                $scanned++;
                $bar->advance();

                $media = $product->media->first();
                if ($media === null) {
                    continue;
                }

                // Sample the smallest derivative when there is one (fast); the
                // original otherwise. The watermark survives the downscale.
                $path = $this->sampleFile($storage, $media);
                if ($path === null) {
                    $missing++;

                    continue;
                }

                if (! $this->looksWatermarked($path)) {
                    continue;
                }

                $flagged++;
                if ($csv !== '') {
                    $rows[] = $this->csvRow($product, $media, $path);
                }
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

        $bar->finish();
        $this->newLine(2);
        $this->table(['Outcome', 'Count'], [
            ['Products scanned', $scanned],
            ['Watermark detected', $flagged],
            [$clear ? 'Images cleared' : 'Would clear (run --clear)', $clear ? $cleared : $flagged],
            ['Original missing on disk', $missing],
        ]);

        if ($csv !== '') {
            $this->writeCsv($csv, $rows);
        }

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
    /**
     * The file to inspect: the smallest JPEG derivative (fast, still carries the
     * watermark) if the media has derivatives, otherwise the original. Null when
     * no readable file exists on disk.
     */
    private function sampleFile(MediaStorage $storage, \App\Models\Media $media): ?string
    {
        $derivative = $media->derivatives
            ->where('format', 'jpeg')
            ->sortBy('width')
            ->first();

        if ($derivative !== null) {
            $path = $storage->absolute($derivative->path);
            if (is_file($path)) {
                return $path;
            }
        }

        $original = $storage->absolute($media->path);

        return is_file($original) ? $original : null;
    }

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

        // The mark is a red "SNIMAY" badge over the TOP-LEFT of a photo AND a
        // second red badge along the BOTTOM, with the product scene (non-red) in
        // between. Requiring all three separates a watermarked photo from a
        // red-packaged product (a red box or board is red in the centre too).
        $topLeft = $this->redFraction($image, 0.015, 0.17, 0.04, 0.15);
        $centre = $this->redFraction($image, 0.28, 0.72, 0.30, 0.70);

        imagedestroy($image);

        // Red badge in the corner, over a non-red product scene. A red-packaged
        // product is red in the centre too, so it is excluded.
        return $topLeft >= 0.25 && $centre < 0.10;
    }

    /**
     * One shortlist row for a flagged product.
     *
     * @return array<string, string>
     */
    private function csvRow(Product $product, \App\Models\Media $media, string $path): array
    {
        $brand = (string) ($product->brand_name_cached ?? '');
        $category = (string) ($product->primaryCategory?->path ?? '');

        // Known Snimay stock: the importer's own source tag on the image or the
        // product, the brand or the name, or the PVC doors department the
        // Snimay catalogue was filed under.
        $knownSnimay = $media->source === 'snimay'
            || $product->source === 'snimay'
            || stripos($brand, 'snimay') !== false
            || stripos((string) $product->name, 'snimay') !== false
            || str_starts_with($category, '/pvc-doors-interiors/');

        $bottomBadge = $this->hasBottomBadge($path);

        return [
            'product_id' => (string) $product->id,
            'slug' => (string) $product->slug,
            'name' => (string) $product->name,
            'brand' => $brand,
            'product_source' => (string) ($product->source ?? ''),
            'image_source' => (string) ($media->source ?? ''),
            'category' => $category,
            'bottom_badge' => $bottomBadge ? 'yes' : 'no',
            'confidence' => ($bottomBadge || $knownSnimay) ? 'high' : 'review',
            'url' => 'https://officelandng.com/p/'.$product->slug.'/',
            'image' => 'https://officelandng.com/media/'.ltrim((string) $media->path, '/'),
        ];
    }

    /**
     * The second Snimay mark: a small red "SNIMAY" badge at the bottom right,
     * beside the snimayhome.com URL (measured at x 0.84-0.93, y 0.90-0.92 of the
     * frame). A red-logo product has no reason to carry red there as well.
     */
    private function hasBottomBadge(string $path): bool
    {
        $data = @file_get_contents($path);
        $image = $data === false ? false : @imagecreatefromstring($data);
        if ($image === false) {
            return false;
        }

        $fraction = $this->redFraction($image, 0.80, 0.96, 0.86, 0.95);
        imagedestroy($image);

        return $fraction >= 0.05;
    }

    /** @param  list<array<string, string>>  $rows */
    private function writeCsv(string $file, array $rows): void
    {
        $handle = @fopen($file, 'w');
        if ($handle === false) {
            $this->error("Could not write {$file}.");

            return;
        }

        if ($rows !== []) {
            fputcsv($handle, array_keys($rows[0]), escape: '');
        }
        foreach ($rows as $row) {
            fputcsv($handle, $row, escape: '');
        }
        fclose($handle);

        $high = count(array_filter($rows, fn (array $r): bool => $r['confidence'] === 'high'));
        $this->info(sprintf(
            'Wrote %d flagged product(s) to %s - %d high-confidence Snimay, %d to review by eye.',
            count($rows), $file, $high, count($rows) - $high,
        ));
    }

    /** The fraction of Snimay-badge-red pixels in a relative rectangle. */
    private function redFraction(\GdImage $image, float $fx0, float $fx1, float $fy0, float $fy1): float
    {
        $w = imagesx($image);
        $h = imagesy($image);

        $x0 = (int) ($w * $fx0);
        $x1 = (int) ($w * $fx1);
        $y0 = (int) ($h * $fy0);
        $y1 = (int) ($h * $fy1);

        $red = 0;
        $total = 0;

        for ($x = $x0; $x < $x1; $x++) {
            for ($y = $y0; $y < $y1; $y++) {
                $rgb = imagecolorat($image, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                $total++;

                if ($r > 140 && $g < 75 && $b < 75 && ($r - $g) > 70) {
                    $red++;
                }
            }
        }

        return $total === 0 ? 0.0 : $red / $total;
    }
}
