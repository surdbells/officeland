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
 * Import the Snimay home collection (snimayhome.com) as draft products under the
 * PVC department.
 *
 * Walks the manufacturer's paginated product list, and for each product creates
 * a DRAFT with its RAW photo (the uploaded files are unwatermarked; only the
 * site chrome is branded) downloaded through the media pipeline, an ORIGINAL
 * short description (never the manufacturer's copy), and a PVC category inferred
 * from the product name. Derivatives are queued, not generated inline, because
 * the range is large.
 *
 * Idempotent: products are keyed by slug, so a re-run updates rather than
 * duplicates, and a photo is only attached when the product has none.
 *
 *   php artisan catalogue:import-snimay --pages=1 --apply       # one page, to try it
 *   php artisan catalogue:import-snimay --pages=16 --apply      # the whole range
 */
final class ImportSnimay extends Command
{
    protected $signature = 'catalogue:import-snimay
        {--apply : Create the products and import the photos.}
        {--pages=16 : How many listing pages to walk.}
        {--limit=0 : Cap the number of products (0 = no cap). For testing.}';

    protected $description = 'Import the Snimay home collection as draft products under PVC';

    private const BASE = 'https://www.snimayhome.com/products/';

    /** Keyword => category path, longest/most-specific first. Unmatched fall to DEFAULT. */
    private const CATEGORY_MAP = [
        'sliding wardrobe' => '/pvc-doors-interiors/pvc-wardrobes/sliding-wardrobes/',
        'wardrobe' => '/pvc-doors-interiors/pvc-wardrobes/fitted-wardrobes/',
        'kitchen island' => '/pvc-doors-interiors/pvc-kitchens/kitchen-islands/',
        'island' => '/pvc-doors-interiors/pvc-kitchens/kitchen-islands/',
        'kitchen' => '/pvc-doors-interiors/pvc-kitchens/kitchen-cabinets/',
        'door' => '/pvc-doors-interiors/pvc-doors/internal-doors/',
        'ceiling' => '/pvc-doors-interiors/pvc-interiors/ceiling-panels/',
        'wall panel' => '/pvc-doors-interiors/pvc-interiors/wall-panels/',
        'panel' => '/pvc-doors-interiors/pvc-interiors/wall-panels/',
    ];

    private const DEFAULT_CATEGORY = '/pvc-doors-interiors/pvc-interiors/';

    public function handle(MediaIngestor $ingestor): int
    {
        @ini_set('memory_limit', '512M');

        $apply = (bool) $this->option('apply');
        $pages = max(1, (int) $this->option('pages'));
        $limit = (int) $this->option('limit');

        /** @var array<string, int> $categoryIds */
        $categoryIds = [];
        foreach (array_merge(array_values(self::CATEGORY_MAP), [self::DEFAULT_CATEGORY]) as $path) {
            $id = Category::query()->where('path', $path)->value('id');
            if ($id === null) {
                $this->error("Category missing: {$path}");

                return self::FAILURE;
            }
            $categoryIds[$path] = (int) $id;
        }

        $seenImages = [];
        $created = 0;
        $updated = 0;
        $photos = 0;
        $failed = 0;
        $byCategory = [];

        foreach (range(1, $pages) as $page) {
            $products = $this->scrapePage($page);
            $this->line("Page {$page}: ".count($products)." product(s)");

            foreach ($products as $item) {
                if (isset($seenImages[$item['image']])) {
                    continue;
                }
                $seenImages[$item['image']] = true;

                if ($limit > 0 && ($created + $updated) >= $limit) {
                    break 2;
                }

                $path = $this->categoryFor($item['name']);
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
                        $tmp = tempnam(sys_get_temp_dir(), 'snimay').'.'.(pathinfo($item['image'], PATHINFO_EXTENSION) ?: 'png');
                        $response = Http::timeout(45)->retry(2, 1500, throw: false)->sink($tmp)->get($item['image']);

                        if ($response->successful()) {
                            $media = $ingestor->ingest($tmp, [
                                'alt_text' => $item['name'],
                                'source' => 'snimay',
                                'source_url' => $item['image'],
                            ]);
                            $product->media()->syncWithoutDetaching([$media->id => ['role' => 'primary', 'sort_order' => 0]]);
                            // Queued, not inline: hundreds of images. The worker
                            // generates them; the storefront shows the original
                            // meanwhile.
                            GenerateMediaDerivatives::dispatch($media->id);
                            $photos++;
                        } else {
                            $this->warn("  image failed ({$response->status()}): {$item['image']}");
                            $failed++;
                        }
                        @unlink($tmp);
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    $this->warn("  failed [{$item['name']}]: {$e->getMessage()}");
                }
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
            $this->info('Created as DRAFT. Review the images (watch for any watermark) and publish in the admin. Derivatives are generating on the media queue.');
        }

        return self::SUCCESS;
    }

    /**
     * Products on one listing page: name (from the image alt) and raw image URL.
     *
     * @return list<array{name: string, image: string}>
     */
    private function scrapePage(int $page): array
    {
        $url = $page <= 1 ? self::BASE : self::BASE.'?page='.$page;

        $html = Http::timeout(30)->retry(2, 1500, throw: false)->get($url)->body();

        if ($html === '') {
            return [];
        }

        preg_match_all('#<img\b[^>]*>#i', $html, $tags);

        $out = [];
        foreach ($tags[0] as $tag) {
            if (! preg_match('#\balt="([^"]*)"#i', $tag, $am)) {
                continue;
            }
            $name = trim(html_entity_decode($am[1], ENT_QUOTES));

            // Prefer the lazy-load real source over the placeholder src.
            $image = null;
            foreach (['data-src', 'data-original', 'data-lazy', 'src'] as $attr) {
                if (preg_match('#\b'.$attr.'="([^"]*uploads/image/[^"]+)"#i', $tag, $sm)) {
                    $image = $sm[1];
                    break;
                }
            }
            if ($image === null) {
                continue;
            }

            // Skip chrome: the logo, flags, and anything without a real name.
            if (stripos($image, 'logo') !== false
                || stripos($name, 'snimay home collection') !== false
                || $name === '' || mb_strlen($name) < 3 || mb_strlen($name) > 120) {
                continue;
            }

            // Absolute URL on the manufacturer's host.
            if (str_starts_with($image, '/')) {
                $image = 'https://www.snimayhome.com'.$image;
            }

            // Tidy the name: strip the "-copy-1778900618" suffixes the CMS leaves.
            $name = trim(preg_replace('/-copy-?\d*$/i', '', $name));

            $out[] = ['name' => $name, 'image' => $image];
        }

        return $out;
    }

    private function categoryFor(string $name): string
    {
        $lower = mb_strtolower($name);

        foreach (self::CATEGORY_MAP as $keyword => $path) {
            if (str_contains($lower, $keyword)) {
                return $path;
            }
        }

        return self::DEFAULT_CATEGORY;
    }

    private function shortDescription(string $name): string
    {
        return Str::limit($name.' from the Snimay collection, supplied by OfficeLand with nationwide delivery and installation. Request a quote for finishes, sizes and lead time.', 155, '');
    }

    private function description(string $name): string
    {
        return $name.' is part of the Snimay home collection that OfficeLand imports and supplies across Nigeria. '
            .'It is built with durable, easy-clean PVC-coated surfaces for long service in homes and offices. '
            .'Request a quote for available finishes, dimensions, pricing and lead time; delivery and installation are available nationwide.';
    }
}
