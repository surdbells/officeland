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
 * Import the Pilot pen range (pilotpen.eu) as draft products under Writing.
 *
 * pilotpen.eu is a WordPress site. Its REST API (/wp-json/wp/v2/products)
 * exposes a 'products' post type but is locked down by iThemes Security
 * (401 itsec_rest_api_access_restricted), so the catalogue is taken from the
 * Yoast page sitemap instead: the individual model pages live under
 * /our-products/ and /our-universes/fine-writing/. Section index pages (the
 * material ranges like ballpoint-pens, and the "universe" theme pages like
 * work/school) are not products, so they are dropped — a page that is a path
 * prefix of another is an index, and a small EXCLUDE list removes the range and
 * universe leaves.
 *
 * For each product page the name comes from og:title (text before "| Pilot")
 * and the photo from og:image; when og:image falls back to the Pilot logo the
 * command scans the page body for the first upload image whose filename matches
 * the product slug. The photo is imported through the media pipeline
 * (derivatives queued), the description is ORIGINAL (never Pilot's marketing
 * copy), and each product is filed under Writing > Pens (paint markers under
 * Markers & Highlighters).
 *
 * Idempotent: products are keyed by slug, so a re-run updates rather than
 * duplicates, and a photo is only attached when the product has none.
 *
 *   php artisan catalogue:import-pilot                   # dry run / report
 *   php artisan catalogue:import-pilot --apply
 *   php artisan catalogue:import-pilot --apply --limit=3 # for testing
 */
final class ImportPilot extends Command
{
    protected $signature = 'catalogue:import-pilot
        {--apply : Create the products and import the photos.}
        {--limit=0 : Cap the number of products (0 = all). For testing.}';

    protected $description = 'Import the Pilot pen range as draft products under Writing';

    private const HOST = 'https://www.pilotpen.eu';

    private const SITEMAP = self::HOST.'/page-sitemap.xml';

    private const UA = 'Mozilla/5.0 (compatible; OfficeLandCatalogueBot/1.0; +https://officeland.ng)';

    private const PENS = '/office-supplies/writing/pens/';

    private const MARKERS = '/office-supplies/writing/markers-highlighters/';

    /**
     * Section index / theme leaves that are navigation, not individual products.
     * Prefix pages (e.g. /our-products/frixion/) are dropped automatically; these
     * are the leaves that would otherwise slip through.
     */
    private const EXCLUDE = [
        '/our-products/ballpoint-pens/',
        '/our-products/liquid-ink-pens/',
        '/our-products/gel-ink-pens/',
        '/our-products/markers/',
        '/our-products/refills/',
        '/our-universes/work/',
        '/our-universes/leisure/',
        '/our-universes/school/',
    ];

    /** Upload filenames that are site chrome, never a product photo. */
    private const CHROME = ['logo', 'flag', 'icon', '.svg', 'vector', 'banniere', 'kv-prop', 'frame-', 'placeholder', 'our-product-'];

