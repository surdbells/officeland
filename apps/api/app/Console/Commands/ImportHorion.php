<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\GenerateMediaDerivatives;
use App\Media\MediaIngestor;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Import Horion's interactive display catalogue (global.horion.com) as draft
 * products under the smart-tech / conferencing-AV area.
 *
 * The /products/ listing is server-rendered: each product is a `card` with its
 * type (interactive-flat-panel / commercial-display / accessories), its name,
 * and a lazy-loaded high-res PNG in the `data-pc` attribute (the visible
 * <img src> is only a placeholder). For each card a DRAFT product is created
 * with its manufacturer photo through the media pipeline (derivatives queued),
 * an ORIGINAL short description (never Horion's marketing copy), and a category
 * mapped from the product type. Idempotent (keyed by slug; a photo is attached
 * only when the product has none).
 *
 *   php artisan catalogue:import-horion                 # report
 *   php artisan catalogue:import-horion --apply
 *   php artisan catalogue:import-horion --apply --limit=3
 */
final class ImportHorion extends Command
{
    protected $signature = 'catalogue:import-horion
        {--apply : Create the products and import the photos.}
        {--limit=0 : Cap the number of products (0 = all). For testing.}';

    protected $description = 'Import the Horion interactive display range as draft products under smart-tech';

    private const BASE = 'https://global.horion.com/products/';

    private const IMAGE_HOST = 'https://global-file.horion.com';

    /** Horion product type => category path. */
    private const CATEGORY_MAP = [
        'interactive-flat-panel' => '/smart-tech/conferencing-av/interactive-displays/',
        'commercial-display' => '/it-products/monitors-displays/tvs-digital-signage/',
        'accessories' => '/smart-tech/conferencing-av/interactive-displays/',
    ];

    private const DEFAULT_CATEGORY = '/smart-tech/conferencing-av/interactive-displays/';

    public function handle(MediaIngestor $ingestor): int
    {
        @ini_set('memory_limit', '512M');

        $apply = (bool) $this->option('apply');
        $limit = (int) $this->option('limit');

        /** @var array<string, int> $categoryIds */
        $categoryIds = [];
        foreach (array_unique(array_merge(array_values(self::CATEGORY_MAP), [self::DEFAULT_CATEGORY])) as $path) {
            $id = Category::query()->where('path', $path)->value('id');
            if ($id === null) {
                $this->error("Category missing: {$path}");

                return self::FAILURE;
            }
            $categoryIds[$path] = (int) $id;
        }

        $products = $this->scrapeProducts();
        $this->line('Found '.count($products).' product(s) on the Horion listing.');

        if ($products === []) {
            $this->error('No products parsed from the Horion listing. The page markup may have changed.');

            return self::FAILURE;
        }

        $created = 0;
        $updated = 0;
        $photos = 0;
        $failed = 0;
        $byCategory = [];

        foreach ($products as $item) {
            if ($limit > 0 && ($created + $updated) >= $limit) {
                break;
            }

            $path = $this->categoryFor($item['type']);
            $byCategory[$path] = ($byCategory[$path] ?? 0) + 1;

            if (! $apply) {
                continue;
            }

            try {
                $slug = Str::slug($item['name']);
                $exists = Product::query()->where('slug', $slug)->exists();

                $product = Product::query()->updateOrCreate(
                    ['slug' => $slug],
                    [
                        'name' => $item['name'],
                        'short_description' => $this->shortDescription($item['name']),
                        'description_html' => '<p>'.e($this->description($item['name'])).'</p>',
                        'primary_category_id' => $categoryIds[$path],
                        'availability' => 'lead_time',
                    ],
                );
                $product->categories()->syncWithoutDetaching([$categoryIds[$path] => ['sort_order' => 0]]);
                $exists ? $updated++ : $created++;

                if (! $product->media()->exists()) {
                    $clean = strtok($item['image'], '?');
                    $tmp = tempnam(sys_get_temp_dir(), 'horion').'.'.(pathinfo($clean, PATHINFO_EXTENSION) ?: 'png');
                    $response = Http::timeout(45)->retry(2, 1500, throw: false)->sink($tmp)->get($item['image']);

                    if ($response->successful()) {
                        $media = $ingestor->ingest($tmp, [
                            'alt_text' => $item['name'],
                            'source' => 'horion',
                            'source_url' => $item['image'],
                        ]);
                        $product->media()->syncWithoutDetaching([$media->id => ['role' => 'primary', 'sort_order' => 0]]);
                        GenerateMediaDerivatives::dispatch($media->id);
                        $photos++;
                    } else {
                        $this->warn("  image failed ({$response->status()}): {$item['name']}");
                        $failed++;
                    }
                    @unlink($tmp);
                }
            } catch (\Throwable $e) {
                $failed++;
                $this->warn("  failed [{$item['name']}]: {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->table(['Outcome', 'Count'], [
            [$apply ? 'Created' : 'Would import', $apply ? $created : array_sum($byCategory)],
            ['Updated (already existed)', $updated],
            ['Photos imported', $photos],
            ['Failed', $failed],
        ]);

        $this->newLine();
        $this->line('By category:');
        foreach ($byCategory as $path => $n) {
            $this->line("  {$n}\t{$path}");
        }

        if (! $apply) {
            $this->comment('Dry run. Add --apply to create the draft products and import the photos.');
        } else {
            $this->info('Created as DRAFT under smart-tech. Review and publish in the admin. Derivatives are generating on the media queue.');
        }

        return self::SUCCESS;
    }

    /**
     * Products on the listing page: name, high-res image URL and Horion type.
     *
     * Each product is a server-rendered `<div class="card" data-product-type=...>`
     * whose real image sits in the lazy-load `data-pc` attribute (the <img src>
     * is a placeholder).
     *
     * @return list<array{name: string, image: string, type: string}>
     */
    private function scrapeProducts(): array
    {
        $html = Http::timeout(30)->retry(2, 1500, throw: false)->get(self::BASE)->body();

        if ($html === '') {
            return [];
        }

        $matched = preg_match_all(
            '#<div\s+class="card"\s+data-product-type="([^"]+)"\s+href="([^"]*)"'
            .'.*?data-pc="([^"]+)".*?class="product-name[^"]*">([^<]+)</div>#is',
            $html,
            $cards,
            PREG_SET_ORDER,
        );

        if ($matched === false || $matched === 0) {
            return [];
        }

        $out = [];
        $seen = [];
        foreach ($cards as $card) {
            $type = trim($card[1]);
            $image = trim($card[3]);
            $name = trim(html_entity_decode($card[4], ENT_QUOTES | ENT_HTML5));

            if ($name === '' || $image === '') {
                continue;
            }

            // Absolute URL on the manufacturer's file host.
            if (str_starts_with($image, '/')) {
                $image = self::IMAGE_HOST.$image;
            }

            if (isset($seen[$image])) {
                continue;
            }
            $seen[$image] = true;

            $out[] = ['name' => $name, 'image' => $image, 'type' => $type];
        }

        return $out;
    }

    private function categoryFor(string $type): string
    {
        return self::CATEGORY_MAP[$type] ?? self::DEFAULT_CATEGORY;
    }

    private function shortDescription(string $name): string
    {
        return Str::limit($name.' from Horion, supplied by OfficeLand with nationwide delivery and installation. Request a quote for sizes, configuration and lead time.', 155, '');
    }

    private function description(string $name): string
    {
        return $name.' is part of the Horion interactive display range that OfficeLand supplies across Nigeria. '
            .'It is built for meeting rooms, classrooms and collaborative workspaces, bringing touch display, whiteboarding and screen sharing into one system. '
            .'Request a quote for available sizes, mounting and accessories, pricing and lead time; delivery, installation and setup are available nationwide.';
    }
}
