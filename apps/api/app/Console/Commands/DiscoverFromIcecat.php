<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Icecat\IcecatClient;
use App\Icecat\IcecatDocument;
use App\Icecat\IcecatIndex;
use App\Icecat\IcecatIngestor;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Discover and import NEW products from Icecat for a brand (Phase 5b).
 *
 *   php artisan catalogue:icecat-discover --brand=dell            # dry run
 *   php artisan catalogue:icecat-discover --brand=dell --dump --limit=5
 *   php artisan catalogue:icecat-discover --brand=dell --apply --limit=200
 *
 * ── How it finds products ────────────────────────────────────────────────
 * Icecat has no "all products for brand X" call, so this streams the gzipped
 * repository index (files.index.xml, ~3.9 GB uncompressed, cached locally),
 * filters it to the brand's supplier id for on-market, full-data entries, then
 * fetches each data-sheet and CREATES a product from it.
 *
 * ── What it creates ──────────────────────────────────────────────────────
 * Every imported product is a DRAFT (status=draft, is_indexable=false): the
 * Icecat title/description are stored as source_* drafts only (D-015) and the
 * category is left for review unless its Catid is mapped. Images, specs and
 * relations are written by the shared IcecatIngestor - the same code the enrich
 * command uses. Deduplicated by icecat_id, so re-running only adds what is new.
 * Legacy products are left untouched; curation/replacement is a later step.
 *
 * Runs only where Icecat is reachable (the whitelisted server).
 */
