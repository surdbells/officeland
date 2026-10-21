<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;

/**
 * Trim every product down to at most N images (default 3).
 *
 * The import cap only applies to new products; brands imported earlier may carry
 * up to ten. This brings them into line. It DETACHES the surplus from the
 * product (removes the product_media pivot) rather than deleting the Media rows:
 * media is content-addressed and may be shared, and a later re-import or manual
 * attach can use it again. The kept images are the primary plus the first of the
 * gallery, in the order the storefront already shows them.
 *
 *   php artisan catalogue:trim-product-images              # dry run, keep 3
 *   php artisan catalogue:trim-product-images --keep=3 --apply
 */
final class TrimProductImages extends Command
{
    protected $signature = 'catalogue:trim-product-images
        {--keep=3 : Maximum images to keep per product.}
        {--apply : Actually detach the surplus. Without this it only reports.}';

    protected $description = 'Trim every product to at most N images (default 3)';

    public function handle(): int
    {
        $keep = max(1, (int) $this->option('keep'));
        $apply = (bool) $this->option('apply');

        $products = 0;
        $detached = 0;

        // Only products that actually exceed the cap. The media relation is
        // ordered primary-first then sort_order, so slicing after $keep drops the
        // lowest-priority gallery shots.
        Product::query()
            ->has('media', '>', $keep)
            ->with('media')
            ->cursor()
            ->each(function (Product $product) use ($keep, $apply, &$products, &$detached): void {
                $surplus = $product->media->slice($keep)->pluck('id')->all();

                if ($surplus === []) {
                    return;
                }

                if ($apply) {
                    $product->media()->detach($surplus);
                }

                $products++;
                $detached += count($surplus);
            });

        $this->newLine();
        $this->table(['Metric', 'Count'], [
            ['Keep per product', $keep],
            ['Products affected', $products],
            [$apply ? 'Images detached' : 'Images that would be detached', $detached],
        ]);

        if (! $apply) {
            $this->info('Dry run. Re-run with --apply to detach the surplus images.');
        } else {
            $this->info('Done. Media rows are kept (content-addressed); only the product links were removed.');
        }

        return self::SUCCESS;
    }
}
