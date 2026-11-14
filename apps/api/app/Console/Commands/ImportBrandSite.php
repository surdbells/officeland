<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Content\ProductSpecs;
use App\Importers\BrandSite\BrandHttp;
use App\Importers\BrandSite\BrandSiteAdapter;
use App\Importers\BrandSite\ImportedProduct;
use App\Jobs\GenerateMediaDerivatives;
use App\Media\MediaIngestor;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Observers\InvalidatesRenderedRoutes;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Import a manufacturer's catalogue from its own website.
 *
 * One engine, one adapter per brand (app/Importers/BrandSite). The adapter only
 * reads the site; the rules below are the same for every brand:
 *
 * ── Matching, so a re-run or an existing product never duplicates ────────
 * A product is the same product when its MPN matches - first within the brand,
 * then (for legacy rows imported with no brand) any product whose MPN matches
 * and whose name mentions the brand - and otherwise by the importer's own slug.
 *
 * ── An existing product is enriched, never overwritten ───────────────────
 * Missing brand, MPN, category and EAN are filled; specs are written only when
 * the product has fewer than three (Icecat or hand-entered specs stay); images
 * are added only when it has none (--refresh-images replaces importer ones); the
 * name is kept unless --rename (legacy names are messy, but they are URLs too).
 *
 * ── New products are drafts with a template blurb ────────────────────────
 * The vendor copy goes to source_description only (never published verbatim,
 * D-015); the public description is a stamped template the spec-driven drafter
 * may replace, and catalogue:finalise-products decides what indexes.
 *
 *   php artisan catalogue:import-brand casio                    # dry run
 *   php artisan catalogue:import-brand casio --limit=10 --apply
 *   php artisan catalogue:import-brand all --apply              # every adapter
 */
final class ImportBrandSite extends Command
{
    protected $signature = 'catalogue:import-brand
        {brand : Adapter key (see --list) or "all".}
        {--apply : Write. Without this it only reports what it would do.}
        {--limit=0 : Stop after this many products (0 = all).}
        {--sleep=0 : Extra milliseconds between requests, on top of the adapter minimum.}
        {--images=3 : Maximum images per product.}
        {--refresh-images : Replace images this importer added earlier.}
        {--rename : Overwrite the name of matched existing products.}
        {--url=* : Import only these product references (URLs), skipping discovery.}
        {--list : List the available adapters.}';

    protected $description = "Import a brand's products from its official website (casio, anker, apple, apc, havit, transcend, genius)";

    /** @var array<string, int> */
    private array $stats = [];

    /** @var array<string, int> */
    private array $unmapped = [];

    /** @var array<string, int> */
    private array $byCategory = [];

    /** @var array<string, int> */
    private array $categoryIds = [];

    public function handle(MediaIngestor $ingestor): int
    {
        @ini_set('memory_limit', '512M');

        $adapters = self::adapters();

        if ($this->option('list')) {
            foreach ($adapters as $key => $class) {
                $this->line("  {$key}  ".(new $class)->brandName());
            }

            return self::SUCCESS;
        }

        $key = Str::lower((string) $this->argument('brand'));
        $keys = $key === 'all' ? array_keys($adapters) : [$key];

        foreach ($keys as $k) {
            if (! isset($adapters[$k])) {
                $this->error("Unknown brand '{$k}'. Available: ".implode(', ', array_keys($adapters)));

                return self::INVALID;
            }
        }

        // Render invalidation per saved product would flood the queue on a
        // bulk import; pages are rebuilt deliberately afterwards (render:full).
        $status = self::SUCCESS;
        InvalidatesRenderedRoutes::withoutInvalidation(function () use ($keys, $adapters, $ingestor, &$status): void {
            foreach ($keys as $k) {
                if ($this->runAdapter(new $adapters[$k], $ingestor) !== self::SUCCESS) {
                    $status = self::FAILURE;
                }
            }
        });

        return $status;
    }

    /** @return array<string, class-string<BrandSiteAdapter>> */
    public static function adapters(): array
    {
        $found = [];
        foreach (glob(app_path('Importers/BrandSite/Adapters/*Adapter.php')) ?: [] as $file) {
            $class = 'App\\Importers\\BrandSite\\Adapters\\'.basename($file, '.php');
            if (class_exists($class) && is_subclass_of($class, BrandSiteAdapter::class)) {
                $found[(new $class)->key()] = $class;
            }
        }
        ksort($found);

        return $found;
    }

