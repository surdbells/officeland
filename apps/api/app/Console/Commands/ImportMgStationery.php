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
 * Import the MG Stationery catalogue (mgstationeryonline.com.my) as draft
 * products under the office/school stationery departments.
 *
 * MG Stationery is a Johor Bahru office-stationery wholesaler running a HikaShop
 * (Joomla) storefront, so the catalogue is server-rendered HTML. The command
 * walks the three top category listings (kid, office, school) with HikaShop's
 * limitstart pagination, and for each product tile reads the name (from the
 * image title attribute) and its photo. The listing shows a 400x400 thumbnail;
 * the full-size upload is the same filename one directory up, so the thumbnail
 * segment is stripped to fetch the original.
 *
 * Each product becomes a DRAFT with its photo imported through the media
 * pipeline (derivatives queued), an ORIGINAL short description (never the site's
 * copy), and a leaf category inferred from the product name. OfficeLand supplies
 * these goods in Nigeria.
 *
 * Idempotent: products are keyed by slug, so a re-run updates rather than
 * duplicates, and a photo is only attached when the product has none.
 *
 *   php artisan catalogue:import-mgstationery                 # report (dry run)
 *   php artisan catalogue:import-mgstationery --apply         # create drafts + photos
 *   php artisan catalogue:import-mgstationery --apply --limit=3
 */
final class ImportMgStationery extends Command
{
    protected $signature = 'catalogue:import-mgstationery
        {--apply : Create the products and import the photos.}
        {--limit=0 : Cap the number of products (0 = all). For testing.}';

    protected $description = 'Import the MG Stationery catalogue as draft products under office/school supplies';

    private const BASE = 'https://www.mgstationeryonline.com.my';

    /** Top-level HikaShop category listings to walk. */
    private const CATEGORIES = ['66-kid', '67-office', '68-school'];

    /** Keyword => category path, longest/most-specific first. Unmatched fall to DEFAULT. */
    private const CATEGORY_MAP = [
        'colour pencil' => '/school-supplies/art-craft/crayons-colour-pencils/',
        'color pencil' => '/school-supplies/art-craft/crayons-colour-pencils/',
        'crayon' => '/school-supplies/art-craft/crayons-colour-pencils/',
        'play dough' => '/school-supplies/art-craft/',
        'water colour' => '/school-supplies/art-craft/drawing-colouring/',
        'water color' => '/school-supplies/art-craft/drawing-colouring/',
        'paint' => '/school-supplies/art-craft/drawing-colouring/',
        'pallette' => '/school-supplies/art-craft/drawing-colouring/',
        'palette' => '/school-supplies/art-craft/drawing-colouring/',
        'writing brush' => '/school-supplies/art-craft/drawing-colouring/',
        'compass' => '/school-supplies/maths-geometry/math-sets/',
        'ruler' => '/school-supplies/maths-geometry/rulers-stencils/',
        'pencil sharperner' => '/office-supplies/writing/pencils/',
        'pencil sharpener' => '/office-supplies/writing/pencils/',
        'sharperner' => '/office-supplies/writing/pencils/',
        'sharpener' => '/office-supplies/writing/pencils/',
        'pencil' => '/office-supplies/writing/pencils/',
        'highlighter' => '/office-supplies/writing/markers-highlighters/',
        'marker' => '/office-supplies/writing/markers-highlighters/',
        'eraser' => '/office-supplies/writing/correction-erasers/',
        'correction' => '/office-supplies/writing/correction-erasers/',
        'name tag' => '/office-supplies/labelling-identification/badges-lanyards/',
        'lanyard' => '/office-supplies/labelling-identification/badges-lanyards/',
        'id card' => '/office-supplies/labelling-identification/badges-lanyards/',
        'badge' => '/office-supplies/labelling-identification/badges-lanyards/',
        'stamp' => '/office-supplies/labelling-identification/stamps/',
        'pen holder' => '/office-supplies/desk-accessories/organisers-holders/',
        'scissor' => '/office-supplies/desk-accessories/cutting-tools/',
        'sccissor' => '/office-supplies/desk-accessories/cutting-tools/',
        'punch' => '/office-supplies/desk-accessories/staplers-punches/',
        'stapler' => '/office-supplies/desk-accessories/staplers-punches/',
        'clip' => '/office-supplies/desk-accessories/clips-pins/',
        'glue' => '/office-supplies/adhesives-tapes/glue/',
        'tape' => '/office-supplies/adhesives-tapes/tapes/',
        'calculator' => '/office-supplies/office-machines/calculators/',
        'sticky note' => '/paper-notebooks/sticky-notes/',
        'notebook' => '/paper-notebooks/notebooks-pads/',
        'note book' => '/paper-notebooks/notebooks-pads/',
        'gel pen' => '/office-supplies/writing/pens/',
        'table pen' => '/office-supplies/writing/pens/',
        'point pen' => '/office-supplies/writing/pens/',
        'polnt pen' => '/office-supplies/writing/pens/',
        'pen' => '/office-supplies/writing/pens/',
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
            if (isset($categoryIds[$path])) {
                continue;
            }
            $id = Category::query()->where('path', $path)->value('id');
            if ($id === null) {
                $this->error("Category missing: {$path}");

                return self::FAILURE;
            }
            $categoryIds[$path] = (int) $id;
        }

