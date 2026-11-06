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
 * Import the Logitech peripheral range as draft products.
 *
 * logitech.com is a headless (SvelteKit) storefront with a clean JSON product
 * API: the sitemap lists every product at /shop/p/<slug>, and
 * /en-us/api/products/<slug> returns that product's data as
 * productData[0].baseData - title, subtitle, category, brand, the variant part
 * numbers (920-xxxxxx) and image paths, and the option facets (colour, keyboard
 * layout, switch style). This pulls those directly, maps Logitech's category to
 * our taxonomy, and creates a DRAFT per product with its photo through the media
 * pipeline, real derived specifications, and an ORIGINAL description (Logitech's
 * own API carries no description text, and its marketing copy is not reproduced).
 *
 * Idempotent (keyed by slug; a photo is attached only when the product has none).
 *
 *   php artisan catalogue:import-logitech                 # report
 *   php artisan catalogue:import-logitech --apply
 */
final class ImportLogitech extends Command
{
    protected $signature = 'catalogue:import-logitech
        {--apply : Create the products and import the photos.}
        {--limit=0 : Cap the number of products (0 = all). For testing.}
        {--sleep=120 : Milliseconds to wait between product fetches.}';

    protected $description = 'Import the Logitech peripheral range as draft products';

    private const SITEMAP = 'https://www.logitech.com/en-us/sitemap.xml';

    private const API = 'https://www.logitech.com/en-us/api/products/';

    /** Cloudinary base; f_auto,q_auto gives an optimised source for derivatives. */
    private const CDN = 'https://resource.logitech.com/f_auto,q_auto';

    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0 Safari/537.36';

    private const KEYBOARDS_MICE = '/it-products/computer-accessories/keyboards-mice/';

    private const HEADSETS_AUDIO = '/it-products/computer-accessories/headsets-audio/';

    private const WEBCAMS = '/smart-tech/conferencing-av/webcams/';

    private const VIDEO_BARS = '/smart-tech/conferencing-av/video-bars/';

    private const SPEAKERPHONES = '/smart-tech/conferencing-av/conference-speakerphones/';

    /**
     * Logitech's own product category (baseData.category) -> our taxonomy leaf.
     * Gaming peripherals are kept (a gaming keyboard is still an IT product);
     * apparel, furniture, pads, VR, racing and spare parts are not, and fall
     * through to the skip set below.
     *
     * @var array<string, string>
     */
    private const CATEGORY_MAP = [
        'keyboards' => self::KEYBOARDS_MICE,
        'gaming-keyboards' => self::KEYBOARDS_MICE,
        'ipad-keyboards' => self::KEYBOARDS_MICE,
        'tablet-keyboards' => self::KEYBOARDS_MICE,
        'mice' => self::KEYBOARDS_MICE,
        'gaming-mice' => self::KEYBOARDS_MICE,
        'left-ambidextrous' => self::KEYBOARDS_MICE,
        'combos' => self::KEYBOARDS_MICE,
        'keyboards-mice-combos' => self::KEYBOARDS_MICE,
        'presenters' => self::KEYBOARDS_MICE,
        'presentation-remotes' => self::KEYBOARDS_MICE,
        'presenters-and-digital-pens' => self::KEYBOARDS_MICE,
        'digital-pens' => self::KEYBOARDS_MICE,
        'creative-and-design-tools' => self::KEYBOARDS_MICE,
        'tablet-ipad-accessories' => self::KEYBOARDS_MICE,
        'headsets' => self::HEADSETS_AUDIO,
        'gaming-audio' => self::HEADSETS_AUDIO,
        'speakers' => self::HEADSETS_AUDIO,
        'microphones' => self::HEADSETS_AUDIO,
        'webcams' => self::WEBCAMS,
        'cameras-lighting' => self::WEBCAMS,
        'streaming-gear' => self::WEBCAMS,
        'conference-cameras' => self::VIDEO_BARS,
        'conference-cameras-systems' => self::VIDEO_BARS,
        'video-bars-boards' => self::VIDEO_BARS,
        'room-controllers-panels' => self::VIDEO_BARS,
        'video-conferencing-accessories' => self::VIDEO_BARS,
        'conference-speakerphones' => self::SPEAKERPHONES,
        'wireless-speakers' => self::HEADSETS_AUDIO,
        'room-solutions' => self::VIDEO_BARS,
    ];

    /**
     * Logitech categories that are not office IT products. Anything whose name
     * contains "refurb" or "outlet" is also skipped (handled in handle()).
     */
    private const SKIP = [
        'gaming-apparel', 'gaming-furniture', 'gaming-mouse-pads', 'driving',
        'simulation', 'gamepads', 'vr', 'virtual-reality', 'extended-reality',
        'cloud-gaming', 'spare-parts', 'employee', 'streaming',
        'farm', 'logi-g-accessories', 'accessories', 'flight', 'space',
        'logi-options-plp', 'logi-earn-cashback', 'gifts-under-100',
        'logi-accessories', 'ue-accessories',
    ];

