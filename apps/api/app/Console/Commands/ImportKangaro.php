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
 * Import the Kangaro stapling/punching range as draft products.
 *
 * kangarokgoc.com is an Angular front end over an ASP.NET MVC back end: each
 * category page fetches its products from a JSON endpoint (one per category,
 * inconsistently named), POSTing the category id. This pulls those endpoints
 * directly - far cleaner than scraping the client-rendered HTML - and creates a
 * DRAFT per product with its thumbnail (/Assets/Thumbnail/{SKU}.png) through the
 * media pipeline and an ORIGINAL description (the site's own DESCRIPTION copy is
 * NOT reproduced). Idempotent (keyed by slug; photo attached only when absent).
 *
 *   php artisan catalogue:import-kangaro                 # report
 *   php artisan catalogue:import-kangaro --apply
 */
final class ImportKangaro extends Command
{
    protected $signature = 'catalogue:import-kangaro
        {--apply : Create the products and import the photos.}
        {--limit=0 : Cap the number of products (0 = all). For testing.}';

    protected $description = 'Import the Kangaro stapling/punching range as draft products';

    private const BASE = 'https://www.kangarokgoc.com';

    private const STAPLERS_PUNCHES = '/office-supplies/desk-accessories/staplers-punches/';

    private const TAPES = '/office-supplies/adhesives-tapes/tapes/';

    /**
     * Each category: its JSON endpoint, the id it is queried by, the noun that
     * makes a model code read as a product name, and the leaf it files under.
     *
     * @var list<array{endpoint: string, id: int, noun: string, path: string}>
     */
    private const CATEGORIES = [
        ['endpoint' => '/Home/GetProductStaplerList/', 'id' => 1, 'noun' => 'Stapler', 'path' => self::STAPLERS_PUNCHES],
        ['endpoint' => '/Home/GetProductPaper/', 'id' => 2, 'noun' => 'Paper Punch', 'path' => self::STAPLERS_PUNCHES],
        ['endpoint' => '/Home/GetProductRemover/', 'id' => 5, 'noun' => 'Staple Remover', 'path' => self::STAPLERS_PUNCHES],
        ['endpoint' => '/Home/GetProductStaples/', 'id' => 4, 'noun' => 'Staples', 'path' => self::STAPLERS_PUNCHES],
        ['endpoint' => '/Home/GetProductTapeDispenser/', 'id' => 9, 'noun' => 'Tape Dispenser', 'path' => self::TAPES],
    ];