        $seenSlugs = [];
        $created = 0;
        $updated = 0;
        $photos = 0;
        $failed = 0;
        $byCategory = [];

        foreach (self::CATEGORIES as $category) {
            for ($start = 0; $start < 1000; $start += 21) {
                $products = $this->scrapePage($category, $start);
                if ($products === []) {
                    break;
                }
                $this->line("Category {$category} (from {$start}): ".count($products).' product(s)');

                foreach ($products as $item) {
                    $slug = Str::slug($item['name']);
                    if ($slug === '' || isset($seenSlugs[$slug])) {
                        continue;
                    }
                    $seenSlugs[$slug] = true;

                    if ($limit > 0 && ($created + $updated) >= $limit) {
                        break 3;
                    }

                    $path = $this->categoryFor($item['name']);
                    $byCategory[$path] = ($byCategory[$path] ?? 0) + 1;

                    if (! $apply) {
                        continue;
                    }

                    try {
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

                        // Record real specs from the product's PDP (one fetch each):
                        // brand, type, model code and any characteristics table.
                        ProductSpecs::set($product, $this->specsFor($item['name'], $item['url']));

                        if (! $product->media()->exists()) {
                            $tmp = tempnam(sys_get_temp_dir(), 'mg').'.'.(pathinfo($item['image'], PATHINFO_EXTENSION) ?: 'jpg');
                            $response = Http::timeout(45)->retry(2, 1500, throw: false)->sink($tmp)->get($item['image']);

                            if ($response->successful()) {
                                $media = $ingestor->ingest($tmp, [
                                    'alt_text' => $item['name'],
                                    'source' => 'mgstationery',
                                    'source_url' => $item['image'],
                                ]);
                                $product->media()->syncWithoutDetaching([$media->id => ['role' => 'primary', 'sort_order' => 0]]);
                                // Queued, not inline: the worker generates them; the
                                // storefront shows the original meanwhile.
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
     * Products on one HikaShop listing page: name (from the image title), the
     * full-size image URL (the listing links a 400x400 thumbnail) and the PDP
     * URL (the tile anchor wraps the image). The PDP is where the real specs are.
     *
     * @return list<array{name: string, image: string, url: string}>
     */
    private function scrapePage(string $category, int $start): array
    {
        $url = self::BASE.'/category-listing/category/'.$category;
        $query = $start > 0 ? ['limitstart' => $start, 'limit' => 21] : [];

        $html = Http::timeout(30)
            ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; OfficeLandImporter/1.0)'])
            ->retry(2, 1500, throw: false)
            ->get($url, $query)
            ->body();

        if ($html === '') {
            return [];
        }

        // Each tile is an anchor to the product page wrapping its listing image;
        // capture the href and the img tag together so name, photo and PDP stay
        // paired (pairing by position would drift when a tile is malformed).
        if (! preg_match_all(
            '#<a\b[^>]*\bhref="([^"]*/product/[^"]+)"[^>]*>\s*(<img\b[^>]*class="[^"]*hikashop_product_listing_image[^"]*"[^>]*>)#is',
            $html,
            $tiles,
            PREG_SET_ORDER,
        )) {
            return [];
        }

        $out = [];
        foreach ($tiles as $tile) {
            $pdp = $tile[1];
            $tag = $tile[2];
            if (! preg_match('#\btitle="([^"]*)"#i', $tag, $tm)) {
                continue;
            }
            $name = trim(html_entity_decode($tm[1], ENT_QUOTES));
            if ($name === '' || mb_strlen($name) < 3 || mb_strlen($name) > 150) {
                continue;
            }

            // Prefer the lazy-load real source over the placeholder src.
            $image = null;
            foreach (['data-src', 'data-original', 'data-lazy', 'src'] as $attr) {
                if (preg_match('#\b'.$attr.'="([^"]+\.(?:jpe?g|png|webp|gif)[^"]*)"#i', $tag, $sm)) {
                    $image = $sm[1];
                    break;
                }
            }
            if ($image === null) {
                continue;
            }

            // The listing shows a 400x400 thumbnail; the original is the same
            // filename one directory up.
            $image = preg_replace('#/thumbnails/\d+x\d+[a-z]?/#i', '/', $image);
            $image = strtok($image, '?');

            // Absolute URL on the shop's host.
            if (str_starts_with($image, '//')) {
                $image = 'https:'.$image;
            } elseif (str_starts_with($image, '/')) {
                $image = self::BASE.$image;
            }

            if (str_starts_with($pdp, '//')) {
                $pdp = 'https:'.$pdp;
            } elseif (str_starts_with($pdp, '/')) {
                $pdp = self::BASE.$pdp;
            }

            $out[] = ['name' => $name, 'image' => $image, 'url' => $pdp];
        }

        return $out;
    }

    /**
     * The real specifications for a product. HikaShop has no brand/manufacturer
     * field, so brand is read from the name prefix and the type from the PDP
     * URL's category segment; the PDP is then fetched once for its product code
     * (SKU) and characteristics table (e.g. the colour options). Pack size, point
     * size and dimensions the site bakes into the product name are added last so
     * they never displace a real on-page value.
     *
     * @return array<string, string>
     */
    private function specsFor(string $name, string $url): array
    {
        $specs = [];

        if (($brand = $this->brandFrom($name)) !== null) {
            $specs['Brand'] = $brand;
        }
        if (($type = $this->typeFromUrl($url)) !== null) {
            $specs['Type'] = $type;
        }

        $html = Http::timeout(30)
            ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; OfficeLandImporter/1.0)'])
            ->retry(2, 1500, throw: false)
            ->get($url)
            ->body();

        if ($html !== '') {
            if (preg_match('#id="hikashop_product_code_main"[^>]*>([^<]+)<#i', $html, $m)) {
                $code = trim(html_entity_decode($m[1], ENT_QUOTES));
                if ($code !== '') {
                    $specs['Model'] = $code;
                }
            }

            foreach ($this->characteristics($html) as $label => $value) {
                $specs[$label] = $value;
            }
        }

        // Union: keep on-page values, add name-derived cues that don't collide.
        return $specs + $this->specsFromName($name);
    }

    /** The brand read from a known leading token of the product name, or null. */
    private function brandFrom(string $name): ?string
    {
        $upper = mb_strtoupper(ltrim($name));

        // Canonical brand => uppercase name prefixes this wholesaler ships under.
        $brands = [
            'M&G' => ['M&G', 'M & G'],
            'Maped' => ['MAPED'],
            'Deli' => ['DELI'],
            'Faber-Castell' => ['FABER-CASTELL', 'FABER CASTELL'],
            'Stabilo' => ['STABILO'],
            'Staedtler' => ['STAEDTLER'],
            'Pilot' => ['PILOT'],
            'Pentel' => ['PENTEL'],
            'Zebra' => ['ZEBRA'],
            'Casio' => ['CASIO'],
            'Kangaro' => ['KANGARO'],
        ];

        foreach ($brands as $canonical => $prefixes) {
            foreach ($prefixes as $prefix) {
                if ($upper === $prefix || str_starts_with($upper, $prefix.' ')) {
                    return $canonical;
                }
            }
        }

        return null;
    }

    /** The product type from the category segment of a PDP URL (…/{type}/product/…). */
    private function typeFromUrl(string $url): ?string
    {
        if (! preg_match('#/([^/]+)/product/#', $url, $m)) {
            return null;
        }

        $type = trim(preg_replace('/\s+/', ' ', str_replace('-', ' ', $m[1])) ?? '');

        return $type === '' ? null : Str::title($type);
    }

    /**
     * The HikaShop characteristics table as a [label => value] map. A variant row
     * is a <select>, so its option labels are gathered (e.g. "Blue, Red, Black");
     * a plain row takes its cell text.
     *
     * @return array<string, string>
     */
    private function characteristics(string $html): array
    {
        if (! preg_match('#<table[^>]*class="[^"]*hikashop_product_characteristics_table[^"]*"[^>]*>(.*?)</table>#is', $html, $tm)) {
            return [];
        }

        $out = [];
        if (preg_match_all('#<tr[^>]*>\s*<td>(.*?)</td>\s*<td>(.*?)</td>\s*</tr>#is', $tm[1], $rows, PREG_SET_ORDER)) {
            foreach ($rows as $row) {
                $label = trim(html_entity_decode(strip_tags($row[1]), ENT_QUOTES));

                if (preg_match_all('#<option[^>]*>(.*?)</option>#is', $row[2], $opts)) {
                    $values = array_values(array_unique(array_filter(array_map(
                        static fn (string $o): string => trim(html_entity_decode(strip_tags($o), ENT_QUOTES)),
                        $opts[1],
                    ))));
                    $value = implode(', ', $values);
                } else {
                    $value = trim(html_entity_decode(strip_tags($row[2]), ENT_QUOTES));
                }

                if ($label !== '' && $value !== '') {
                    // "Color" -> "Colour" to match the house spelling used elsewhere.
                    $out[Str::title(mb_strtolower($label) === 'color' ? 'Colour' : $label)] = $value;
                }
            }
        }

        return $out;
    }

    /**
     * Pack size, colour count, dimensions, volume and writing-point size that the
     * site encodes inside the product name (e.g. "48'S/BOX", "( 25ML )", "0.5").
     *
     * @return array<string, string>
     */
    private function specsFromName(string $name): array
    {
        $specs = [];

        if (preg_match("#(\d+)\s*(?:'S|PCS)\s*/\s*(BOX|SET|CARD|PKT|PACK)#i", $name, $m)) {
            $specs['Pack quantity'] = $m[1].' pcs/'.mb_strtolower($m[2]);
        }

        if (preg_match('#(\d+)\s*COLOU?RS#i', $name, $m)) {
            $specs['Colours'] = $m[1];
        }

        if (preg_match('#(\d+(?:\.\d+)?\s*(?:X|\*)\s*\d+(?:\.\d+)?(?:\s*(?:X|\*)\s*\d+(?:\.\d+)?)?\s*MM)#i', $name, $m)) {
            $specs['Dimensions'] = trim(preg_replace('/\s+/', ' ', $m[1]) ?? $m[1]);
        }

        if (preg_match('#(\d+(?:\.\d+)?)\s*ML\b#i', $name, $m)) {
            $specs['Volume'] = $m[1].' ml';
        }

        if (preg_match('#(?<![\d.])(0\.\d|1\.0)(?![\d.])#', $name, $m)) {
            $specs['Point size'] = $m[1].' mm';
        }

        return $specs;
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
        return Str::limit($name.', supplied by OfficeLand in Nigeria with nationwide delivery. Request a quote for pack sizes, colours and lead time.', 155, '');
    }

    private function description(string $name): string
    {
        return $name.' is an office and school stationery item that OfficeLand supplies across Nigeria. '
            .'It is a practical, everyday supply for desks, classrooms and workplaces, stocked for bulk and corporate orders. '
            .'Request a quote for available colours, pack sizes, pricing and lead time; delivery is available nationwide.';
    }
}