    /** Human Type label per Logitech category, for the Type spec and the name. */
    private const TYPE_LABEL = [
        'keyboards' => 'Keyboard', 'gaming-keyboards' => 'Gaming keyboard',
        'ipad-keyboards' => 'Tablet keyboard', 'tablet-keyboards' => 'Tablet keyboard',
        'mice' => 'Mouse', 'gaming-mice' => 'Gaming mouse', 'left-ambidextrous' => 'Mouse',
        'combos' => 'Keyboard & mouse combo', 'keyboards-mice-combos' => 'Keyboard & mouse combo',
        'presenters' => 'Presentation remote', 'presentation-remotes' => 'Presentation remote',
        'presenters-and-digital-pens' => 'Presentation remote', 'digital-pens' => 'Digital pen',
        'creative-and-design-tools' => 'Creative input device', 'tablet-ipad-accessories' => 'Tablet accessory',
        'headsets' => 'Headset', 'gaming-audio' => 'Gaming headset', 'speakers' => 'Speaker',
        'microphones' => 'Microphone', 'webcams' => 'Webcam', 'cameras-lighting' => 'Streaming camera',
        'streaming-gear' => 'Streaming device', 'conference-cameras' => 'Conference camera',
        'conference-cameras-systems' => 'Conference camera system', 'video-bars-boards' => 'Video bar',
        'room-controllers-panels' => 'Room controller', 'video-conferencing-accessories' => 'Conferencing accessory',
        'conference-speakerphones' => 'Speakerphone', 'wireless-speakers' => 'Speaker',
        'room-solutions' => 'Video conferencing system', 'gaming-microphones' => 'Microphone',
        'keyboards-mice' => 'Keyboard & mouse combo',
    ];

    public function handle(MediaIngestor $ingestor): int
    {
        @ini_set('memory_limit', '512M');

        $apply = (bool) $this->option('apply');
        $limit = (int) $this->option('limit');
        $sleepMs = max(0, (int) $this->option('sleep'));

        $categoryIds = [];
        foreach (array_unique(array_values(self::CATEGORY_MAP)) as $path) {
            $id = Category::query()->where('path', $path)->value('id');
            if ($id === null) {
                $this->error("Category missing: {$path}");

                return self::FAILURE;
            }
            $categoryIds[$path] = (int) $id;
        }

        $slugs = $this->productSlugs();
        if ($slugs === []) {
            $this->error('No product URLs found in the Logitech sitemap.');

            return self::FAILURE;
        }
        $this->info(count($slugs).' product(s) in the Logitech sitemap.');

        $created = 0;
        $updated = 0;
        $photos = 0;
        $failed = 0;
        $skipped = 0;
        $byCategory = [];
        $unmapped = [];
        $done = 0;

        foreach ($slugs as $slug) {
            if ($limit > 0 && $done >= $limit) {
                break;
            }

            if ($sleepMs > 0 && $done > 0) {
                usleep($sleepMs * 1000);
            }
            $done++;

            try {
                $base = $this->fetchProduct($slug);
            } catch (\Throwable $e) {
                $failed++;
                $this->warn("  fetch failed [{$slug}]: {$e->getMessage()}");

                continue;
            }

            if ($base === null || ($base['physicalProduct'] ?? true) === false || ! empty($base['isSparepart'])) {
                $skipped++;

                continue;
            }

            $logiCategory = (string) ($base['category'] ?? '');
            if ($logiCategory === '' || in_array($logiCategory, self::SKIP, true)
                || Str::contains($logiCategory, ['refurb', 'outlet'])) {
                $skipped++;

                continue;
            }

            // b2b/business affixes name the same product line as the base
            // category; a keyword fallback then catches the remaining variants
            // (gaming-microphones, education-headsets-..., keyboards-mice).
            $mapKey = str_replace(['b2b-', '-b2b', 'business-', '-business'], '', $logiCategory);

            $path = self::CATEGORY_MAP[$mapKey] ?? $this->fallbackPath($mapKey);
            if ($path === null) {
                $unmapped[$logiCategory] = ($unmapped[$logiCategory] ?? 0) + 1;
                $skipped++;

                continue;
            }

            $title = trim((string) ($base['title'] ?? ''));
            if ($title === '') {
                $skipped++;

                continue;
            }

            [$image, $mpn] = $this->variantImageAndCode($base);
            $byCategory[$path] = ($byCategory[$path] ?? 0) + 1;

            if (! $apply) {
                continue;
            }

            try {
                $name = $this->productName($title, (string) ($base['subtitle'] ?? ''));
                $productSlug = Str::slug('logitech-'.($base['productIdentifier'] ?? $title));
                $exists = Product::query()->where('slug', $productSlug)->exists();

                $product = Product::query()->updateOrCreate(
                    ['slug' => $productSlug],
                    [
                        'name' => $name,
                        'mpn' => $mpn ?: null,
                        'short_description' => $this->shortDescription($name),
                        'description_html' => '<p>'.e($this->description($name, $mapKey)).'</p>',
                        // A template blurb is machine copy: stamp it so the
                        // spec-driven drafter may replace it and the readiness
                        // gate holds it until it is approved.
                        'description_generated_at' => now(),
                        'primary_category_id' => $categoryIds[$path],
                        'availability' => 'lead_time',
                    ],
                );
                $product->categories()->syncWithoutDetaching([$categoryIds[$path] => ['sort_order' => 0]]);
                $exists ? $updated++ : $created++;

                ProductSpecs::set($product, $this->specsFrom($base, $mapKey, $mpn));

                if (! $product->media()->exists() && $image !== null) {
                    $tmp = tempnam(sys_get_temp_dir(), 'logi').'.png';
                    $response = Http::timeout(60)->withHeaders(['User-Agent' => self::UA])
                        ->retry(2, 1500, throw: false)->sink($tmp)->get($image);

                    if ($response->successful() && (filesize($tmp) ?: 0) > 2048) {
                        $media = $ingestor->ingest($tmp, [
                            'alt_text' => $name,
                            'source' => 'logitech',
                            'source_url' => $image,
                        ]);
                        $product->media()->syncWithoutDetaching([$media->id => ['role' => 'primary', 'sort_order' => 0]]);
                        GenerateMediaDerivatives::dispatch($media->id);
                        $photos++;
                    } else {
                        $this->warn("  image failed: {$name}");
                    }
                    @unlink($tmp);
                }
            } catch (\Throwable $e) {
                $failed++;
                $this->warn("  failed [{$slug}]: {$e->getMessage()}");
            }
        }

        $this->report($apply, $created, $updated, $photos, $skipped, $failed, $byCategory, $unmapped);

        return self::SUCCESS;
    }