final class DiscoverFromIcecat extends Command
{
    protected $signature = 'catalogue:icecat-discover
        {--brand= : Brand to import (key from config/icecat.php suppliers, e.g. dell).}
        {--apply : Create products. Without this the command only reports.}
        {--limit=0 : Maximum index entries to process (0 = no limit).}
        {--catids= : Comma-separated Icecat category ids to import; others are skipped.}
        {--since= : Only products Icecat catalogued on/after this date (YYYY, YYYY-MM or YYYY-MM-DD) - a recency filter against obsolete SKUs.}
        {--survey : List the brand\'s category ids and their product counts, then exit (no fetches).}
        {--dump : Fetch and print each data-sheet summary, creating nothing.}
        {--publish : Activate a product immediately if it imported with an image (kept noindex).}
        {--max-images=10 : Maximum high-res images to mirror per product.}
        {--refresh-index : Re-download the repository index even if the cache is fresh.}
        {--sleep=250 : Milliseconds to pause between products.}';

    protected $description = 'Discover and import new products from Icecat for a brand';

    private int $seen = 0;
    private int $created = 0;
    private int $publishedNow = 0;
    private int $skippedExisting = 0;
    private int $missed = 0;

    public function handle(IcecatClient $client, IcecatIngestor $ingestor): int
    {
        // Headroom for image work: generating derivatives from Icecat's 4200px
        // originals needs more than the 128 MB CLI default, even with downloads
        // streamed to disk.
        @ini_set('memory_limit', '512M');

        $brand = Str::lower(trim((string) $this->option('brand')));

        if ($brand === '') {
            $this->error('--brand is required (e.g. --brand=dell).');

            return self::INVALID;
        }

        $supplierId = config("icecat.suppliers.{$brand}");

        if ($supplierId === null) {
            $this->error("Unknown brand '{$brand}'. Known: ".implode(', ', array_keys((array) config('icecat.suppliers'))));

            return self::INVALID;
        }

        if ((string) config('icecat.username') === '') {
            $this->warn('ICECAT_USERNAME is not set; relying on IP whitelisting alone.');
        }

        $indexPath = $this->ensureIndex();

        if ($indexPath === null) {
            return self::FAILURE;
        }

        if ($this->option('survey')) {
            return $this->survey($indexPath, (int) $supplierId, $brand);
        }

        $apply = (bool) $this->option('apply');
        $dump = (bool) $this->option('dump');
        $limit = (int) $this->option('limit');
        $sleepMs = (int) $this->option('sleep');

        // Optional category filter: import only these Icecat category ids. Use
        // `--survey` first to see which ids a brand carries.
        $catids = array_values(array_filter(array_map(
            static fn ($c) => (int) trim($c),
            explode(',', (string) $this->option('catids')),
        )));

        // Optional recency filter against obsolete SKUs (a brand's full index is
        // its whole history, back to products from 2008).
        $since = $this->parseSince((string) $this->option('since'));

        $this->info("Scanning the index for supplier {$supplierId} ({$brand})…");

        foreach (IcecatIndex::stream('compress.zlib://'.$indexPath, [(int) $supplierId]) as $entry) {
            if ($limit > 0 && $this->seen >= $limit) {
                break;
            }

            // Category and recency filters are applied before the limit/fetch so
            // --limit counts only the products we would actually import. A product
            // with no catalogued date is excluded once --since is in play, since
            // its recency cannot be confirmed.
            if ($catids !== [] && ! in_array($entry['catid'], $catids, true)) {
                continue;
            }

            if ($since !== null && ($entry['date_added'] === null || $entry['date_added'] < $since)) {
                continue;
            }

            $this->seen++;

            // Dedup: an Icecat product already imported is left alone.
            if (Product::query()->where('icecat_id', $entry['product_id'])->exists()) {
                $this->skippedExisting++;

                continue;
            }

            try {
                $document = $client->fetchById($entry['product_id']);
            } catch (Throwable $e) {
                $this->warn("  error: id {$entry['product_id']} - {$e->getMessage()}");
                $this->missed++;
                $this->pause($sleepMs);

                continue;
            }

            if ($document === null || $this->isEmpty($document)) {
                $this->missed++;
                $this->pause($sleepMs);

                continue;
            }

            if ($dump) {
                $this->dump($entry, $document);
                $this->pause($sleepMs);

                continue;
            }

            if ($apply) {
                try {
                    $this->create($entry, $document, $brand, $ingestor);
                    $this->created++;
                } catch (Throwable $e) {
                    $this->warn("  failed: id {$entry['product_id']} - {$e->getMessage()}");
                }
            } else {
                $this->line(sprintf(
                    '  would import: [%d] %s',
                    $entry['product_id'],
                    Str::limit((string) ($document->title() ?? $entry['model_name']), 70),
                ));
                $this->created++; // "would create" tally
            }

            $this->pause($sleepMs);
        }

        $this->report($apply, $dump);

        return self::SUCCESS;
    }

    /**
     * Stream the index for a brand and tally products per Icecat category, with
     * a sample product name so each category is identifiable at a glance - the
     * fast way to pick which categories to import, with no data-sheet fetches.
     */
    private function survey(string $indexPath, int $supplierId, string $brand): int
    {
        $this->info("Surveying supplier {$supplierId} ({$brand}) — category distribution (no fetches)…");

        $counts = [];
        $samples = [];
        $total = 0;

        foreach (IcecatIndex::stream('compress.zlib://'.$indexPath, [$supplierId]) as $entry) {
            $cid = $entry['catid'] ?? 0;
            $counts[$cid] = ($counts[$cid] ?? 0) + 1;
            $samples[$cid] ??= $entry['model_name'] ?? '';
            $total++;
        }

        arsort($counts);

        $rows = [];
        foreach ($counts as $cid => $n) {
            $rows[] = [
                $cid ?: '(none)',
                $n,
                config("icecat.category_map.{$cid}") ?? '—',
                Str::limit((string) $samples[$cid], 48),
            ];
        }

        $this->table(['Catid', 'Products', 'Mapped to', 'Sample product'], $rows);
        $this->info("{$brand}: {$total} on-market, full-data products across ".count($counts).' categories.');
        $this->line('Pick the categories to import, then: --apply --catids=<id,id,...>');

        return self::SUCCESS;
    }

    /**
     * Create the draft product shell, then let the shared ingestor write images,
     * specs, relations and facts onto it.
     *
     * @param  array{product_id: int, mpn: ?string, supplier_id: int, catid: ?int, model_name: ?string, ean: ?string}  $entry
     */
    private function create(array $entry, IcecatDocument $document, string $brandKey, IcecatIngestor $ingestor): void
    {
        $brand = $this->brand($document->brandName() ?? ucfirst($brandKey));
        $name = $document->title() ?? $entry['model_name'] ?? ('Product '.$entry['product_id']);
        $category = $this->category($entry['catid']);

        $product = Product::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => $name,
            'slug' => $this->uniqueSlug($name, $entry['product_id']),
            'brand_id' => $brand->id,
            'brand_name_cached' => $brand->name,
            'mpn' => $document->mpn() ?? $entry['mpn'],
            'ean' => $entry['ean'],
            'icecat_id' => $entry['product_id'],
            'primary_category_id' => $category?->id,
            // Draft + not indexable: the review step rewrites the prose (D-015),
            // assigns a category if none mapped, and publishes. Never live
            // straight from import.
            'status' => 'draft',
            'is_indexable' => false,
            'source' => 'icecat',
        ]);

        // Attach the category pivot too, not just primary_category_id: category
        // LISTINGS query the product_category pivot, so without this the product
        // would have a breadcrumb/URL but never appear on its category page.
        if ($category !== null) {
            $product->categories()->syncWithoutDetaching([$category->id => ['sort_order' => 0]]);
        }

        // New product has no media, so force is irrelevant. Mirror up to
        // --max-images high-res shots (HighPic + the gallery originals, low-res
        // duplicates already excluded by the parser).
        $maxImages = max(1, (int) $this->option('max-images'));
        $stats = $ingestor->write($product, $document, forceImages: true, maxImages: $maxImages);

        // Auto-publish: a product that imported with an image and landed in a
        // category is good enough to sell, so activate it now. It stays noindex
        // (D-015) - the name/description are still Icecat's words until rewritten.
        if ($this->option('publish') && $stats['images'] > 0 && $product->primary_category_id !== null) {
            $product->status = 'active';
            $product->published_at ??= now();
            $product->save();
            $this->publishedNow++;
            $this->line("  created + published: [{$entry['product_id']}] {$product->slug}");

            return;
        }

