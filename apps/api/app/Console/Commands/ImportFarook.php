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
 * Import Farook stationery (farook.com) as draft products under office supplies.
 *
 * farook.com itself is a corporate brochure; the company's actual catalogue of
 * 18,000+ office and school stationery lines lives on its storefront,
 * farookonline.com (a Next.js app). Its category pages are server-rendered with
 * a clean schema.org ItemList in a JSON-LD <script>, so the name, image and
 * canonical URL come from structured data rather than brittle HTML scraping -
 * which also sidesteps the storefront's client-side lazy-loaded <img> tags
 * entirely (a defensive data-src fallback is kept for when a page lacks JSON-LD).
 *
 * For each product a DRAFT is created with its manufacturer photo through the
 * media pipeline (derivatives queued), an ORIGINAL short description (never the
 * storefront's copy) and a confirmed OfficeLand leaf category inferred from the
 * product name. Idempotent: keyed by the storefront's own product slug (which
 * carries the SKU, so colour/pack variants stay distinct), so a re-run updates
 * rather than duplicates, and a photo is only attached when the product has none.
 *
 *   php artisan catalogue:import-farook                 # report (dry run)
 *   php artisan catalogue:import-farook --apply         # create drafts + photos
 *   php artisan catalogue:import-farook --apply --limit=3
 */
final class ImportFarook extends Command
{
    protected $signature = 'catalogue:import-farook
        {--apply : Create the products and import the photos.}
        {--limit=0 : Cap the number of products (0 = all). For testing.}';

    protected $description = 'Import Farook stationery as draft products under office supplies';

    private const STORE = 'https://www.farookonline.com';

    /**
     * Farook storefront category slugs to pull from. Kept to the stationery
     * lines that map cleanly onto confirmed OfficeLand leaves; a slug that 404s
     * or returns nothing is skipped silently.
     *
     * @var list<string>
     */
    private const SOURCE_CATEGORIES = [
        'pens', 'pencil', 'marker', 'markers-highlighters', 'drawing-marker',
        'oil-pastel', 'crayons', 'water-color', 'watercolor-brush-pen',
        'erasers', 'correction-fluid', 'erasers-correction-supplies',
        'staplers', 'hand-staplers-tackers', 'punches', 'staple-remover',
        'scissors', 'kids-scissors', 'cutter', 'sword-cutter',
        'calculator', 'shredders', 'shredder', 'laminators', 'laminator',
        'laminating-film', 'binding-machine', 'rotary-trimmer', 'trimmer',
        'notebooks', 'exercise-books', 'graph-books', 'graph-pads',
        'writing-pad', 'note-pad', 'refill-pads', 'jotter-pad',
        'sticky-notes', 'self-stick-notes', 'memo-book-ncr',
        'envelopes', 'glues', 'glue-sticks', 'super-glues',
        'adhesives-craft-glue', 'tapes', 'tape', 'packaging-tape',
        'mathematical-sets', 'multipurpose-labels', 'rubber-bands',
        'pencil-sharpeners', 'inks',
    ];