    /**
     * Last-resort category resolution by keyword, for Logitech's many category
     * name variants (gaming-microphones, education-headsets-for-education,
     * keyboards-mice). Order matters: speakerphone is tested before speaker.
     */
    private function fallbackPath(string $cat): ?string
    {
        return match (true) {
            Str::contains($cat, 'speakerphone') => self::SPEAKERPHONES,
            Str::contains($cat, ['headset', 'speaker', 'microphone', 'audio']) => self::HEADSETS_AUDIO,
            Str::contains($cat, 'webcam') => self::WEBCAMS,
            Str::contains($cat, ['conference', 'video-bar', 'room']) => self::VIDEO_BARS,
            Str::contains($cat, ['keyboard', 'mice', 'mouse', 'combo']) => self::KEYBOARDS_MICE,
            default => null,
        };
    }

    /** Every /shop/p/<slug> in the sitemap. */
    private function productSlugs(): array
    {
        $xml = Http::timeout(45)->withHeaders(['User-Agent' => self::UA])
            ->retry(2, 2000, throw: false)->get(self::SITEMAP)->body();

        preg_match_all('#/shop/p/([a-z0-9][a-z0-9\-]*)#i', $xml, $m);

        return array_values(array_unique($m[1] ?? []));
    }

    /** @return array<string, mixed>|null baseData, or null if unusable. */
    private function fetchProduct(string $slug): ?array
    {
        $json = Http::timeout(30)->withHeaders(['User-Agent' => self::UA, 'Accept' => 'application/json'])
            ->retry(2, 1500, throw: false)->get(self::API.rawurlencode($slug))->json();

        $base = $json['productData'][0]['baseData'] ?? null;

        return is_array($base) ? $base : null;
    }

    /**
     * The first real product image (not a video thumbnail) and the first
     * variant's part number.
     *
     * @param  array<string, mixed>  $base
     * @return array{0: ?string, 1: string}
     */
    private function variantImageAndCode(array $base): array
    {
        $variants = (array) ($base['variants'] ?? []);
        $first = $variants !== [] ? reset($variants) : [];

        $code = trim((string) ($first['productId'] ?? ''));

        $image = null;
        foreach ((array) ($first['productImages'] ?? []) as $img) {
            if (! empty($img['video'])) {
                continue;
            }
            $pathPart = (string) ($img['path'] ?? '');
            if ($pathPart !== '') {
                $image = self::CDN.$pathPart;
                break;
            }
        }

        return [$image, $code];
    }

