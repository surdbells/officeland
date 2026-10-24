<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Icecat\IcecatIndex;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * One consolidated list of the Icecat Catids that are not yet mapped, across
 * several brands at once.
 *
 * Finishing a brand import (HP, ASUS) means extending config('icecat.category_map')
 * with the real Catids the brand uses, and exclude_catids with its junk. Running
 * catalogue:recategorise once per brand works but is one survey per brand; this
 * streams the index a single time for ALL the given suppliers and reports every
 * unmapped Catid together - how many products carry it, across which brands, with
 * a few real product names - so the whole map can be extended in one edit.
 *
 *   php artisan catalogue:survey-catids --brands=apple,dell,canon,epson,lenovo
 *   php artisan catalogue:survey-catids                     # all configured brands
 */
final class SurveyUnmappedCatids extends Command
{
    protected $signature = 'catalogue:survey-catids
        {--brands= : Comma-separated brand keys (default: all configured suppliers).}
        {--refresh-index : Re-download the repository index first.}
        {--rows=40 : How many unmapped Catids to list.}';

    protected $description = 'List the unmapped Icecat Catids across several brands in one survey';

    public function handle(): int
    {
        /** @var array<string, int> $suppliers */
        $suppliers = (array) config('icecat.suppliers');

        $brands = array_filter(array_map('trim', explode(',', (string) $this->option('brands'))));
        if ($brands === []) {
            $brands = array_keys($suppliers);
        }

        $supplierIds = [];
        $supplierToBrand = [];
        foreach ($brands as $brand) {
            $id = $suppliers[Str::lower($brand)] ?? null;
            if ($id === null) {
                $this->warn("Unknown brand (skipped): {$brand}");

                continue;
            }
            $supplierIds[] = (int) $id;
            $supplierToBrand[(int) $id] = Str::lower($brand);
        }

        if ($supplierIds === []) {
            $this->error('No known brands given. Known: '.implode(', ', array_keys($suppliers)));

            return self::INVALID;
        }

        $indexPath = IcecatIndex::ensureCached((bool) $this->option('refresh-index'));
        if ($indexPath === null) {
            $this->error('Could not download the Icecat index. Check credentials and IP whitelisting.');

            return self::FAILURE;
        }

        // icecat_id => true, for imported products still missing a category.
        $uncategorised = Product::query()
            ->whereNull('primary_category_id')
            ->whereNotNull('icecat_id')
            ->pluck('icecat_id')
            ->flip();

        if ($uncategorised->isEmpty()) {
            $this->info('No imported products are missing a category.');

            return self::SUCCESS;
        }

        $map = (array) config('icecat.category_map');
        $exclude = (array) config('icecat.exclude_catids');

        $this->info('Scanning the index for '.count($supplierIds).' brand(s), '.$uncategorised->count().' uncategorised product(s)…');

        /** @var array<int, int> $count */
        $count = [];
        /** @var array<int, array<string, int>> $brandsByCat */
        $brandsByCat = [];
        /** @var array<int, list<int>> $sampleIds */
        $sampleIds = [];

        foreach (IcecatIndex::stream('compress.zlib://'.$indexPath, $supplierIds) as $entry) {
            $catid = $entry['catid'];

            if ($catid === null || isset($map[$catid]) || in_array($catid, $exclude, true)) {
                continue;
            }
            if (! $uncategorised->has($entry['product_id'])) {
                continue;
            }

            $count[$catid] = ($count[$catid] ?? 0) + 1;

            $brand = $supplierToBrand[$entry['supplier_id']] ?? (string) $entry['supplier_id'];
            $brandsByCat[$catid][$brand] = ($brandsByCat[$catid][$brand] ?? 0) + 1;

            if (count($sampleIds[$catid] ?? []) < 3) {
                $sampleIds[$catid][] = $entry['product_id'];
            }
        }

        if ($count === []) {
            $this->info('Every Catid for these brands is already mapped or excluded.');

            return self::SUCCESS;
        }

        arsort($count);

        $allIds = array_merge(...array_values($sampleIds));
        $names = Product::query()->whereIn('icecat_id', $allIds)->pluck('name', 'icecat_id');

        $rows = [];
        foreach (array_slice($count, 0, (int) $this->option('rows'), true) as $catid => $n) {
            $brandList = $brandsByCat[$catid] ?? [];
            arsort($brandList);
            $brandStr = implode(', ', array_map(
                static fn ($b, $c): string => "{$b}({$c})",
                array_keys($brandList),
                array_values($brandList),
            ));

            $sampleNames = [];
            foreach ($sampleIds[$catid] ?? [] as $id) {
                $sampleNames[] = Str::limit((string) ($names[$id] ?? $id), 42);
            }

            $rows[] = [$catid, $n, Str::limit($brandStr, 34), implode("\n", $sampleNames)];
        }

        $this->newLine();
        $this->warn('Unmapped Catids across these brands (map the real ones in category_map, exclude the junk):');
        $this->table(['Catid', 'Products', 'Brands', 'Sample products (real names)'], $rows);

        return self::SUCCESS;
    }
}