    private function runAdapter(BrandSiteAdapter $adapter, MediaIngestor $ingestor): int
    {
        $this->stats = ['seen' => 0, 'created' => 0, 'matched' => 0, 'images' => 0, 'skipped' => 0, 'failed' => 0];
        $this->unmapped = [];
        $this->byCategory = [];

        $apply = (bool) $this->option('apply');
        $limit = (int) $this->option('limit');
        $http = new BrandHttp($adapter->minDelayMs() + max(0, (int) $this->option('sleep')), $adapter->userAgent());

        $this->info("── {$adapter->brandName()}".($apply ? '' : ' (dry run)'));

        $brand = $apply
            ? Brand::query()->firstOrCreate(['slug' => Str::slug($adapter->brandName())], ['uuid' => (string) Str::uuid(), 'name' => $adapter->brandName()])
            : Brand::query()->where('slug', Str::slug($adapter->brandName()))->first();

        try {
            $references = $this->option('url') ?: $adapter->discover($http);
        } catch (Throwable $e) {
            $this->error("  discovery failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        foreach ($references as $reference) {
            if ($limit > 0 && $this->stats['seen'] >= $limit) {
                break;
            }

            try {
                $items = $adapter->read($http, $reference);
            } catch (Throwable $e) {
                $this->stats['failed']++;
                $this->warn("  read failed [{$reference}]: {$e->getMessage()}");

                continue;
            }

            foreach ($items as $item) {
                if ($limit > 0 && $this->stats['seen'] >= $limit) {
                    break;
                }
                $this->stats['seen']++;

                try {
                    $this->upsert($item, $adapter, $brand, $ingestor, $apply);
                } catch (Throwable $e) {
                    $this->stats['failed']++;
                    $this->warn("  failed [{$item->name}]: {$e->getMessage()}");
                }
            }
        }

        $this->report($adapter, $apply, $http->requests);

        return self::SUCCESS;
    }

    private function upsert(ImportedProduct $item, BrandSiteAdapter $adapter, ?Brand $brand, MediaIngestor $ingestor, bool $apply): void
    {
        if ($item->categoryPath === null) {
            $this->unmapped[$item->sourceCategory ?: '(none)'] = ($this->unmapped[$item->sourceCategory ?: '(none)'] ?? 0) + 1;
            $this->stats['skipped']++;

            return;
        }

        $categoryId = $this->categoryId($item->categoryPath);
        if ($categoryId === null) {
            $this->unmapped["missing category {$item->categoryPath}"] = ($this->unmapped["missing category {$item->categoryPath}"] ?? 0) + 1;
            $this->stats['skipped']++;

            return;
        }

        $existing = $this->match($item, $adapter, $brand);
        $this->byCategory[$item->categoryPath] = ($this->byCategory[$item->categoryPath] ?? 0) + 1;
        $existing ? $this->stats['matched']++ : $this->stats['created']++;

        if (! $apply || $brand === null) {
            return;
        }

        $name = Str::limit($this->brandedName($item->name, $adapter->brandName()), 150, '');

        if ($existing !== null) {
            $product = $existing;
            $fill = array_filter([
                'brand_id' => $product->brand_id === null ? $brand->id : null,
                'brand_name_cached' => $product->brand_id === null ? $brand->name : null,
                'mpn' => ($product->mpn ?? '') === '' ? $item->mpn : null,
                'ean' => ($product->ean ?? '') === '' ? $item->ean : null,
                'primary_category_id' => $product->primary_category_id === null ? $categoryId : null,
                'source_title' => ($product->source_title ?? '') === '' ? $item->name : null,
                'source_description' => ($product->source_description ?? '') === '' ? $item->summary : null,
                'name' => $this->option('rename') ? $name : null,
            ], fn ($v): bool => $v !== null && $v !== '');
            if ($fill !== []) {
                $product->forceFill($fill)->save();
            }
        } else {
            $product = Product::query()->create([
                'uuid' => (string) Str::uuid(),
                'slug' => $this->freshSlug($adapter->brandName(), $item),
                'name' => $name,
                'brand_id' => $brand->id,
                'brand_name_cached' => $brand->name,
                'mpn' => $item->mpn,
                'ean' => $item->ean,
                'primary_category_id' => $categoryId,
                'source_title' => $item->name,
                'source_description' => $item->summary,
                'short_description' => Str::limit($name.', supplied by OfficeLand with nationwide delivery across Nigeria. Request a quote for pricing and availability.', 155, ''),
                'description_html' => '<p>'.e($this->blurb($name, $adapter->brandName(), $item->categoryPath)).'</p>',
                // Template copy is machine copy: stamped so the spec-driven
                // drafter may replace it and the readiness gate holds it.
                'description_generated_at' => now(),
                'availability' => 'lead_time',
            ]);
        }

        $product->categories()->syncWithoutDetaching([$categoryId => ['sort_order' => 0]]);

        if ($existing === null || $product->attributeValues()->count() < 3) {
            ProductSpecs::set($product, $item->specs + array_filter(['Brand' => $adapter->brandName(), 'Manufacturer code' => $item->mpn]));
        }

        $this->attachImages($product, $item, $adapter, $ingestor);
    }

    private function match(ImportedProduct $item, BrandSiteAdapter $adapter, ?Brand $brand): ?Product
    {
        $mpn = $this->normalise($item->mpn);

        if ($mpn !== '') {
            $sql = "upper(replace(replace(mpn, ' ', ''), '-', '')) = ?";
            $found = Product::query()
                ->when($brand !== null, fn ($q) => $q->where('brand_id', $brand->id))
                ->whereRaw($sql, [$mpn])
                ->first();

            // Legacy rows often have the code but no brand set: accept the code
            // match when the name names the brand.
            $found ??= Product::query()
                ->whereNull('brand_id')
                ->whereRaw($sql, [$mpn])
                ->where('name', 'ilike', '%'.$adapter->brandName().'%')
                ->first();

            if ($found !== null) {
                return $found;
            }
        }

        return Product::query()->where('slug', $this->freshSlug($adapter->brandName(), $item))->first();
    }

    private function attachImages(Product $product, ImportedProduct $item, BrandSiteAdapter $adapter, MediaIngestor $ingestor): void
    {
        if ($item->images === []) {
            return;
        }

        $has = $product->media()->exists();
        if ($has && ! $this->option('refresh-images')) {
            return;
        }

        if ($has) {
            // Only images this importer added are replaced; Icecat and hand-
            // uploaded media stay.
            $ours = $product->media()->where('media.source', $adapter->key())->pluck('media.id');
            $product->media()->detach($ours);
            if ($product->media()->exists()) {
                return;
            }
        }

        $max = max(1, (int) $this->option('images'));
        $order = 0;

        foreach (array_slice(array_values(array_unique($item->images)), 0, $max) as $url) {
            $tmp = tempnam(sys_get_temp_dir(), 'brand').'.img';
            try {
                $response = Http::timeout(60)->withHeaders($adapter->imageHeaders() + ['User-Agent' => $adapter->userAgent()])
                    ->retry(2, 1500, throw: false)->sink($tmp)->get($url);

                if (! $response->successful() || (filesize($tmp) ?: 0) < 2048) {
                    continue;
                }

                $media = $ingestor->ingest($tmp, [
                    'alt_text' => $product->name,
                    'source' => $adapter->key(),
                    'source_url' => $url,
                ]);
                $product->media()->syncWithoutDetaching([$media->id => ['role' => $order === 0 ? 'primary' : 'gallery', 'sort_order' => $order]]);
                GenerateMediaDerivatives::dispatch($media->id);
                $this->stats['images']++;
                $order++;
            } catch (Throwable $e) {
                $this->warn("  image failed [{$url}]: {$e->getMessage()}");
            } finally {
                @unlink($tmp);
            }
        }
    }

    private function categoryId(string $path): ?int
    {
        if (! array_key_exists($path, $this->categoryIds)) {
            $this->categoryIds[$path] = Category::query()->where('path', $path)->value('id');
        }

        return $this->categoryIds[$path];
    }

    private function freshSlug(string $brand, ImportedProduct $item): string
    {
        return Str::limit(Str::slug($brand.' '.($item->mpn ?: $item->name)), 180, '');
    }

    private function normalise(?string $mpn): string
    {
        return Str::upper(str_replace([' ', '-'], '', trim((string) $mpn)));
    }

    /** The brand leads the name once, never twice ("Casio Casio fx-991"). */
    private function brandedName(string $name, string $brand): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);

