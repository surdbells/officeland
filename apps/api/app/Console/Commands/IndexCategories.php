<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Content\ContentMinimums;
use App\Models\Category;
use Illuminate\Console\Command;

/**
 * Mark content-ready category pages indexable, and un-mark ones that no longer
 * are, so the taxonomy reaches the sitemap.
 *
 * Products have catalogue:finalise-products to publish them; categories had no
 * equivalent, so is_indexable was never set on any of them and sitemap-categories
 * came out empty - the department pages, which are the catalogue's prime search
 * landing pages, were offered to nobody. A category is ready when its intro
 * clears the floor (ContentMinimums::CATEGORY_WORDS_MIN, 150 words), so this
 * reconciles is_indexable to that readiness.
 *
 * saveQuietly bypasses the EnforcesContentMinimums guard the same way
 * finalise-products does, and for the same reason: the bar it checks IS the bar
 * the guard enforces, applied deliberately in bulk rather than one save at a
 * time. The guard still protects the Filament form.
 *
 * The category PAGES must be re-rendered afterwards (render:full) so their robots
 * meta matches the new flag, and render:sitemaps re-run so they are listed.
 *
 *   php artisan catalogue:index-categories          # report
 *   php artisan catalogue:index-categories --apply
 */
final class IndexCategories extends Command
{
    protected $signature = 'catalogue:index-categories {--apply : Write is_indexable.}';

    protected $description = 'Mark content-ready categories indexable so they reach the sitemap';

    public function handle(ContentMinimums $minimums): int
    {
        $apply = (bool) $this->option('apply');

        $indexed = 0;
        $unindexed = 0;
        $ready = 0;
        $scanned = 0;
        /** @var list<string> $notReady */
        $notReady = [];

        Category::query()
            ->orderBy('path')
            ->chunkById(500, function ($categories) use ($minimums, $apply, &$indexed, &$unindexed, &$ready, &$scanned, &$notReady): void {
                foreach ($categories as $category) {
                    $scanned++;
                    $isReady = $minimums->category($category)->isReady();

                    if ($isReady) {
                        $ready++;
                    } elseif (count($notReady) < 30) {
                        $notReady[] = (string) $category->path;
                    }

                    if ((bool) $category->is_indexable !== $isReady) {
                        if ($apply) {
                            $category->forceFill(['is_indexable' => $isReady])->saveQuietly();
                        }
                        $isReady ? $indexed++ : $unindexed++;
                    }
                }
            });

        $this->newLine();
        $this->table(['Outcome', 'Count'], [
            [$apply ? 'Now indexable' : 'Would index', $indexed],
            [$apply ? 'Removed from index' : 'Would remove', $unindexed],
            ['Ready in total', $ready],
            ['Scanned', $scanned],
        ]);

        if ($notReady !== []) {
            $this->newLine();
            $this->warn('Not ready - need a '.ContentMinimums::CATEGORY_WORDS_MIN.'+ word intro (sample):');
            foreach ($notReady as $path) {
                $this->line('  '.$path);
            }
            $this->comment('Author intros under database/data/category-intros/, then run catalogue:write-category-intros --apply.');
        }

        if (! $apply) {
            $this->comment('Dry run. Add --apply, then re-render the category pages (render:full) and render:sitemaps.');
        }

        return self::SUCCESS;
    }
}
