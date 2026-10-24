<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Category;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Give each category a square card image, borrowed from the best product photo
 * in its subtree.
 *
 * The homepage department cards and the megamenu want a 1:1 image per category,
 * and uploading 170 by hand is slow. Icecat product photos are high-resolution
 * and shot on white, so they crop to a clean square card. For each category this
 * picks the sharpest PRIMARY product image (highest-resolution original, with
 * derivatives already generated) from an active product anywhere in the
 * category's subtree, and points the category's card_media_id at it. Media is
 * content-addressed, so the row is simply reused - nothing is copied or
 * regenerated.
 *
 * Idempotent: only fills a category that has no card image, so a hand-picked
 * image is never overwritten; --force replaces existing ones. --hero also fills
 * a missing hero_media_id from the same photo (a square product shot crops
 * acceptably into the wide hero until a proper banner is uploaded).
 *
 *   php artisan catalogue:category-card-images                 # report only
 *   php artisan catalogue:category-card-images --apply
 *   php artisan catalogue:category-card-images --apply --hero
 *   php artisan catalogue:category-card-images --apply --force
 */
final class SetCategoryCardImages extends Command
{
    protected $signature = 'catalogue:category-card-images
        {--apply : Write the images.}
        {--force : Replace card images that are already set.}
        {--hero : Also fill hero_media_id when the category has none.}';

    protected $description = 'Set each category card image from the best product photo in its subtree';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $force = (bool) $this->option('force');
        $hero = (bool) $this->option('hero');

        // Deepest categories first: a leaf gets the image of a product that is
        // actually in it, before a parent reuses one from across its subtree.
        $categories = Category::query()->orderByDesc('depth')->orderBy('path')->get();

        $set = 0;
        $skipped = 0;
        $noImage = 0;

        foreach ($categories as $category) {
            $needsCard = $force || $category->card_media_id === null;
            $needsHero = $hero && $category->hero_media_id === null;

            if (! $needsCard && ! $needsHero) {
                $skipped++;

                continue;
            }

            $mediaId = $this->bestMediaId($category->path);

            if ($mediaId === null) {
                $noImage++;

                continue;
            }

            $changes = [];
            if ($needsCard) {
                $changes['card_media_id'] = $mediaId;
            }
            if ($needsHero) {
                $changes['hero_media_id'] = $mediaId;
            }

            if ($apply) {
                $category->update($changes);
            }

            $set++;
        }

        if ($apply) {
            // The navigation payload carries the card and hero images, and it is
            // cached for an hour; drop it so the new images show at once.
            \Illuminate\Support\Facades\Cache::forget('api:navigation:v1');
        }

        $this->newLine();
        $this->table(['Outcome', 'Categories'], [
            [$apply ? 'Image set' : 'Would set an image', $set],
            ['Skipped (already has one)', $skipped],
            ['No product image in subtree', $noImage],
        ]);

        if (! $apply) {
            $this->comment('Dry run. Add --apply to set them (--hero to also fill empty heroes, --force to replace).');
        }

        return self::SUCCESS;
    }

    /**
     * The id of the sharpest primary product image anywhere in a category's
     * subtree, or null when the subtree has no usable product photo.
     */
    private function bestMediaId(string $path): ?int
    {
        return DB::table('product_media')
            ->join('products', 'products.id', '=', 'product_media.product_id')
            ->join('product_category', 'product_category.product_id', '=', 'products.id')
            ->join('categories', 'categories.id', '=', 'product_category.category_id')
            ->join('media', 'media.id', '=', 'product_media.media_id')
            ->where('product_media.role', 'primary')
            ->where('products.status', 'active')
            ->whereNull('products.deleted_at')
            ->where('categories.path', 'like', $path.'%')
            ->whereNotNull('media.width')
            // A card of a broken image helps nobody: the chosen media must have
            // its derivatives generated.
            ->whereExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('media_derivatives')
                    ->whereColumn('media_derivatives.media_id', 'media.id');
            })
            ->orderByDesc('media.width')
            ->value('media.id');
    }
}
