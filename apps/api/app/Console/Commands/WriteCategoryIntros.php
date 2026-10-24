<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Content\ContentMinimums;
use App\Models\Category;
use Illuminate\Console\Command;

/**
 * Fill category intro_copy from the authored intros under
 * database/data/category-intros/*.php (one file per department, each returning
 * a [path => intro] map). A category page cannot be indexed without a 150+ word
 * intro (ContentMinimums::category); this is how the written intros reach the
 * database.
 *
 * Idempotent: by default it only fills a category that still needs an intro, so
 * re-running it never clobbers copy an editor has since improved. --force
 * overwrites existing intros too.
 *
 *   php artisan catalogue:write-category-intros            # dry run
 *   php artisan catalogue:write-category-intros --apply
 *   php artisan catalogue:write-category-intros --apply --force
 */
final class WriteCategoryIntros extends Command
{
    protected $signature = 'catalogue:write-category-intros {--apply : Write the intros.} {--force : Overwrite categories that already have an intro.}';

    protected $description = 'Fill category intro_copy from the authored intros in database/data/category-intros';

    public function handle(ContentMinimums $minimums): int
    {
        $apply = (bool) $this->option('apply');
        $force = (bool) $this->option('force');

        /** @var array<string, string> $intros */
        $intros = [];
        foreach (glob(database_path('data/category-intros/*.php')) ?: [] as $file) {
            $map = require $file;
            if (is_array($map)) {
                $intros = array_merge($intros, $map);
            }
        }

        if ($intros === []) {
            $this->error('No intro files found in database/data/category-intros/*.php.');

            return self::FAILURE;
        }

        $this->info('Loaded '.count($intros).' authored intro(s).');

        $updated = 0;
        $skippedOk = 0;
        $notFound = 0;
        $thin = [];

        foreach ($intros as $path => $copy) {
            $copy = trim((string) $copy);

            if (str_word_count(strip_tags($copy)) < ContentMinimums::CATEGORY_WORDS_MIN) {
                $thin[$path] = str_word_count(strip_tags($copy));
            }

            $category = Category::query()->where('path', $path)->first();

            if ($category === null) {
                $notFound++;
                $this->warn("  path not found: {$path}");

                continue;
            }

            if (! $force && $minimums->category($category)->isReady()) {
                $skippedOk++;

                continue;
            }

            if ($apply) {
                $category->update(['intro_copy' => $copy]);
            }

            $updated++;
        }

        $this->newLine();
        $this->table(['Outcome', 'Count'], [
            [$apply ? 'Updated' : 'Would update', $updated],
            ['Skipped (already has an intro)', $skippedOk],
            ['Path not found', $notFound],
            ['Authored intros under '.ContentMinimums::CATEGORY_WORDS_MIN.' words', count($thin)],
        ]);

        foreach ($thin as $path => $words) {
            $this->warn("  thin ({$words} words): {$path}");
        }

        if (! $apply) {
            $this->comment('Dry run. Add --apply to write them (--force to overwrite existing intros).');
        }

        return self::SUCCESS;
    }
}