    public function handle(MediaIngestor $ingestor): int
    {
        @ini_set('memory_limit', '512M');

        $apply = (bool) $this->option('apply');
        $limit = (int) $this->option('limit');

        $categoryIds = [];
        foreach ([self::PENS, self::MARKERS] as $path) {
            $id = Category::query()->where('path', $path)->value('id');
            if ($id === null) {
                $this->error("Category missing: {$path}");

                return self::FAILURE;
            }
            $categoryIds[$path] = (int) $id;
        }

        $urls = $this->productUrls();
        if ($urls === []) {
            $this->error('No product pages found in the sitemap. Aborting rather than guessing.');

            return self::FAILURE;
        }
        $this->line(count($urls).' product page(s) in the sitemap.');

        $created = 0;
        $updated = 0;
        $photos = 0;
        $failed = 0;
        $skipped = 0;
        $byCategory = [];
        $rows = [];

        foreach ($urls as $url) {
            if ($limit > 0 && ($created + $updated + $skipped) >= $limit && $apply) {
                break;
            }
            if ($limit > 0 && count($rows) >= $limit && ! $apply) {
                break;
            }

            $html = Http::withHeaders(['User-Agent' => self::UA])
                ->timeout(30)->retry(2, 1500, throw: false)->get($url)->body();

            if ($html === '') {
                $this->warn("  page failed: {$url}");
                $failed++;

                continue;
            }

            $name = $this->name($html);
            if ($name === '') {
                $this->warn("  no name: {$url}");
                $skipped++;

                continue;
            }

            $slug = Str::slug($name);
            $image = $this->image($html, $slug);
            if ($image === null) {
                $this->warn("  no usable image: {$name}");
                $skipped++;

                continue;
            }

            $path = $this->categoryFor($name);
            $byCategory[$path] = ($byCategory[$path] ?? 0) + 1;
            $rows[] = [$name, Str::afterLast(rtrim($path, '/'), '/'), Str::limit($image, 60, '…')];

            if (! $apply) {
                continue;
            }

            try {
                $exists = Product::query()->where('slug', $slug)->exists();

                $product = Product::query()->updateOrCreate(
                    ['slug' => $slug],
                    [
                        'name' => $name,
                        'short_description' => $this->shortDescription($name),
                        'description_html' => '<p>'.e($this->description($name)).'</p>',
                        // The blurb above is a template, not sourced copy: stamp it
                        // so the spec-driven DescriptionDrafter may replace it and so
                        // the readiness gate holds it until it is approved.
                        'description_generated_at' => now(),
                        'primary_category_id' => $categoryIds[$path],
                        'availability' => 'lead_time',
                    ],
                );
                $product->categories()->syncWithoutDetaching([$categoryIds[$path] => ['sort_order' => 0]]);
                $exists ? $updated++ : $created++;

                ProductSpecs::set($product, $this->specsFrom($html, $name, $url));

                if (! $product->media()->exists()) {
                    $clean = strtok($image, '?');
                    $tmp = tempnam(sys_get_temp_dir(), 'pilot').'.'.(pathinfo($clean, PATHINFO_EXTENSION) ?: 'jpg');
                    $response = Http::withHeaders(['User-Agent' => self::UA])
                        ->timeout(45)->retry(2, 1500, throw: false)->sink($tmp)->get($image);

                    if ($response->successful()) {
                        $media = $ingestor->ingest($tmp, [
                            'alt_text' => $name,
                            'source' => 'pilot',
                            'source_url' => $image,
                        ]);
                        $product->media()->syncWithoutDetaching([$media->id => ['role' => 'primary', 'sort_order' => 0]]);
                        GenerateMediaDerivatives::dispatch($media->id);
                        $photos++;
                    } else {
                        $this->warn("  image failed ({$response->status()}): {$name}");
                        $failed++;
                    }
                    @unlink($tmp);
                }
            } catch (\Throwable $e) {
                $failed++;
                $this->warn("  failed [{$name}]: {$e->getMessage()}");
            }
        }

        if (! $apply && $rows !== []) {
            $this->newLine();
            $this->table(['Product', 'Category', 'Image'], $rows);
        }

        $this->newLine();
        $this->table(['Outcome', 'Count'], [
            [$apply ? 'Created' : 'Would import', $apply ? $created : count($rows)],
            ['Updated (already existed)', $updated],
            ['Photos imported', $photos],
            ['Skipped (no name / image)', $skipped],
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
            $this->info('Created as DRAFT under Writing. Review and publish in the admin. Derivatives are generating on the media queue.');
        }

        return self::SUCCESS;
    }

    /**
     * Individual product page URLs from the page sitemap: the leaves under
     * /our-products/ and /our-universes/, minus index and theme pages.
     *
     * @return list<string>
     */
    private function productUrls(): array
    {
        $xml = Http::withHeaders(['User-Agent' => self::UA])
            ->timeout(30)->retry(2, 1500, throw: false)->get(self::SITEMAP)->body();

        if ($xml === '' || ! preg_match_all('#<loc>\s*([^<]+?)\s*</loc>#i', $xml, $m)) {
            return [];
        }

        // Keep only the product sections.
        $paths = [];
        foreach ($m[1] as $loc) {
            $path = parse_url(trim($loc), PHP_URL_PATH);
            if (! is_string($path)) {
                continue;
            }
            if (str_starts_with($path, '/our-products/') || str_starts_with($path, '/our-universes/')) {
                $paths[$path] = true;
            }
        }
        $paths = array_keys($paths);

        $out = [];
        foreach ($paths as $path) {
            // Drop index pages: any path that is a prefix of another page.
            foreach ($paths as $other) {
                if ($other !== $path && str_starts_with($other, $path)) {
                    continue 2;
                }
            }
            if (in_array($path, self::EXCLUDE, true)) {
                continue;
            }
            $out[] = self::HOST.$path;
        }

        sort($out);

        return $out;
    }

    private function name(string $html): string
    {
        $title = $this->meta($html, 'og:title');
        if ($title === '') {
            return '';
        }

        // "FriXion Ball + | Pilot – marketing…" -> "FriXion Ball +".
        $title = trim(Str::before($title, '|'));

        return trim(html_entity_decode($title, ENT_QUOTES | ENT_HTML5));
    }

    /**
     * The product photo: og:image when it is a real upload, otherwise the first
     * body upload image whose filename matches the product slug.
     */
    private function image(string $html, string $slug): ?string
    {
        $og = $this->meta($html, 'og:image');
        if ($og !== '' && ! $this->isChrome($og)) {
            return $og;
        }

        // Fallback: scan body <img> tags in document order (lazy-load src first).
        preg_match_all('#<img\b[^>]*>#i', $html, $tags);
        $candidates = [];
        foreach ($tags[0] as $tag) {
            foreach (['data-src', 'data-lazy-src', 'data-original', 'src'] as $attr) {
                if (preg_match('#\b'.$attr.'="([^"]*/wp-content/uploads/[^"]+)"#i', $tag, $sm)) {
                    $src = html_entity_decode($sm[1], ENT_QUOTES);
                    if (! $this->isChrome($src)) {
                        $candidates[] = $src;
                    }
                    break;
                }
            }
        }
        $candidates = array_values(array_unique($candidates));

        // Prefer an image whose filename carries a slug token (len >= 3).
        $tokens = array_filter(explode('-', $slug), static fn ($t): bool => mb_strlen($t) >= 3);
        foreach ($candidates as $src) {
            $base = mb_strtolower(basename(parse_url($src, PHP_URL_PATH) ?: $src));
            foreach ($tokens as $t) {
                if (str_contains($base, $t)) {
                    return $src;
                }
            }
        }

        return $candidates[0] ?? null;
    }

    private function meta(string $html, string $property): string
    {
        if (preg_match('#<meta[^>]+property=["\']'.preg_quote($property, '#').'["\'][^>]+content=["\']([^"\']*)["\']#i', $html, $m)) {
            return trim($m[1]);
        }

        return '';
    }

    private function isChrome(string $url): bool
    {
        $base = mb_strtolower(basename(parse_url($url, PHP_URL_PATH) ?: $url));

        return Str::contains($base, self::CHROME);
    }

    private function categoryFor(string $name): string
    {
        return Str::contains(Str::lower($name), ['pintor', 'marker']) ? self::MARKERS : self::PENS;
    }

    /**
     * The product's real specifications as a [label => value] map, taken from the
     * PDP. Brand + Type + Range are always known (the URL section is a truthful
     * source of the writing-instrument type); tip size, ink colours, nib sizes,
     * body material and refillable/retractable are added only when the product's
     * own copy states them. Site chrome (the mega-menu's category words) is
     * stripped first so a nav link like "Erasable pens" never reads as a fact.
     *
     * @return array<string, string>
     */
    private function specsFrom(string $html, string $name, string $url): array
    {
        $path = parse_url($url, PHP_URL_PATH) ?: $url;
        $segments = array_values(array_filter(explode('/', $path)));
        $section = $segments[1] ?? '';
        $leaf = end($segments) ?: $section;

        $specs = ['Brand' => 'Pilot'];

        $type = $this->typeFor($section, (string) $leaf);
        if ($type !== '') {
            $specs['Type'] = $type;
        }

        // Range/series: for a family section (/our-products/frixion/<leaf>) the
        // section names the family; for a single-level product the section IS the
        // product, so the name is the range.
        $specs['Range'] = in_array($section, ['frixion', 'pintor', 'capless'], true) && $leaf !== $section
            ? Str::title(str_replace('-', ' ', $section))
            : $name;

        $text = $this->visibleText($html);

        // Tip / line width, e.g. "a smooth 0.7mm tip".
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*mm\b/i', $text, $m)) {
            $specs['Tip size'] = str_replace(',', '.', $m[1]).' mm';
        }

        // Ink colours, as genuinely phrased: "Available in black and blue ink" or
        // "Available in four colors: black, blue, green, and red.".
        $colours = '';
        if (preg_match('/available in\s+([a-z][a-z,\s]*?(?:\s+and\s+[a-z]+)?)\s+ink\b/i', $text, $m)) {
            $colours = $m[1];
        } elseif (preg_match('/available in\s+[a-z0-9]+\s+colou?rs?\s*:\s*([a-z][a-z,\s]*?(?:\s+and\s+[a-z]+)?)\s*[.<]/i', $text, $m)) {
            $colours = $m[1];
        }
        $colours = $this->normaliseList($colours);
        if ($colours !== '') {
            $specs['Ink colours'] = $colours;
        }

        // Nib sizes (fine writing): "available in 4 nib sizes: soft fine, ...".
        if (preg_match('/available in\s+[a-z0-9]+\s+nib sizes?\s*:\s*([a-z][a-z,\s]*?(?:\s+and\s+[a-z]+)?)\s*[.<]/i', $text, $m)) {
            $nib = $this->normaliseList($m[1]);
            if ($nib !== '') {
                $specs['Nib sizes'] = $nib;
            }
        }

        // Body material, e.g. "lacquered steel barrel", "aluminium body".
        if (preg_match('/\b((?:lacquered\s+)?(?:stainless\s+)?(?:steel|aluminium|aluminum|brass|resin|metal|plastic|wood|ebonite|acrylic))\s+(?:barrel|body)\b/i', $text, $m)) {
            $specs['Body material'] = Str::ucfirst(Str::lower(trim($m[1])));
        }

        if (preg_match('/\brefillable\b/i', $text)) {
            $specs['Refillable'] = 'Yes';
        }
        if (preg_match('/\bretractable\b/i', $text)) {
            $specs['Retractable'] = 'Yes';
        }

        return $specs;
    }