    /**
     * Keyword => confirmed leaf category path, most-specific first. The first
     * keyword found in the lower-cased product name wins; unmatched fall to
     * DEFAULT_CATEGORY. Every path here is verified against the live category
     * tree, so a missing one aborts the run rather than mis-filing products.
     */
    private const CATEGORY_MAP = [
        // School / art & craft
        'oil pastel' => '/school-supplies/art-craft/crayons-colour-pencils/',
        'oil pastal' => '/school-supplies/art-craft/crayons-colour-pencils/',
        'colour pencil' => '/school-supplies/art-craft/crayons-colour-pencils/',
        'color pencil' => '/school-supplies/art-craft/crayons-colour-pencils/',
        'coloured pencil' => '/school-supplies/art-craft/crayons-colour-pencils/',
        'crayon' => '/school-supplies/art-craft/crayons-colour-pencils/',
        'water color' => '/school-supplies/art-craft/drawing-colouring/',
        'watercolor' => '/school-supplies/art-craft/drawing-colouring/',
        'water colour' => '/school-supplies/art-craft/drawing-colouring/',
        'paint' => '/school-supplies/art-craft/drawing-colouring/',
        'sketch' => '/school-supplies/art-craft/drawing-colouring/',

        // Maths / geometry
        'mathematical set' => '/school-supplies/maths-geometry/math-sets/',
        'math set' => '/school-supplies/maths-geometry/math-sets/',
        'geometry set' => '/school-supplies/maths-geometry/math-sets/',
        'protractor' => '/school-supplies/maths-geometry/rulers-stencils/',
        'stencil' => '/school-supplies/maths-geometry/rulers-stencils/',
        'ruler' => '/school-supplies/maths-geometry/rulers-stencils/',

        // Office machines (before generic writing/cutting keywords)
        'paper trimmer' => '/office-supplies/office-machines/paper-trimmers/',
        'rotary trimmer' => '/office-supplies/office-machines/paper-trimmers/',
        'binding machine' => '/office-supplies/office-machines/binding-machines/',
        'laminat' => '/office-supplies/office-machines/laminators/',
        'shredder' => '/office-supplies/office-machines/shredders/',
        'calculator' => '/office-supplies/office-machines/calculators/',

        // Writing
        'highlighter' => '/office-supplies/writing/markers-highlighters/',
        'marker' => '/office-supplies/writing/markers-highlighters/',
        'correction' => '/office-supplies/writing/correction-erasers/',
        'eraser' => '/office-supplies/writing/correction-erasers/',
        'sharpener' => '/office-supplies/writing/pencils/',
        'pencil' => '/office-supplies/writing/pencils/',
        'ballpoint' => '/office-supplies/writing/pens/',
        'roller pen' => '/office-supplies/writing/pens/',
        'gel pen' => '/office-supplies/writing/pens/',
        'fountain pen' => '/office-supplies/writing/pens/',
        'pen' => '/office-supplies/writing/pens/',

        // Desk accessories
        'stapler' => '/office-supplies/desk-accessories/staplers-punches/',
        'staple' => '/office-supplies/desk-accessories/staplers-punches/',
        'punch' => '/office-supplies/desk-accessories/staplers-punches/',
        'paper clip' => '/office-supplies/desk-accessories/clips-pins/',
        'binder clip' => '/office-supplies/desk-accessories/clips-pins/',
        'rubber band' => '/office-supplies/desk-accessories/clips-pins/',
        'scissor' => '/office-supplies/desk-accessories/cutting-tools/',
        'cutter' => '/office-supplies/desk-accessories/cutting-tools/',
        'trimmer' => '/office-supplies/desk-accessories/cutting-tools/',
        'knife' => '/office-supplies/desk-accessories/cutting-tools/',

        // Labelling
        'label' => '/office-supplies/labelling-identification/labels/',
        'stamp' => '/office-supplies/labelling-identification/stamps/',

        // Adhesives & tapes
        'glue stick' => '/office-supplies/adhesives-tapes/glue/',
        'super glue' => '/office-supplies/adhesives-tapes/adhesives/',
        'adhesive' => '/office-supplies/adhesives-tapes/adhesives/',
        'glue' => '/office-supplies/adhesives-tapes/glue/',
        'tape' => '/office-supplies/adhesives-tapes/tapes/',

        // Paper & notebooks
        'sticky note' => '/paper-notebooks/sticky-notes/',
        'self stick' => '/paper-notebooks/sticky-notes/',
        'self-stick' => '/paper-notebooks/sticky-notes/',
        'memo' => '/paper-notebooks/sticky-notes/',
        'envelope' => '/paper-notebooks/envelopes-mailing/',
        'photo paper' => '/paper-notebooks/specialty-paper/photo-paper/',
        'thermal' => '/paper-notebooks/specialty-paper/receipt-rolls/',
        'notebook' => '/paper-notebooks/notebooks-pads/',
        'exercise book' => '/paper-notebooks/notebooks-pads/',
        'graph book' => '/paper-notebooks/notebooks-pads/',
        'graph pad' => '/paper-notebooks/notebooks-pads/',
        'refill pad' => '/paper-notebooks/notebooks-pads/',
        'writing pad' => '/paper-notebooks/notebooks-pads/',
        'jotter' => '/paper-notebooks/notebooks-pads/',
        'note pad' => '/paper-notebooks/notebooks-pads/',
        'notepad' => '/paper-notebooks/notebooks-pads/',
    ];