    /**
     * Real specifications derived from the Logitech API. Facets (colour,
     * keyboard layout, switch style, connectivity) are genuine options; the rest
     * is inferred only from explicit text.
     *
     * @param  array<string, mixed>  $base
     * @return array<string, string>
     */
    private function specsFrom(array $base, string $logiCategory, string $mpn): array
    {
        $title = (string) ($base['title'] ?? '');
        $subtitle = (string) ($base['subtitle'] ?? '');

        $specs = [
            'Brand' => 'Logitech',
            'Type' => self::TYPE_LABEL[$logiCategory] ?? 'Peripheral',
        ];

        $connectivity = $this->connectivity($title.' '.$subtitle);
        if ($connectivity !== '') {
            $specs['Connectivity'] = $connectivity;
        }

        $series = trim((string) ($base['seriesTag'] ?? ''));
        if ($series !== '' && str_contains($series, '/')) {
            $tail = Str::afterLast($series, '/');
            $specs['Series'] = Str::title(str_replace('-', ' ', $tail));
        }

        // Option facets become specs: colour, keyboard layout, switch style, etc.
        $facetLabels = [
            'color' => 'Colour', 'language' => 'Keyboard layout', 'style' => 'Switch type',
            'connectivity' => 'Connectivity', 'size' => 'Size', 'hand' => 'Hand orientation',
        ];
        foreach ((array) ($base['facets'] ?? []) as $key => $values) {
            $label = $facetLabels[$key] ?? null;
            if ($label === null || isset($specs[$label])) {
                continue;
            }
            $names = array_values(array_filter(array_map(
                static fn ($v): string => trim((string) ($v['label'] ?? '')),
                (array) $values,
            )));
            if ($names !== []) {
                $specs[$label] = implode(', ', array_slice($names, 0, 6));
            }
        }

        if ($mpn !== '') {
            $specs['Manufacturer code'] = $mpn;
        }

        return $specs;
    }

    private function connectivity(string $text): string
    {
        $t = Str::lower($text);
        $found = [];
        if (Str::contains($t, 'bluetooth')) {
            $found[] = 'Bluetooth';
        }
        if (Str::contains($t, ['wireless', 'wi-fi', 'rf ', 'logi bolt', 'lightspeed'])) {
            $found[] = 'Wireless';
        }
        if (Str::contains($t, ['usb-c', 'usb c'])) {
            $found[] = 'USB-C';
        }
        if ($found === [] && Str::contains($t, ['wired', 'usb'])) {
            $found[] = 'Wired';
        }

        return implode(' / ', array_unique($found));
    }

    private function productName(string $title, string $subtitle): string
    {
        $name = 'Logitech '.$title;
        $subtitle = trim($subtitle);

        // Append the subtitle only when it adds words the title does not have.
        if ($subtitle !== '' && ! Str::contains(Str::lower($name), Str::lower($subtitle))) {
            $name .= ' '.$subtitle;
        }

        return Str::limit($name, 150, '');
    }

    private function shortDescription(string $name): string
    {
        return Str::limit($name.', supplied by OfficeLand with nationwide delivery across Nigeria. Request a quote for pricing, availability and lead time.', 155, '');
    }

    private function description(string $name, string $logiCategory): string
    {
        $type = Str::lower(self::TYPE_LABEL[$logiCategory] ?? 'peripheral');

        return $name.' is a Logitech '.$type.' supplied by OfficeLand to offices, schools and government buyers across Nigeria. '
            .'Logitech is a global leader in computer peripherals and video collaboration hardware built for reliable, everyday productivity. '
            .'Request a quote for current pricing, configuration options and lead time; delivery and setup are available nationwide.';
    }

    /**
     * @param  array<string, int>  $byCategory
     * @param  array<string, int>  $unmapped
     */
    private function report(bool $apply, int $created, int $updated, int $photos, int $skipped, int $failed, array $byCategory, array $unmapped): void
    {
        $this->newLine();
        $this->table(['Outcome', 'Count'], [
            [$apply ? 'Created' : 'Would import', $apply ? $created : array_sum($byCategory)],
            ['Updated (already existed)', $updated],
            ['Photos imported', $photos],
            ['Skipped (not office IT / unmapped)', $skipped],
            ['Failed', $failed],
        ]);

        if ($byCategory !== []) {
            $this->newLine();
            $this->line('By category:');
            foreach ($byCategory as $path => $n) {
                $this->line("  {$n}\t{$path}");
            }
        }

        if ($unmapped !== []) {
            arsort($unmapped);
            $this->newLine();
            $this->warn('Unmapped Logitech categories (add to CATEGORY_MAP or SKIP to include/exclude):');
            foreach ($unmapped as $cat => $n) {
                $this->line("  {$n}\t{$cat}");
            }
        }

        if (! $apply) {
            $this->comment('Dry run. Add --apply to create the draft products and import the photos.');
        } else {
            $this->info('Created as DRAFT under IT Products / Smart Tech. Review and publish in the admin.');
        }
    }
}
