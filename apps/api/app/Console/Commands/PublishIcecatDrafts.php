<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Review and bulk-activate Icecat-imported draft products.
 *
 *   php artisan catalogue:icecat-publish --brand=dell                 # dry run
 *   php artisan catalogue:icecat-publish --brand=dell --apply
 *   php artisan catalogue:icecat-publish --catids=151,153 --apply --limit=200
 *
 * ── What "publish" means here, and what it deliberately does NOT do ───────
 * It sets a draft to status=active, so the product becomes visible and
 * quotable on the storefront. It does NOT set is_indexable: an imported
 * product's name and description are Icecat's own prose, kept as drafts (D-015),
 * and indexing syndicated copy is the legacy site's defect at scale. Indexing
 * waits until an original description is written (then the content-minimums
 * gate lets is_indexable flip). So this makes products SELLABLE now and leaves
 * them noindex until they have earned their place in the index.
 *
 * ── The quality gate ──────────────────────────────────────────────────────
 * Only drafts worth showing are activated: at least one image, a spec table of
 * at least the content-minimum rows, and a category (for a real URL and to
 * appear in listings). Everything else stays a draft for manual attention, and
 * the dry run breaks down why.
 */
final class PublishIcecatDrafts extends Command
{
    protected $signature = 'catalogue:icecat-publish
        {--apply : Activate the eligible drafts. Without this the command only reports.}
        {--brand= : Restrict to one brand name (ilike).}
        {--catids= : Restrict to products in these Icecat categories (via the category map).}
        {--min-specs=3 : Minimum spec rows to activate (default: the content-minimum).}
        {--min-images=1 : Minimum images to activate.}
        {--limit=0 : Maximum drafts to process (0 = no limit).}';

    protected $description = 'Activate Icecat-imported drafts that meet the quality gate (kept noindex)';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $minSpecs = (int) $this->option('min-specs');
        $minImages = (int) $this->option('min-images');

        $published = 0;
        $eligible = 0;
        $skipped = ['no image' => 0, 'too few specs' => 0, 'no category' => 0];

        foreach ($this->candidates()->cursor() as $product) {
            $reasons = [];

            if ($product->media_count < $minImages) {
                $reasons[] = 'no image';
            }
            if ($product->attribute_values_count < $minSpecs) {
                $reasons[] = 'too few specs';
            }
            if ($product->primary_category_id === null) {
                $reasons[] = 'no category';
            }

            if ($reasons !== []) {
                foreach ($reasons as $r) {
                    $skipped[$r]++;
                }

                continue;
            }

            $eligible++;

            if ($apply) {
                // status only - is_indexable stays false (see the class note), so
                // the content-minimums guard on is_indexable is never tripped.
                $product->status = 'active';
                $product->published_at ??= now();
                $product->save();
                $published++;
            }
        }

        $this->report($apply, $eligible, $published, $skipped);

        return self::SUCCESS;
    }

    /** @return Builder<Product> */
    private function candidates(): Builder
    {
        return Product::query()
            ->where('source', 'icecat')
            ->where('status', 'draft')
            ->withCount(['media', 'attributeValues'])
            ->when((string) $this->option('brand') !== '', fn (Builder $q) => $q->whereHas(
                'brand',
                fn (Builder $b) => $b->where('name', 'ilike', (string) $this->option('brand')),
            ))
            ->when($this->categoryIds() !== null, fn (Builder $q) => $q->whereIn('primary_category_id', $this->categoryIds()))
            ->orderBy('id')
            ->when((int) $this->option('limit') > 0, fn (Builder $q) => $q->limit((int) $this->option('limit')));
    }

    /**
     * The category ids for the --catids Icecat categories, resolved through the
     * same map the importer uses, or null when the filter is not set.
     *
     * @return list<int>|null
     */
    private function categoryIds(): ?array
    {
        $catids = array_values(array_filter(array_map(
            static fn ($c) => (int) trim($c),
            explode(',', (string) $this->option('catids')),
        )));

        if ($catids === []) {
            return null;
        }

        $paths = array_values(array_filter(array_map(
            static fn (int $catid) => config("icecat.category_map.{$catid}"),
            $catids,
        )));

        return Category::query()->whereIn('path', $paths)->pluck('id')->all();
    }

    /** @param array<string, int> $skipped */
    private function report(bool $apply, int $eligible, int $published, array $skipped): void
    {
        $this->newLine();
        $this->table(['Metric', 'Count'], [
            [$apply ? 'Activated' : 'Eligible (would activate)', $apply ? $published : $eligible],
            ['Skipped — no image', $skipped['no image']],
            ['Skipped — too few specs', $skipped['too few specs']],
            ['Skipped — no category', $skipped['no category']],
        ]);

        if ($apply) {
            $this->info('Activated as SELLABLE but noindex. They index only once an original description is written (D-015).');
        } else {
            $this->info('Dry run. Re-run with --apply to activate the eligible drafts.');
        }
    }
}
