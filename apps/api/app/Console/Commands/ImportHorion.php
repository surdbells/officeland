<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Content\ProductSpecs;
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

    private const SITE = 'https://global.horion.com';

    private const BASE = 'https://global.horion.com/products/';

    private const IMAGE_HOST = 'https://global-file.horion.com';

    /** Horion product type => category path. */
    private const CATEGORY_MAP = [
        'interactive-flat-panel' => '/smart-tech/conferencing-av/interactive-displays/',
        'commercial-display' => '/it-products/monitors-displays/tvs-digital-signage/',
        'accessories' => '/smart-tech/conferencing-av/interactive-displays/',
    ];

    /** Horion product type => human-readable label used as the "Type" spec. */
    private const TYPE_LABELS = [
        'interactive-flat-panel' => 'Interactive Flat Panel Display',
        'commercial-display' => 'Commercial Display',
        'accessories' => 'Accessory',
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
        $specTotal = 0;
        $belowFloor = 0;
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
                        // A template blurb is machine copy: stamp it so the
                        // spec-driven DescriptionDrafter may replace it and so
                        // the readiness gate holds it until it is approved.
                        'description_generated_at' => now(),
                        'primary_category_id' => $categoryIds[$path],
                        'availability' => 'lead_time',
                    ],
                );
                $product->categories()->syncWithoutDetaching([$categoryIds[$path] => ['sort_order' => 0]]);
                $exists ? $updated++ : $created++;

                // Real specs from the product's own PDP (one fetch per product),
                // with the Horion type always recorded. Blanks are skipped by
                // ProductSpecs; products whose PDP has no spec table fall short
                // of the three-spec content floor and are counted below.
                $specs = ['Type' => self::TYPE_LABELS[$item['type']] ?? Str::headline($item['type'])];
                if ($item['url'] !== '') {
                    $specs += $this->fetchSpecs($item['url']);
                }
                $written = ProductSpecs::set($product, $specs);
                $specTotal += $written;
                if ($written < 3) {
                    $belowFloor++;
                    $this->warn("  only {$written} spec(s) [{$item['name']}] - PDP has no structured spec table");
                }

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
            ['Specs written', $specTotal],
            ['Below spec floor (<3)', $belowFloor],
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
     * is a placeholder) and whose `href` points at the product's own PDP.
     *
     * @return list<array{name: string, image: string, type: string, url: string}>
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
            $href = trim($card[2]);
            $image = trim($card[3]);
            $name = trim(html_entity_decode($card[4], ENT_QUOTES | ENT_HTML5));

            if ($name === '' || $image === '') {
                continue;
            }

            // Absolute URL on the manufacturer's file host.
            if (str_starts_with($image, '/')) {
                $image = self::IMAGE_HOST.$image;
            }

            // PDP lives on the main site; the card carries a relative href.
            $url = $href === '' ? '' : self::SITE.'/'.ltrim($href, '/');

            if (isset($seen[$image])) {
                continue;
            }
            $seen[$image] = true;

            $out[] = ['name' => $name, 'image' => $image, 'type' => $type, 'url' => $url];
        }

        return $out;
    }

    /**
     * The real specifications on a product's PDP, as a [label => value] map.
     *
     * Horion renders its spec table in several shapes depending on the product
     * line, so each known layout is parsed and the results merged (first writer
     * of a label wins, matching ProductSpecs):
     *   - parameter-list spans   (c-label / c-value) - e.g. M6APro EDLA
     *   - param-grid divs        (param-key / param-val) - e.g. M6APro V2
     *   - parameter-table rows   (td.col-key + per-size value columns) - e.g. K8A
     *   - accessory spec blocks  ("<p>Label: value</p>" under a spec heading)
     * One fetch per product, with the same timeout/retry as the image fetch.
     * A commercial display or accessory whose PDP carries no spec table yields
     * nothing here; only the Type spec is then recorded.
     *
     * @return array<string, string>
     */
    private function fetchSpecs(string $pdpUrl): array
    {
        $html = Http::timeout(30)->retry(2, 1500, throw: false)->get($pdpUrl)->body();

        if ($html === '') {
            return [];
        }

        $specs = [];

        // parameter-list: <span class="c-label">K</span><span class="c-value">V</span>
        if (preg_match_all(
            '#<span\s+class="c-label">(.*?)</span>\s*<span\s+class="c-value">(.*?)</span>#is',
            $html,
            $spans,
            PREG_SET_ORDER,
        )) {
            foreach ($spans as $s) {
                $this->addSpec($specs, $s[1], $s[2]);
            }
        }

        // param-grid: <div class="param-key">K</div><div class="param-val">V</div>
        if (preg_match_all(
            '#<div\s+class="param-key">(.*?)</div>\s*<div\s+class="param-val">(.*?)</div>#is',
            $html,
            $grid,
            PREG_SET_ORDER,
        )) {
            foreach ($grid as $g) {
                $this->addSpec($specs, $g[1], $g[2]);
            }
        }

        // parameter-table: <td class="col-key">K</td> then one value <td> per size.
        if (preg_match_all('#<td\s+class="col-key">(.*?)</td>(.*?)</tr>#is', $html, $rows, PREG_SET_ORDER)) {
            foreach ($rows as $row) {
                $values = [];
                if (preg_match_all('#<td[^>]*>(.*?)</td>#is', $row[2], $cells)) {
                    foreach ($cells[1] as $cell) {
                        $value = $this->cleanText($cell);
                        if ($value !== '' && ! in_array($value, $values, true)) {
                            $values[] = $value;
                        }
                    }
                }
                $this->addSpec($specs, $row[1], implode(' / ', $values));
            }
        }

        // Accessory PDPs have no table, only "<p>Label: value</p>" under a spec
        // heading. Scope to that section so marketing copy is not mistaken for a
        // spec, and only when no structured table was found.
        if ($specs === []) {
            $pos = stripos($html, 'Product Specification');
            if ($pos !== false
                && preg_match_all(
                    '#<p>\s*([A-Za-z][^<:]{1,39}):\s*(.*?)</p>#is',
                    substr($html, $pos, 8000),
                    $paras,
                    PREG_SET_ORDER,
                )) {
                foreach ($paras as $p) {
                    $this->addSpec($specs, $p[1], $p[2]);
                }
            }
        }

        return $specs;
    }

    /**
     * Add a cleaned label => value pair, skipping blanks, over-long labels and
     * duplicate labels (first writer wins, so the richest layout parsed first
     * keeps its value).
     *
     * @param  array<string, string>  $specs
     */
    private function addSpec(array &$specs, string $rawLabel, string $rawValue): void
    {
        $label = $this->cleanText($rawLabel);
        $value = $this->cleanText($rawValue);

        if ($label === '' || $value === '' || mb_strlen($label) > 60) {
            return;
        }

        if (! isset($specs[$label])) {
            $specs[$label] = Str::limit($value, 255, '');
        }
    }

    /** Strip tags/entities and collapse whitespace from a spec-cell fragment. */
    private function cleanText(string $html): string
    {
        $text = preg_replace('#<br\s*/?>#i', ' ', $html) ?? $html;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
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
