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
 * Import the Naturewall wall-panelling range as draft products under PVC.
 *
 * Naturewall is a Shopify store, so the catalogue comes from products.json
 * (clean structured data, no HTML scraping). Only the PANELLING lines are taken:
 * the designer WALLPAPER brands it also sells (Zoffany, Morris & Co, Sanderson,
 * Harlequin, Scion, Clarke & Clarke) are third-party licensed goods, not PVC, so
 * they are skipped, as are the sample swatches. Each kept product is created as a
 * DRAFT with its manufacturer photo through the media pipeline (derivatives
 * queued) and an ORIGINAL short description, filed under the PVC department by
 * type. Idempotent (keyed by slug; a photo is attached only when the product has
 * none).
 *
 *   php artisan catalogue:import-naturewall                 # report
 *   php artisan catalogue:import-naturewall --apply
 */
final class ImportNaturewall extends Command
{
    protected $signature = 'catalogue:import-naturewall
        {--apply : Create the products and import the photos.}
        {--limit=0 : Cap the number of products (0 = all). For testing.}';

    protected $description = 'Import the Naturewall wall-panelling range as draft products under PVC';

    private const BASE = 'https://www.naturewall.com/products.json';

    private const WALL_PANELS = '/pvc-doors-interiors/pvc-interiors/wall-panels/';

    private const EDGING = '/pvc-doors-interiors/pvc-boards/pvc-edging/';

    private const ADHESIVES = '/office-supplies/adhesives-tapes/adhesives/';

    public function handle(MediaIngestor $ingestor): int
    {
        @ini_set('memory_limit', '512M');

        $apply = (bool) $this->option('apply');
        $limit = (int) $this->option('limit');

        $categoryIds = [];
        foreach ([self::WALL_PANELS, self::EDGING, self::ADHESIVES] as $path) {
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

        for ($page = 1; $page <= 10; $page++) {
            $products = Http::timeout(30)->retry(2, 1500, throw: false)
                ->get(self::BASE, ['limit' => 250, 'page' => $page])
                ->json('products', []);

            if ($products === []) {
                break;
            }

            foreach ($products as $item) {
                $type = (string) ($item['product_type'] ?? '');
                $title = trim((string) ($item['title'] ?? ''));

                // Skip designer wallpaper (licensed third-party brands, not PVC)
                // and sample swatches.
                if ($title === ''
                    || Str::contains(Str::lower($type), ['wallpaper', 'sample'])
                    || Str::contains(Str::lower($title), ['sample', 'wallpaper'])) {
                    $skipped++;

                    continue;
                }

                $image = $item['images'][0]['src'] ?? null;
                if (! is_string($image) || $image === '') {
                    $skipped++;

                    continue;
                }

                if ($limit > 0 && ($created + $updated) >= $limit) {
                    break 2;
                }

                $path = $this->categoryFor($title, $type);
                $byCategory[$path] = ($byCategory[$path] ?? 0) + 1;

                if (! $apply) {
                    continue;
                }

                try {
                    $slug = Str::slug($title);
                    $exists = Product::query()->where('slug', $slug)->exists();

                    $product = Product::query()->updateOrCreate(
                        ['slug' => $slug],
                        [
                            'name' => $title,
                            'short_description' => $this->shortDescription($title),
                            'description_html' => '<p>'.e($this->description($title)).'</p>',
                            'primary_category_id' => $categoryIds[$path],
                            'availability' => 'lead_time',
                        ],
                    );
                    $product->categories()->syncWithoutDetaching([$categoryIds[$path] => ['sort_order' => 0]]);
                    $exists ? $updated++ : $created++;

                    if (! $product->media()->exists()) {
                        $clean = strtok($image, '?');
                        $tmp = tempnam(sys_get_temp_dir(), 'nw').'.'.(pathinfo($clean, PATHINFO_EXTENSION) ?: 'jpg');
                        $response = Http::timeout(45)->retry(2, 1500, throw: false)->sink($tmp)->get($image);

                        if ($response->successful()) {
                            $media = $ingestor->ingest($tmp, [
                                'alt_text' => $title,
                                'source' => 'naturewall',
                                'source_url' => $image,
                            ]);
                            $product->media()->syncWithoutDetaching([$media->id => ['role' => 'primary', 'sort_order' => 0]]);
                            GenerateMediaDerivatives::dispatch($media->id);
                            $photos++;
                        } else {
                            $this->warn("  image failed ({$response->status()}): {$title}");
                            $failed++;
                        }
                        @unlink($tmp);
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    $this->warn("  failed [{$title}]: {$e->getMessage()}");
                }
            }
        }

        $this->newLine();
        $this->table(['Outcome', 'Count'], [
            [$apply ? 'Created' : 'Would import', $apply ? $created : array_sum($byCategory)],
            ['Updated (already existed)', $updated],
            ['Photos imported', $photos],
            ['Skipped (wallpaper / samples)', $skipped],
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
            $this->info('Created as DRAFT under PVC. Review and publish in the admin.');
        }

        return self::SUCCESS;
    }

    private function categoryFor(string $title, string $type): string
    {
        $t = Str::lower($title.' '.$type);

        if (Str::contains($t, ['adhesive', 'sealant', 'glue'])) {
            return self::ADHESIVES;
        }
        if (Str::contains($t, ['trim', 'corner', 'edging', 'end cap', 'joint'])) {
            return self::EDGING;
        }

        return self::WALL_PANELS;
    }

    private function shortDescription(string $title): string
    {
        return Str::limit($title.' from Naturewall, supplied by OfficeLand with nationwide delivery and installation. Request a quote for sizes, finishes and lead time.', 155, '');
    }

    private function description(string $title): string
    {
        return $title.' is part of the Naturewall wall-panelling range that OfficeLand supplies across Nigeria. '
            .'It gives interior walls a durable, easy-clean decorative finish and installs over most sound surfaces. '
            .'Request a quote for available sizes, finishes, fixing accessories and lead time; delivery and installation are available nationwide.';
    }
}