    /**
     * The writing-instrument type implied by the URL section (and, for inks, the
     * leaf). The section is navigation Pilot itself assigns, so it is a truthful
     * source of the product type even when the page copy is pure marketing.
     */
    private function typeFor(string $section, string $leaf): string
    {
        if ($leaf === 'iroshizuku-inks') {
            return 'Fountain pen ink';
        }

        return [
            'ballpoint-pens' => 'Ballpoint pen',
            'gel-ink-pens' => 'Gel ink pen',
            'liquid-ink-pens' => 'Liquid ink rollerball pen',
            'markers' => 'Marker',
            'pintor' => 'Paint marker',
            'frixion' => 'Erasable gel ink pen',
            'capless' => 'Fountain pen (retractable)',
            'refills' => 'Refill',
            'fine-writing' => 'Fountain pen',
        ][$section] ?? '';
    }

    /**
     * Visible page text with site chrome removed, for the factual parse. The
     * mega-menu, header and footer carry category words (e.g. "Erasable pens")
     * that must not be mistaken for this product's own specification.
     */
    private function visibleText(string $html): string
    {
        foreach (['script', 'style', 'header', 'nav', 'footer'] as $tag) {
            $html = preg_replace('#<'.$tag.'\b[^>]*>.*?</'.$tag.'>#is', ' ', $html) ?? $html;
        }
        $text = preg_replace('#<[^>]+>#', ' ', $html) ?? $html;
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5);

        return trim(preg_replace('#\s+#', ' ', $text) ?? $text);
    }

    /** Tidy a parsed comma/and-separated list; drop implausibly long captures. */
    private function normaliseList(string $value): string
    {
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > 120) {
            return '';
        }
        $value = preg_replace('/\s*,\s*/', ', ', $value) ?? $value;
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return Str::ucfirst($value);
    }

    private function shortDescription(string $name): string
    {
        return Str::limit($name.' by Pilot, supplied by OfficeLand with nationwide delivery across Nigeria. Request a quote for variants, tip sizes, ink colours and lead time.', 155, '');
    }

    private function description(string $name): string
    {
        return $name.' is part of the Pilot writing-instrument range that OfficeLand supplies to businesses and schools across Nigeria. '
            .'Pilot pens are built for smooth, consistent writing and dependable everyday use in the office and classroom. '
            .'Request a quote for the available models, tip sizes, ink colours, pack quantities and current lead time; delivery is available nationwide.';
    }
}
