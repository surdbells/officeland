<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Give draft products a brand.
 *
 * Two signals, most reliable first:
 *   1. The media source the importer stamped. The single-brand imports don't
 *      put the brand in the product name (Pilot's "FriXion", Herman Miller's
 *      "Mirra 2"), so the source is the only reliable key for them.
 *   2. The product name starting with a known brand (WiWU, Ugreen, M&G, …) -
 *      how the accessory and multi-brand stock is matched.
 *
 * Missing brands are created. A product whose brand can't be determined either
 * way is left alone and reported, so the gap is visible rather than guessed.
 *
 *   php artisan catalogue:backfill-brands            # report only
 *   php artisan catalogue:backfill-brands --apply
 */
final class BackfillBrands extends Command
{
    protected $signature = 'catalogue:backfill-brands
        {--apply : Create missing brands and set brand_id (otherwise report only).}
        {--all : Consider every brandless product, not just drafts.}';

    protected $description = 'Give draft products a brand (by import source, then by name)';

    /** Media source stamped by a single-brand importer => canonical brand name. */
    private const SOURCE_BRANDS = [
        'naturewall' => 'Naturewall',
        'horion' => 'Horion',
        'kangaro' => 'Kangaro',
        'pilot' => 'Pilot',
        'hermanmiller' => 'Herman Miller',
        'mgstationery' => 'M&G',
    ];

    /** Brands to ensure exist so name-prefix matching can find them. */
    private const KNOWN_BRANDS = [
        'Naturewall', 'Horion', 'Kangaro', 'Pilot', 'Herman Miller', 'M&G', 'WiWU', 'Ugreen',
    ];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $all = (bool) $this->option('all');

        // name(lower) => Brand, built once and grown as brands are created.
        $brands = Brand::query()->get()->keyBy(fn (Brand $b): string => Str::lower($b->name));

        if ($apply) {
            foreach (self::KNOWN_BRANDS as $name) {
                if (! $brands->has(Str::lower($name))) {
                    $brands->put(Str::lower($name), $this->makeBrand($name));
                }
            }
        }

        // Longest names first so "Herman Miller" wins over a hypothetical "Herman".
        $prefixOrder = $brands->keys()->sort(fn ($a, $b): int => mb_strlen($b) <=> mb_strlen($a))->values();

        $assigned = [];
        $unmatched = 0;
        $unmatchedSamples = [];
        $scanned = 0;

        Product::query()
            ->whereNull('brand_id')
            ->when(! $all, fn (Builder $q) => $q->where('status', 'draft'))
            ->with(['media:id,source'])
            ->orderBy('id')
            ->chunkById(500, function ($products) use ($apply, &$brands, &$prefixOrder, &$assigned, &$unmatched, &$unmatchedSamples, &$scanned): void {
                foreach ($products as $product) {
                    $scanned++;

                    $brandName = $this->brandFor($product, $brands, $prefixOrder);

                    if ($brandName === null) {
                        $unmatched++;
                        if (count($unmatchedSamples) < 25) {
                            $unmatchedSamples[] = Str::limit($product->name, 60);
                        }

                        continue;
                    }

                    $assigned[$brandName] = ($assigned[$brandName] ?? 0) + 1;

                    if (! $apply) {
                        continue;
                    }

                    $brand = $brands->get(Str::lower($brandName));
                    if (! $brand instanceof Brand) {
                        $brand = $this->makeBrand($brandName);
                        $brands->put(Str::lower($brandName), $brand);
                        $prefixOrder = $brands->keys()->sort(fn ($a, $b): int => mb_strlen($b) <=> mb_strlen($a))->values();
                    }

                    $product->update(['brand_id' => $brand->id]);
                }
            }, 'id');

        arsort($assigned);

        $this->newLine();
        $this->info(($apply ? 'Assigned' : 'Would assign').' brands to '.array_sum($assigned)." of {$scanned} brandless product(s).");
        $this->table(['Brand', $apply ? 'Assigned' : 'Would assign'], array_map(
            static fn ($name, $n): array => [$name, $n],
            array_keys($assigned),
            array_values($assigned),
        ));

        if ($unmatched > 0) {
            $this->newLine();
            $this->warn("Could not determine a brand for {$unmatched} product(s). Samples:");
            foreach ($unmatchedSamples as $name) {
                $this->line('  '.$name);
            }
        }

        if (! $apply) {
            $this->newLine();
            $this->comment('Report only. Re-run with --apply to create missing brands and set brand_id.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  Collection<string, Brand>  $brands
     * @param  Collection<int, string>  $prefixOrder
     */
    private function brandFor(Product $product, $brands, $prefixOrder): ?string
    {
        // 1. Import source (reliable for single-brand imports).
        foreach ($product->media as $media) {
            $source = Str::lower((string) $media->source);
            if (isset(self::SOURCE_BRANDS[$source])) {
                return self::SOURCE_BRANDS[$source];
            }
        }

        // 2. Product name starts with a known/existing brand, at a word boundary.
        $name = Str::lower(trim((string) $product->name));
        foreach ($prefixOrder as $key) {
            if ($key === '' || ! str_starts_with($name, $key)) {
                continue;
            }
            $next = $name[mb_strlen($key)] ?? '';
            if ($next === '' || ! ctype_alnum($next)) {
                return $brands->get($key)->name;
            }
        }

        return null;
    }

    private function makeBrand(string $name): Brand
    {
        return Brand::create([
            'uuid' => (string) Str::uuid(),
            'name' => $name,
            'slug' => Str::slug($name),
        ]);
    }
}
