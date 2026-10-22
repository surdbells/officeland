<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Icecat\IcecatIndex;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Fill in the category for imported products that have none, from the Icecat
 * index, and report the Icecat categories we do not yet map.
 *
 * A product lands uncategorised when its Icecat Catid is not in
 * config('icecat.category_map'). The import does not keep the raw Catid on the
 * product, so this re-streams the index (Product_id → Catid is cheap there) and,
 * for every imported product still missing a category whose Catid IS mapped,
 * sets it. The report then lists the UNMAPPED Catids by volume - the shortlist
 * of what to add to the map to categorise the rest.
 *
 *   php artisan catalogue:recategorise --brand=hp            # report only
 *   php artisan catalogue:recategorise --brand=hp --apply
 */
final class RecategoriseFromIcecat extends Command
{
    protected $signature = 'catalogue:recategorise
        {--brand= : Brand key from config/icecat.php suppliers (e.g. hp).}
        {--apply : Actually set categories. Without this it only reports.}
        {--refresh-index : Re-download the repository index first.}';

    protected $description = 'Set missing product categories from the Icecat index; report unmapped Catids';

    public function handle(): int
    {
        $brand = Str::lower(trim((string) $this->option('brand')));
        $supplierId = config("icecat.suppliers.{$brand}");

        if ($supplierId === null) {
            $this->error('Pass a known --brand. Known: '.implode(', ', array_keys((array) config('icecat.suppliers'))));

            return self::INVALID;
        }

        $indexPath = IcecatIndex::ensureCached((bool) $this->option('refresh-index'));

        if ($indexPath === null) {
            $this->error('Could not download the Icecat index. Check credentials and IP whitelisting.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');

        // icecat_id of every imported product for this brand that has no category.
        $uncategorised = Product::query()
            ->whereNull('primary_category_id')
            ->whereNotNull('icecat_id')
            ->pluck('icecat_id')
            ->flip(); // icecat_id => index, for O(1) lookup

        if ($uncategorised->isEmpty()) {
            $this->info('No imported products are missing a category.');

            return self::SUCCESS;
        }

        $this->info($uncategorised->count().' product(s) have no category. Scanning the index…');

        /** @var array<int, Category|null> $catCache */
        $catCache = [];
        $fixed = 0;
        $unmapped = [];
        $unmappedSample = [];

        foreach (IcecatIndex::stream('compress.zlib://'.$indexPath, [(int) $supplierId]) as $entry) {
            if (! $uncategorised->has($entry['product_id'])) {
                continue;
            }

            $catid = $entry['catid'];
            $path = $catid !== null ? config("icecat.category_map.{$catid}") : null;

            if ($path === null) {
                $unmapped[$catid] = ($unmapped[$catid] ?? 0) + 1;
                $unmappedSample[$catid] ??= (string) ($entry['model_name'] ?? '');

                continue;
            }

            $category = $catCache[$catid] ??= Category::query()->where('path', $path)->first();

            if ($category === null) {
                continue;
            }

            if ($apply) {
                $product = Product::query()->where('icecat_id', $entry['product_id'])->first();

                if ($product !== null && $product->primary_category_id === null) {
                    $product->update(['primary_category_id' => $category->id]);
                    $product->categories()->syncWithoutDetaching([$category->id => ['sort_order' => 0]]);
                }
            }

            $fixed++;
        }

        $this->newLine();
        $this->info(($apply ? 'Categorised' : 'Would categorise').": {$fixed} product(s) via the current map.");

        if ($unmapped !== []) {
            arsort($unmapped);
            $rows = [];
            foreach (array_slice($unmapped, 0, 25, true) as $catid => $count) {
                $rows[] = [$catid ?: '(none)', $count, Str::limit($unmappedSample[$catid] ?? '', 44)];
            }
            $this->newLine();
            $this->warn('Unmapped Icecat Catids (add the useful ones to config/icecat.php category_map, then re-run):');
            $this->table(['Catid', 'Products', 'Sample product'], $rows);
        }

        if (! $apply) {
            $this->comment('Dry run. Add --apply to set categories.');
        }

        return self::SUCCESS;
    }
}