    public function handle(MediaIngestor $ingestor): int
    {
        @ini_set('memory_limit', '512M');

        $apply = (bool) $this->option('apply');
        $limit = (int) $this->option('limit');

        $categoryIds = [];
        foreach ([self::STAPLERS_PUNCHES, self::TAPES] as $path) {
            $id = Category::query()->where('path', $path)->value('id');
            if ($id === null) {
                $this->error("Category missing: {$path}");

                return self::FAILURE;
            }
            $categoryIds[$path] = (int) $id;
        }

        $created = 0;
        $updated = 0;
        $photos = 0;
        $failed = 0;
        $skipped = 0;
        $byCategory = [];
        $done = 0;

        foreach (self::CATEGORIES as $category) {
            $records = Http::timeout(30)->retry(2, 1500, throw: false)
                ->asJson()
                ->post(self::BASE.$category['endpoint'], ['ID' => $category['id']])
                ->json();

            if (! is_array($records)) {
                $this->warn("No data for {$category['noun']} ({$category['endpoint']}).");

                continue;
            }

            foreach ($records as $record) {
                $sku = trim((string) ($record['SKU'] ?? ''));
                $model = trim((string) ($record['PRODUCTNAME'] ?? $sku));

                if ($sku === '' || $model === '') {
                    $skipped++;

                    continue;
                }

                if ($limit > 0 && $done >= $limit) {
                    break 2;
                }
                $done++;

                $name = $this->productName($model, $category['noun']);
                $byCategory[$category['path']] = ($byCategory[$category['path']] ?? 0) + 1;

                if (! $apply) {
                    continue;
                }

                try {
                    $slug = Str::slug('kangaro-'.$sku.'-'.$category['noun']);
                    $exists = Product::query()->where('slug', $slug)->exists();

                    $product = Product::query()->updateOrCreate(
                        ['slug' => $slug],
                        [
                            'name' => $name,
                            'short_description' => $this->shortDescription($name),
                            'description_html' => '<p>'.e($this->description($name, $category['noun'])).'</p>',
                            // A template blurb is machine copy: stamp it so the
                            // spec-driven DescriptionDrafter may replace it and so
                            // the readiness gate holds it until it is approved.
                            'description_generated_at' => now(),
                            'primary_category_id' => $categoryIds[$category['path']],
                            'availability' => 'lead_time',
                        ],
                    );
                    $product->categories()->syncWithoutDetaching([$categoryIds[$category['path']] => ['sort_order' => 0]]);
                    $exists ? $updated++ : $created++;

                    ProductSpecs::set($product, $this->specsFrom($record, $category['noun']));

                    if (! $product->media()->exists()) {
                        $image = self::BASE.'/Assets/Thumbnail/'.rawurlencode($sku).'.png';
                        $tmp = tempnam(sys_get_temp_dir(), 'kgo').'.png';
                        $response = Http::timeout(45)->retry(2, 1500, throw: false)->sink($tmp)->get($image);

                        if ($response->successful() && (filesize($tmp) ?: 0) > 512) {
                            $media = $ingestor->ingest($tmp, [
                                'alt_text' => $name,
                                'source' => 'kangaro',
                                'source_url' => $image,
                            ]);
                            $product->media()->syncWithoutDetaching([$media->id => ['role' => 'primary', 'sort_order' => 0]]);
                            GenerateMediaDerivatives::dispatch($media->id);
                            $photos++;
                        } else {
                            $this->warn("  image failed: {$name} ({$image})");
                            $failed++;
                        }
                        @unlink($tmp);
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    $this->warn("  failed [{$name}]: {$e->getMessage()}");
                }
            }
        }

        $this->newLine();
        $this->table(['Outcome', 'Count'], [
            [$apply ? 'Created' : 'Would import', $apply ? $created : $done],
            ['Updated (already existed)', $updated],
            ['Photos imported', $photos],
            ['Skipped (no SKU/name)', $skipped],
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
            $this->info('Created as DRAFT. Review and publish in the admin.');
        }

        return self::SUCCESS;
    }

    /**
     * The real specifications Kangaro returns for a product, as a label => value
     * map. Only the fields that are genuine specs are taken; IDs, colour hex,
     * the image and the marketing DESCRIPTION are left out.
     *
     * @param  array<string, mixed>  $record
     * @return array<string, string>
     */
    private function specsFrom(array $record, string $noun): array
    {
        $map = [
            'Type' => 'SUBCATEGORYNAME',
            'Material' => 'MATERIAL',
            'Stapling capacity' => 'STAPLINGCAPACITY',
            'Throat depth' => 'THROATDEPTH',
            'Stapling style' => 'STAPLINGSTYLES',
            'Clinch type' => 'CLINCHTYPE',
            'Tacking' => 'TACKING',
            'Special features' => 'SPECIALFEATURES',
            'Packing' => 'PACKINGDETAILS',
            'Carton quantity' => 'CARTONQTY',
        ];

        $specs = ['Brand' => 'Kangaro', 'Category' => $noun];
        foreach ($map as $label => $key) {
            $value = trim((string) ($record[$key] ?? ''));
            if ($value !== '') {
                $specs[$label] = $value;
            }
        }

        return $specs;
    }

    /** A model code like "HD-45" reads as a product with its category noun. */
    private function productName(string $model, string $noun): string
    {
        $model = preg_replace('/\s+/', ' ', $model) ?? $model;

        // Avoid "Kangaro HP-210 Stapler Stapler" when the model already says it.
        if (Str::contains(Str::lower($model), Str::lower($noun))) {
            return "Kangaro {$model}";
        }

        return "Kangaro {$model} {$noun}";
    }

    private function shortDescription(string $name): string
    {
        return Str::limit($name.', supplied by OfficeLand with nationwide delivery across Nigeria. Request a quote for pricing, pack sizes and lead time.', 155, '');
    }

    private function description(string $name, string $noun): string
    {
        return $name.' is part of the Kangaro stationery range that OfficeLand supplies to offices, schools and government buyers across Nigeria. '
            .'Kangaro is a long-established manufacturer of stapling and punching tools built for everyday office use. '
            .'Request a quote for current pricing, pack sizes, compatible staples or refills, and lead time; delivery is available nationwide.';
    }
}
