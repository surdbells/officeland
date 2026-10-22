<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;

/**
 * Permanently delete soft-deleted products and the images they leave orphaned.
 *
 * Pruning (catalogue:prune-icecat) soft-deletes, so the products are recoverable
 * from the trashed filter. This is the irreversible follow-up: it force-deletes
 * every trashed product (their category/media/relation pivots cascade in the
 * database) and then reaps the media that is no longer referenced by any
 * product - removing the derivative and original files from disk, not just the
 * rows. Content-addressed media still used by another product is left untouched.
 *
 *   php artisan catalogue:purge-trashed            # report only
 *   php artisan catalogue:purge-trashed --apply
 */
final class PurgeTrashedProducts extends Command
{
    protected $signature = 'catalogue:purge-trashed
        {--apply : Actually delete. Without this it only reports.}';

    protected $description = 'Permanently delete soft-deleted products and reap their orphaned media';

    public function handle(): int
    {
        @ini_set('memory_limit', '512M');

        $count = Product::onlyTrashed()->count();

        if ($count === 0) {
            $this->info('No soft-deleted products to purge.');

            return self::SUCCESS;
        }

        if (! $this->option('apply')) {
            $this->warn("{$count} soft-deleted product(s) would be permanently deleted, and their orphaned images removed.");
            $this->comment('Dry run. Add --apply to delete them for good (NOT recoverable).');

            return self::SUCCESS;
        }

        $this->info("Permanently deleting {$count} trashed product(s)…");

        // Force-delete in id batches. The DB cascades product_category,
        // product_media and product_relations; the media ROWS survive (they are
        // content-addressed and may be shared) and are reaped below.
        Product::onlyTrashed()
            ->orderBy('id')
            ->chunkById(500, function ($products): void {
                Product::withTrashed()->whereKey($products->modelKeys())->forceDelete();
            });

        $this->info('Reaping images no longer attached to any product…');
        $this->call('media:reap', ['--apply' => true]);

        $this->info('Done.');

        return self::SUCCESS;
    }
}