        $this->line("  created: [{$entry['product_id']}] {$product->slug}");
    }

    /** Download the repository index to the cache if missing, stale, or forced. */
    private function ensureIndex(): ?string
    {
        $path = (string) config('icecat.index_cache');
        $ttl = (int) config('icecat.index_ttl_hours', 24) * 3600;
        $fresh = is_file($path) && (time() - (int) filemtime($path)) < $ttl;

        if ($fresh && ! $this->option('refresh-index')) {
            $this->line('Using the cached Icecat index ('.$this->humanSize(filesize($path) ?: 0).').');

            return $path;
        }

        @mkdir(dirname($path), 0775, true);
        $this->info('Downloading the Icecat index (~310 MB; cached for next time)…');

        try {
            $response = Http::withBasicAuth((string) config('icecat.username'), (string) config('icecat.password'))
                ->timeout(1800)
                ->sink($path)
                ->get((string) config('icecat.endpoints.index'));
        } catch (Throwable $e) {
            $this->error('Index download failed: '.$e->getMessage());

            return null;
        }

        if (! $response->successful() || ! is_file($path) || (filesize($path) ?: 0) < 1_000_000) {
            $this->error('Index download failed (HTTP '.$response->status().'). Check credentials and IP whitelisting.');
            @unlink($path);

            return null;
        }

        $this->line('Index downloaded ('.$this->humanSize(filesize($path) ?: 0).').');

        return $path;
    }

    private function brand(string $name): Brand
    {
        return Brand::query()->firstOrCreate(
            ['slug' => Str::slug($name)],
            ['uuid' => (string) Str::uuid(), 'name' => $name],
        );
    }

    private function category(?int $catid): ?Category
    {
        if ($catid === null) {
            return null;
        }

        $path = config("icecat.category_map.{$catid}");

        return $path !== null ? Category::query()->where('path', $path)->first() : null;
    }

    /** A slug unique against existing products; the Icecat id disambiguates. */
    private function uniqueSlug(string $name, int $icecatId): string
    {
        $base = rtrim(Str::limit(Str::slug($name) ?: 'product', 180, ''), '-');

        if (! Product::query()->where('slug', $base)->exists()) {
            return $base;
        }

        return $base.'-'.$icecatId;
    }

    /** "2023", "2023-05" or "2023-05-10" → YYYYMMDD int, or null. */
    private function parseSince(string $value): ?int
    {
        $digits = preg_replace('/\D/', '', $value) ?? '';

        return match (strlen($digits)) {
            0 => null,
            4 => (int) ($digits.'0101'),
            6 => (int) ($digits.'01'),
            default => (int) substr(str_pad($digits, 8, '0'), 0, 8),
        };
    }

    private function isEmpty(IcecatDocument $document): bool
    {
        return $document->icecatId() === null
            && $document->imageUrls() === []
            && $document->featureGroups() === [];
    }

    /**
     * @param  array{product_id: int, mpn: ?string, catid: ?int, model_name: ?string, ean: ?string}  $entry
     */
    private function dump(array $entry, IcecatDocument $document): void
    {
        $specs = array_sum(array_map(static fn ($g) => count($g['features']), $document->featureGroups()));
        $this->info("── [{$entry['product_id']}] ".Str::limit((string) $document->title(), 70));
        $this->line('  brand/mpn: '.($document->brandName() ?? '-').' / '.($document->mpn() ?? $entry['mpn'] ?? '-'));
        $this->line('  catid:     '.($entry['catid'] ?? '-').'  ean: '.($entry['ean'] ?? '-'));
        $this->line('  images:    '.count($document->imageUrls()).'  specs: '.$specs.'  relations: '.count($document->relations()));
    }

    private function pause(int $ms): void
    {
        if ($ms > 0) {
            usleep($ms * 1000);
        }
    }

    private function humanSize(int $bytes): string
    {
        return $bytes >= 1_073_741_824
            ? round($bytes / 1_073_741_824, 1).' GB'
            : round($bytes / 1_048_576).' MB';
    }

    private function report(bool $apply, bool $dump): void
    {
        $rows = [
            ['Index entries seen', $this->seen],
            ['Already imported (skipped)', $this->skippedExisting],
            ['Missed (no data)', $this->missed],
            [$apply ? 'Created' : ($dump ? 'Dumped' : 'Would create'), $this->created],
        ];

        if ($apply && $this->option('publish')) {
            $rows[] = ['Published (had an image)', $this->publishedNow];
        }

        $this->newLine();
        $this->table(['Metric', 'Count'], $rows);

        if (! $apply && ! $dump) {
            $this->info('Dry run. Re-run with --apply to create the draft products.');
        } elseif ($apply && $this->option('publish')) {
            $this->info('Imported; those with an image are live (noindex). Imageless ones stay draft. Rewrite prose (D-015) before indexing.');
        } elseif ($apply) {
            $this->info('Imported as DRAFTS (noindex). Review, then: catalogue:icecat-publish --brand=…');
        }
    }
}