    private const DEFAULT_CATEGORY = '/office-supplies/desk-accessories/';

    public function handle(MediaIngestor $ingestor): int
    {
        @ini_set('memory_limit', '512M');

        $apply = (bool) $this->option('apply');
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
        $seenSlugs = [];
        $created = 0;
        $updated = 0;
        $photos = 0;
        $failed = 0;
        $byCategory = [];

        foreach (self::SOURCE_CATEGORIES as $slug) {
            $products = $this->scrapeCategory($slug);
            $this->line("Category {$slug}: ".count($products).' product(s)');

            foreach ($products as $item) {
                // Dedupe across categories: a product is listed under several.
                if (isset($seenImages[$item['image']]) || isset($seenSlugs[$item['slug']])) {
                    continue;
                }
                $seenImages[$item['image']] = true;
                $seenSlugs[$item['slug']] = true;

                if ($limit > 0 && ($created + $updated) >= $limit) {
                    break 2;
                }

                $path = $this->categoryFor($item['name']);
                $byCategory[$path] = ($byCategory[$path] ?? 0) + 1;

                if (! $apply) {
                    continue;
                }

                try {
                    $exists = Product::query()->where('slug', $item['slug'])->exists();

                    $product = Product::query()->updateOrCreate(
                        ['slug' => $item['slug']],
                        [
                            'name' => $item['name'],
                            'short_description' => $this->shortDescription($item['name']),
                            'description_html' => '<p>'.e($this->description($item['name'])).'</p>',
                            // Template blurb is machine copy: stamp it so the
                            // spec-driven DescriptionDrafter may replace it and
                            // the readiness gate holds it until it is approved.
                            'description_generated_at' => now(),
                            'primary_category_id' => $categoryIds[$path],
                            'availability' => 'lead_time',
                        ],
                    );
                    $product->categories()->syncWithoutDetaching([$categoryIds[$path] => ['sort_order' => 0]]);
                    $exists ? $updated++ : $created++;

                    ProductSpecs::set($product, $this->specsFor($item, $path));

                    if (! $product->media()->exists()) {
                        $clean = strtok($item['image'], '?');
                        $tmp = tempnam(sys_get_temp_dir(), 'farook').'.'.(pathinfo($clean, PATHINFO_EXTENSION) ?: 'webp');
                        $response = Http::timeout(45)->retry(2, 1500, throw: false)->sink($tmp)->get($item['image']);

                        if ($response->successful()) {
                            $media = $ingestor->ingest($tmp, [
                                'alt_text' => $item['name'],
                                'source' => 'farook',
                                'source_url' => $item['image'],
                            ]);
                            $product->media()->syncWithoutDetaching([$media->id => ['role' => 'primary', 'sort_order' => 0]]);
                            // Queued, not inline: the range is large. The worker
                            // builds derivatives; the storefront shows the
                            // original meanwhile.
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
            $this->info('Created as DRAFT. Review and publish in the admin. Derivatives are generating on the media queue.');
        }

        return self::SUCCESS;
    }

    /**
     * Products on one storefront category page, from its schema.org ItemList
     * JSON-LD (name, primary image, canonical URL).
     *
     * @return list<array{name: string, image: string, slug: string, url: string}>
     */
    private function scrapeCategory(string $slug): array
    {
        $html = Http::timeout(30)->retry(2, 1500, throw: false)
            ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; OfficeLandBot/1.0)'])
            ->get(self::STORE.'/category/'.$slug)
            ->body();

        if ($html === '') {
            return [];
        }

        $out = [];
        $seen = [];

        if (! preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $scripts)) {
            return [];
        }

        foreach ($scripts[1] as $json) {
            $data = json_decode(trim($json), true);
            if (! is_array($data)) {
                continue;
            }

            foreach ($this->findProducts($data) as $product) {
                $name = trim((string) ($product['name'] ?? ''));
                $image = (string) ($product['image'] ?? '');
                $url = (string) ($product['url'] ?? '');

                if ($name === '' || $image === '' || $url === '') {
                    continue;
                }

                // Idempotency key: the storefront's own product slug (last path
                // segment). It carries the SKU, so pack/colour variants that
                // share a display name stay distinct instead of colliding.
                $slugKey = Str::slug(basename(parse_url($url, PHP_URL_PATH) ?: $url));
                if ($slugKey === '' || isset($seen[$slugKey])) {
                    continue;
                }
                $seen[$slugKey] = true;

                $out[] = [
                    'name' => $this->tidyName($name),
                    'image' => $image,
                    'slug' => $slugKey,
                    'url' => $url,
                ];
            }
        }

        // Fallback: if a page ever ships without JSON-LD, read the product
        // thumbnails straight from the markup, preferring the lazy-load source
        // (data-src / data-original) over the placeholder in src.
        if ($out === [] && preg_match_all('#<img\b[^>]*>#i', $html, $tags)) {
            foreach ($tags[0] as $tag) {
                $image = null;
                foreach (['data-src', 'data-original', 'data-lazy', 'src'] as $attr) {
                    if (preg_match('#\b'.$attr.'="([^"]*uploads/products/[^"]+)"#i', $tag, $sm)) {
                        $image = $sm[1];
                        break;
                    }
                }
                if ($image === null || ! preg_match('#\balt="([^"]{3,120})"#i', $tag, $am)) {
                    continue;
                }
                $name = trim(html_entity_decode($am[1], ENT_QUOTES));
                $slugKey = Str::slug($name);
                if ($slugKey === '' || isset($seen[$slugKey])) {
                    continue;
                }
                $seen[$slugKey] = true;
                $out[] = ['name' => $this->tidyName($name), 'image' => $image, 'slug' => $slugKey, 'url' => ''];
            }
        }

        return $out;
    }

    /**
     * Recursively collect schema.org Product nodes from a decoded JSON-LD tree
     * (handles @graph, ItemList/itemListElement and nested arrays).
     *
     * @param  array<mixed>  $data
     * @return list<array<string, mixed>>
     */
    private function findProducts(array $data): array
    {
        $out = [];

        if (($data['@type'] ?? null) === 'Product' && isset($data['name'])) {
            $out[] = $data;
        }

        foreach ($data as $value) {
            if (is_array($value)) {
                $out = array_merge($out, $this->findProducts($value));
            }
        }

        return $out;
    }

    /**
     * Drop the trailing " - SKU" code the storefront appends to display names
     * (e.g. "Artline Oil Pastal 24 Colors - ARCREOP-A-24S"). Only a token that
     * looks like a SKU (upper-case/digits, no spaces, contains a digit) is
     * stripped, so names with a genuine " - 25 mm" style suffix are kept.
     */
    private function tidyName(string $name): string
    {
        return trim(preg_replace('/\s+-\s+(?=[A-Z0-9-]*\d)[A-Z0-9][A-Z0-9-]{3,}$/', '', $name));
    }

    /**
     * Real specifications for a product, as a label => value map for
     * ProductSpecs. Three are always available from the listing itself - Brand
     * (read from the product name, falling back to the storefront house brand),
     * the confirmed OfficeLand leaf Category, and the SKU - which clears the
     * content-readiness floor on its own. The product's detail page is then
     * fetched for the authoritative SKU and whatever structured attributes it
     * publishes (Type, Colour, Material, Weight, pack size, size), so a pen ends
     * up distinguishable from its siblings. A failed/thin PDP simply leaves the
     * three listing specs, enriched by any pack size read from the name.
     *
     * @param  array{name: string, image: string, slug: string, url: string}  $item
     * @return array<string, string>
     */
    private function specsFor(array $item, string $path): array
    {
        $pdp = $item['url'] !== '' ? $this->fetchPdpSpecs($item['url']) : [];

        $sku = trim((string) ($pdp['sku'] ?? '')) ?: $this->skuFromSlug($item['slug']);

        $specs = [
            'Brand' => $this->brandFor($item['name'], (string) ($pdp['brand'] ?? '')),
            'Category' => $this->humanizeCategory($path),
        ];
        if ($sku !== '') {
            $specs['SKU'] = $sku;
        }

        // Structured attributes the PDP publishes (already label => value).
        foreach (($pdp['attributes'] ?? []) as $label => $value) {
            $specs[$label] = $value;
        }

        // Pack size is usually in the name ("Pack of 5", "12-Piece", "100
        // Sheets") when the PDP did not spell it out; keep the PDP's own value.
        if (! isset($specs['Pack size']) && ($pack = $this->packFromName($item['name'])) !== null) {
            $specs['Pack size'] = $pack;
        }

        return $specs;
    }

    /**
     * Fetch a product's detail page and read its authoritative SKU/brand from
     * the Product JSON-LD and its structured specs from the SEO meta
     * description, which the storefront renders server-side as "Label : value"
     * runs (e.g. "Type : Liquid Glue Weight : 40ml Number of Pieces : 36"). Only
     * labelled, space-delimited pairs are taken, so marketing prose and the
     * name-echo descriptions yield nothing rather than junk.
     *
     * @return array{sku?: string, brand?: string, attributes?: array<string, string>}
     */
    private function fetchPdpSpecs(string $url): array
    {
        try {
            $html = Http::timeout(20)->retry(1, 1000, throw: false)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; OfficeLandBot/1.0)'])
                ->get($url)
                ->body();
        } catch (\Throwable) {
            return [];
        }

        if ($html === '') {
            return [];
        }

        $out = [];

        if (preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $scripts)) {
            foreach ($scripts[1] as $json) {
                $data = json_decode(trim($json), true);
                if (! is_array($data)) {
                    continue;
                }
                foreach ($this->findProducts($data) as $product) {
                    if (($sku = trim((string) ($product['sku'] ?? ''))) !== '') {
                        $out['sku'] = $sku;
                    }
                    $brand = $product['brand'] ?? null;
                    $name = is_array($brand) ? ($brand['name'] ?? '') : $brand;
                    if (($name = trim((string) $name)) !== '') {
                        $out['brand'] = $name;
                    }
                    break 2;
                }
            }
        }

        if (preg_match('#<meta name="description" content="([^"]*)"#i', $html, $m)) {
            $attributes = $this->specsFromMeta(html_entity_decode($m[1], ENT_QUOTES));
            if ($attributes !== []) {
                $out['attributes'] = $attributes;
            }
        }

        return $out;
    }

