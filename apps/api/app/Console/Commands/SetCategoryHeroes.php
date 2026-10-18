<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * Give each department a representative hero image from its own catalogue, so
 * the homepage slider and the department rail render photographs instead of
 * lettered placeholders, and every subcategory inherits one for its page hero.
 *
 * This is the reproducible, committable replacement for the one-off tinker that
 * seeded these on the dev database - run it on a freshly imported production DB:
 *
 *     php artisan catalogue:set-heroes
 *
 * Idempotent: a department that already has a hero is left alone unless --force.
 */
final class SetCategoryHeroes extends Command
{
    protected $signature = 'catalogue:set-heroes {--force : overwrite heroes that are already set}';

    protected $description = 'Give each department a representative hero image from its catalogue';

    /**
     * Name keywords that pick a clean, on-topic product per department, in
     * preference order - a monitor leads IT (laptop stock imagery is noisier),
     * a shredder leads office supplies, and so on. Falls back to any product in
     * the subtree that has an image.
     *
     * @var array<string, list<string>>
     */
    private const KEYWORDS = [
        'office-furniture' => ['executive chair', 'ergonomic', 'chair', 'desk'],
        'it-products' => ['monitor', 'dell', 'lenovo', 'laptop'],
        'smart-tech' => ['ups', 'inverter', 'cctv', 'camera'],
        'printers-consumables' => ['printer', 'officejet', 'deskjet', 'laserjet'],
        'office-supplies' => ['shredder', 'laminator', 'stapler'],
        'paper-notebooks' => ['a4', 'ream', 'paper', 'notebook'],
        'filing-storage' => ['lever arch', 'box file', 'folder', 'cabinet'],
        'school-supplies' => ['crayon', 'colour', 'math set', 'ruler'],
        'pvc-doors-interiors' => ['pvc', 'door', 'panel', 'wardrobe'],
    ];

    public function handle(): int
    {
        $set = 0;
        $skipped = 0;
        $missed = 0;

        foreach (Category::query()->where('depth', 1)->orderBy('sort_order')->get() as $department) {
            if ($department->hero_media_id !== null && ! $this->option('force')) {
                $skipped++;

                continue;
            }

            $mediaId = $this->pickHero($department);

            if ($mediaId === null) {
                $this->warn("  no image found for {$department->slug}");
                $missed++;

                continue;
            }

            $department->hero_media_id = $mediaId;
            $department->save();
            $this->info("  {$department->slug} -> media {$mediaId}");
            $set++;
        }

        // The navigation payload (which carries the department heroes) is cached
        // for an hour; drop it so the storefront picks the new images up at once.
        Cache::forget('api:navigation:v1');

        $this->newLine();
        $this->info("Heroes set: {$set}, skipped (already set): {$skipped}, no image: {$missed}.");

        return self::SUCCESS;
    }

    /**
     * The media id of a representative product's primary image for this
     * department, preferring a product whose name matches a department keyword.
     */
    private function pickHero(Category $department): ?int
    {
        foreach (self::KEYWORDS[$department->slug] ?? [] as $keyword) {
            $product = $this->subtreeProducts($department)
                ->where('name', 'ilike', '%'.$keyword.'%')
                ->first();

            if ($product?->media->isNotEmpty()) {
                return (int) $product->media->first()->getKey();
            }
        }

        $product = $this->subtreeProducts($department)->first();

        return $product?->media->isNotEmpty()
            ? (int) $product->media->first()->getKey()
            : null;
    }

    /** Active products anywhere in the department's subtree that have an image. */
    private function subtreeProducts(Category $department): Builder
    {
        return Product::query()
            ->active()
            ->whereHas('categories', fn (Builder $categories) => $categories->where('path', 'like', $department->path.'%'))
            ->whereHas('media')
            ->with('media');
    }
}
