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

                    ProductSpecs::set($product, $this->specsFrom($item));

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

    /**
     * The real specifications carried in a Shopify products.json record, as a
     * label => value map. Only structured, truthful facts are taken - vendor,
     * product type, the product line, the manufacturer code, the panel's material
     * and its genuine options (size, finish). The body_html is marketing prose,
     * so nothing is copied from it. Shopify's "Title / Default Title" placeholder
     * option is skipped, as is the colour_* tag (it carries the felt backing's
     * colour, not the panel's).
     *
     * @param  array<string, mixed>  $item
     * @return array<string, string>
     */
    private function specsFrom(array $item): array
    {
        $title = trim((string) ($item['title'] ?? ''));
        $type = trim((string) ($item['product_type'] ?? ''));
        $tags = array_map(fn ($t): string => (string) $t, (array) ($item['tags'] ?? []));

        $specs = [
            'Brand' => trim((string) ($item['vendor'] ?? '')),
            'Type' => $type,
            'Range' => $this->rangeOf($title.' '.$type),
            'Material' => $this->materialOf($title.' '.$type),
        ];

        // Manufacturer code, with the per-variant size suffix (e.g. -1.2M) dropped
        // so it reads as one product-level code rather than a single size.
        $sku = trim((string) ($item['variants'][0]['sku'] ?? ''));
        $sku = preg_replace('/-\d+(?:\.\d+)?m$/i', '', $sku) ?? $sku;
        if ($sku !== '') {
            $specs['Manufacturer code'] = $sku;
        }

        // Genuine options become specs; Shopify's placeholder option is skipped.
        foreach (($item['options'] ?? []) as $option) {
            $name = trim((string) ($option['name'] ?? ''));
            $values = array_values(array_filter(
                array_map(fn ($v): string => trim((string) $v), (array) ($option['values'] ?? [])),
                fn (string $v): bool => $v !== '' && Str::lower($v) !== 'default title',
            ));

            if ($name === '' || Str::lower($name) === 'title' || $values === []) {
                continue;
            }
            $specs[$name] = implode(', ', $values);
        }

        // Truthful facts carried in the tag list. Options already set win.
        foreach ($tags as $tag) {
            if (Str::startsWith($tag, 'finish_') && ! isset($specs['Finish'])) {
                $specs['Finish'] = Str::title(str_replace(['_', '-'], ' ', Str::after($tag, 'finish_')));
            } elseif (Str::startsWith($tag, 'felt_colour_') && ! isset($specs['Felt colour'])) {
                $specs['Felt colour'] = trim(Str::after($tag, 'felt_colour_'));
            }
        }
        if (Str::contains(Str::lower($title.' '.$type.' '.implode(' ', $tags)), 'acoustic')) {
            $specs['Acoustic'] = 'Yes';
        }

        return $specs;
    }

    /** The Naturewall product line a title/type belongs to, or '' if none. */
    private function rangeOf(string $text): string
    {
        foreach (['WoodWall', 'SlatWall', 'Arborelle', 'Legacy'] as $range) {
            if (Str::contains($text, $range, ignoreCase: true)) {
                return $range;
            }
        }

        return '';
    }

    /** The panel material, inferred only from explicit keywords, or '' if none. */
    private function materialOf(string $text): string
    {
        $t = Str::lower($text);

        return match (true) {
            Str::contains($t, 'wood veneer') => 'Real wood veneer',
            Str::contains($t, 'mdf') => 'MDF (paintable)',
            Str::contains($t, 'wood') => 'Wood',
            default => '',
        };
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