    /**
     * Parse a storefront meta description into a label => value map. Labels must
     * be from the known set and delimited with a spaced colon (" : "), so the
     * genuine spec runs are picked up while prose such as "designSize:9X7 ..."
     * and plain name echoes are ignored. Values are capped to a spec-like length.
     *
     * @return array<string, string>
     */
    private function specsFromMeta(string $desc): array
    {
        $labels = 'Number of Pieces|Pack ?Size|Type|Colou?r|Material|Weight|Capacity|Dimensions|Size';
        if (! preg_match_all('/('.$labels.')\s+:\s+/i', $desc, $m, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $canonical = [
            'number of pieces' => 'Pack size',
            'pack size' => 'Pack size',
            'packsize' => 'Pack size',
            'color' => 'Colour',
            'colour' => 'Colour',
            'dimensions' => 'Size',
            'size' => 'Size',
            'type' => 'Type',
            'material' => 'Material',
            'weight' => 'Weight',
            'capacity' => 'Capacity',
        ];

        $out = [];
        $count = count($m[0]);
        for ($i = 0; $i < $count; $i++) {
            $raw = mb_strtolower(preg_replace('/\s+/', ' ', trim($m[1][$i][0])));
            $label = $canonical[$raw] ?? null;
            $start = $m[0][$i][1] + strlen($m[0][$i][0]);
            $end = ($i + 1 < $count) ? $m[0][$i + 1][1] : strlen($desc);
            $value = trim(substr($desc, $start, $end - $start));

            if ($label !== null && $value !== '' && mb_strlen($value) <= 60 && ! isset($out[$label])) {
                $out[$label] = $value;
            }
        }

        return $out;
    }

    /**
     * The product's own brand, read from the name against the brands Farook
     * carries; otherwise the brand the PDP declared, otherwise the house brand.
     * (The storefront's JSON-LD brand is always "FIS", its own house label, even
     * on third-party lines, so the name is the more truthful source.)
     */
    private function brandFor(string $name, string $pdpBrand): string
    {
        $brands = [
            'Faber-Castell', 'Faber Castell', 'Artline', 'Maped', 'Pentel', 'Pilot',
            'Deli', 'Casio', 'Stabilo', 'Staedtler', 'Pentonic', 'Uni-Ball', 'Uniball',
            'Sharpie', 'Bic', 'Kores', 'Pritt', 'Nataraj', 'Camlin', 'Doms', 'FIS',
        ];

        $lower = mb_strtolower($name);
        foreach ($brands as $brand) {
            if (str_contains($lower, mb_strtolower($brand))) {
                return $brand;
            }
        }

        return $pdpBrand !== '' ? $pdpBrand : 'FIS';
    }

    /** A pack/quantity spec read from the product name, or null. */
    private function packFromName(string $name): ?string
    {
        if (preg_match('/\b(\d{1,4})\s*-?\s*(?:piece|pieces|pcs|pack|pc)\b/i', $name, $m)) {
            return $m[1].' pieces';
        }
        if (preg_match('/\b(?:pack|set|box)\s+of\s+(\d{1,4})\b/i', $name, $m)) {
            return 'Pack of '.$m[1];
        }
        if (preg_match('/\b(\d{1,4})\s*(?:sheets|leaves)\b/i', $name, $m)) {
            return $m[1].' sheets';
        }

        return null;
    }

    /** "/office-supplies/writing/markers-highlighters/" => "Markers Highlighters". */
    private function humanizeCategory(string $path): string
    {
        $leaf = basename(rtrim($path, '/'));

        return (string) Str::of($leaf)->replace('-', ' ')->title();
    }

    /** Fallback SKU when the PDP is unavailable: the storefront slug's tail. */
    private function skuFromSlug(string $slug): string
    {
        $parts = explode('-', $slug);

        return strtoupper((string) end($parts));
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
        return Str::limit($name.' - office and school stationery supplied by OfficeLand across Nigeria. Request a quote for pack sizes, specifications and lead time.', 155, '');
    }

    private function description(string $name): string
    {
        return $name.' is an office and school stationery item that OfficeLand supplies to businesses, schools and resellers across Nigeria. '
            .'It is offered in trade pack sizes for day-to-day use in offices and classrooms. '
            .'Request a quote for available pack sizes, specifications, pricing and lead time; delivery is available nationwide.';
    }
}