        return Str::startsWith(Str::lower($name), Str::lower($brand)) ? $name : $brand.' '.$name;
    }

    private function blurb(string $name, string $brand, string $path): string
    {
        $type = Str::lower((string) (Category::query()->where('path', $path)->value('name') ?? 'product'));

        return "{$name} is genuine {$brand} {$type} stock, supplied by OfficeLand to offices, schools and government buyers across Nigeria. "
            .'Request a quote for current pricing, quantities and delivery; nationwide delivery is available from our Lagos showrooms.';
    }

    private function report(BrandSiteAdapter $adapter, bool $apply, int $requests): void
    {
        $this->table(['Outcome', 'Count'], [
            ['Products read', $this->stats['seen']],
            [$apply ? 'Created' : 'Would create', $this->stats['created']],
            [$apply ? 'Matched existing (enriched)' : 'Would match existing', $this->stats['matched']],
            ['Images attached', $this->stats['images']],
            ['Skipped (unmapped)', $this->stats['skipped']],
            ['Failed', $this->stats['failed']],
            ['HTTP requests', $requests],
        ]);

        if ($this->byCategory !== []) {
            arsort($this->byCategory);
            $this->table(['Category', 'Products'], array_map(null, array_keys($this->byCategory), array_values($this->byCategory)));
        }

        if ($this->unmapped !== []) {
            arsort($this->unmapped);
            $this->warn('Unmapped '.$adapter->brandName().' categories (extend the adapter map if any belong in the catalogue):');
            $this->table(['Source category', 'Products'], array_map(null, array_keys($this->unmapped), array_values($this->unmapped)));
        }
    }
}
