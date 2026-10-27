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
 * Import the Herman Miller OFFICE furniture range as draft products.
 *
 * store.hermanmiller.com is a Salesforce Commerce Cloud (Demandware) storefront.
 * Its legacy products.json feed is gone (returns 410), but the XML sitemap index
 * at /sitemap_index.xml points at /sitemap_0-product.xml, which lists every PDP.
 * Each PDP server-renders two schema.org JSON-LD blocks: a ProductGroup (name,
 * canonical URL, and a hasVariant[] array whose first entry carries a hi-res
 * image) and a BreadcrumbList (the live department -> category -> leaf path).
 * Both are read as structured data rather than scraped from brittle markup.
 *
 * The store mixes office furniture with home decor (sofas, dining, rugs,
 * lighting, bedroom, outdoor), gaming and desk accessories. Scope here is OFFICE
 * FURNITURE ONLY, decided authoritatively by the breadcrumb: the first crumb must
 * be "Office", and the leaf must map to a confirmed OfficeLand furniture category
 * (seating, desks & tables, storage furniture, partitions/screens). Office items
 * that are not furniture - desk accessories / desk organisation - have no
 * furniture leaf and are skipped. Everything under Home, Furniture (home),
 * Living Room, Decor, Gaming, Lighting, Outdoor, etc. is skipped.
 *
 * Each kept product becomes a DRAFT with its manufacturer photo through the media
 * pipeline (derivatives queued) and an ORIGINAL short + long description (never
 * Herman Miller's own marketing copy). Idempotent: keyed by the slug of the
 * product-group name, so a re-run updates rather than duplicates, and a photo is
 * attached only when the product has none. Product `source` is left unset so it
 * defaults to `manual` (the CHECK constraint allows only icecat/csv/manual); the
 * media row records source `hermanmiller` for provenance.
 *
 *   php artisan catalogue:import-hermanmiller                 # report (dry run)
 *   php artisan catalogue:import-hermanmiller --apply         # create drafts + photos
 *   php artisan catalogue:import-hermanmiller --apply --limit=3
 */
final class ImportHermanMiller extends Command
{
    protected $signature = 'catalogue:import-hermanmiller
        {--apply : Create the products and import the photos.}
        {--limit=0 : Cap the number of products imported (0 = all). For testing.}';

    protected $description = 'Import Herman Miller office furniture as draft products';

    private const SITEMAP = 'https://store.hermanmiller.com/sitemap_0-product.xml';

    private const UA = 'Mozilla/5.0 (compatible; OfficeLandBot/1.0; +https://officeland.ng)';

    /**
     * The department (breadcrumb position 1) that scopes the import. Anything
     * whose first crumb is not exactly this is home decor / gaming / outdoor and
     * is skipped.
     */
    private const OFFICE_DEPARTMENT = 'Office';

    // Confirmed OfficeLand leaf category paths (verified against the live tree at
    // the start of the run; a missing one aborts rather than mis-filing).
    private const SEATING = '/office-furniture/seating/';

    private const TASK_CHAIRS = '/office-furniture/seating/ergonomic-task-chairs/';

    private const EXEC_CHAIRS = '/office-furniture/seating/executive-chairs/';

    private const CONF_CHAIRS = '/office-furniture/seating/conference-chairs/';

    private const VISITOR_CHAIRS = '/office-furniture/seating/visitor-reception-chairs/';

    private const LOUNGE_CHAIRS = '/office-furniture/seating/recliner-lounge-chairs/';

    private const DESKS_TABLES = '/office-furniture/desks-tables/';

    private const HEIGHT_DESKS = '/office-furniture/desks-tables/height-adjustable-desks/';

    private const EXEC_DESKS = '/office-furniture/desks-tables/executive-desks/';

    private const WORKSTATIONS = '/office-furniture/desks-tables/workstations/';

    private const CONF_TABLES = '/office-furniture/desks-tables/conference-tables/';

    private const RECEPTION_DESKS = '/office-furniture/desks-tables/reception-desks/';

    private const STORAGE = '/office-furniture/storage-furniture/';

    private const FILING = '/office-furniture/storage-furniture/filing-cabinets/';

    private const BOOKCASES = '/office-furniture/storage-furniture/bookcases-shelving/';

    private const CUPBOARDS = '/office-furniture/storage-furniture/cupboards-lockers/';

    private const PARTITIONS = '/office-furniture/partitions-screens/';

    /**
     * Herman Miller breadcrumb leaf (lower-cased) => confirmed OfficeLand leaf.
     * Matched on the LAST crumb first, then (in categoryFor) on the second-level
     * crumb, then on name keywords. A leaf that resolves to null means "Office
     * department but not furniture" (e.g. desk accessories) and is skipped.
     *
     * @var array<string, string|null>
     */
    private const LEAF_MAP = [
        // Seating
        'ergonomic office chairs' => self::TASK_CHAIRS,
        'ergonomic chairs' => self::TASK_CHAIRS,
        'home office chairs' => self::TASK_CHAIRS,
        'task chairs' => self::TASK_CHAIRS,
        'executive chairs' => self::EXEC_CHAIRS,
        'executive office chairs' => self::EXEC_CHAIRS,
        'conference chairs' => self::CONF_CHAIRS,
        'conference room chairs' => self::CONF_CHAIRS,
        'meeting chairs' => self::CONF_CHAIRS,
        'side chairs' => self::VISITOR_CHAIRS,
        'guest chairs' => self::VISITOR_CHAIRS,
        'stacking chairs' => self::VISITOR_CHAIRS,
        'multipurpose chairs' => self::VISITOR_CHAIRS,
        'office stools' => self::SEATING,
        'stools' => self::SEATING,
        'lounge chairs' => self::LOUNGE_CHAIRS,
        'lounge seating' => self::LOUNGE_CHAIRS,
        'office chairs' => self::SEATING,

        // Desks & tables
        'standing desks' => self::HEIGHT_DESKS,
        'sit-to-stand desks' => self::HEIGHT_DESKS,
        'height adjustable desks' => self::HEIGHT_DESKS,
        'executive desks' => self::EXEC_DESKS,
        'workstations' => self::WORKSTATIONS,
        'conference tables' => self::CONF_TABLES,
        'meeting tables' => self::CONF_TABLES,
        'reception desks' => self::RECEPTION_DESKS,
        'desks & office tables' => self::DESKS_TABLES,
        'office tables' => self::DESKS_TABLES,
        'desks' => self::DESKS_TABLES,
        'tables' => self::DESKS_TABLES,

        // Storage furniture
        'filing cabinets' => self::FILING,
        'file cabinets' => self::FILING,
        'bookcases & shelves' => self::BOOKCASES,
        'bookcases' => self::BOOKCASES,
        'shelving' => self::BOOKCASES,
        'cabinets' => self::CUPBOARDS,
        'cupboards' => self::CUPBOARDS,
        'lockers' => self::CUPBOARDS,
        'credenzas' => self::STORAGE,
        'storage' => self::STORAGE,
        'office storage' => self::STORAGE,

        // Partitions / screens
        'screens' => self::PARTITIONS,
        'partitions' => self::PARTITIONS,
        'privacy screens' => self::PARTITIONS,

        // Office department, but NOT furniture -> skip (no furniture leaf).
        'desk accessories' => null,
        'desk organization' => null,
        'desk organisation' => null,
        // "Home Office Storage" is deliberately NOT mapped here: it mixes real
        // storage furniture (credenzas, shelving) with desktop organisers (e.g.
        // the Uten Silo). It falls through to the name fallback, which files the
        // furniture and null-skips the accessories.
        'monitor arms' => null,
        'keyboard trays' => null,
        'lighting' => null,
        'task lighting' => null,
        'desk lamps' => null,
        'accessories' => null,
        'work accessories' => null,
        'technology' => null,
        'cable management' => null,
        'footrests' => null,
        'whiteboards' => null,
    ];

    /**
     * Name-keyword fallback when the breadcrumb leaf is unknown but the first
     * crumb is still "Office". Most-specific first; the first hit wins.
     *
     * @var array<string, string|null>
     */
    private const NAME_MAP = [
        'gaming' => null,              // gaming gear filed under Office nav -> skip
        'monitor arm' => null,
        'keyboard tray' => null,
        'pencil cup' => null,
        'tray' => null,
        'organizer' => null,
        'organiser' => null,
        'footrest' => null,
        'lamp' => null,

        'standing desk' => self::HEIGHT_DESKS,
        'sit-to-stand' => self::HEIGHT_DESKS,
        'height-adjustable' => self::HEIGHT_DESKS,
        'executive desk' => self::EXEC_DESKS,
        'reception desk' => self::RECEPTION_DESKS,
        'conference table' => self::CONF_TABLES,
        'meeting table' => self::CONF_TABLES,
        'workstation' => self::WORKSTATIONS,
        'desk' => self::DESKS_TABLES,
        'table' => self::DESKS_TABLES,

        'filing cabinet' => self::FILING,
        'file cabinet' => self::FILING,
        'lateral file' => self::FILING,
        'pedestal' => self::FILING,       // mobile drawer pedestal = filing furniture
        'bookcase' => self::BOOKCASES,
        'shelf' => self::BOOKCASES,
        'shelving' => self::BOOKCASES,
        'credenza' => self::STORAGE,
        'storage case' => self::STORAGE,
        'cabinet' => self::CUPBOARDS,
        'locker' => self::CUPBOARDS,
        'storage' => self::STORAGE,

        'screen' => self::PARTITIONS,
        'partition' => self::PARTITIONS,

        'executive chair' => self::EXEC_CHAIRS,
        'conference chair' => self::CONF_CHAIRS,
        'side chair' => self::VISITOR_CHAIRS,
        'guest chair' => self::VISITOR_CHAIRS,
        'stacking chair' => self::VISITOR_CHAIRS,
        'stool' => self::SEATING,
        'lounge' => self::LOUNGE_CHAIRS,
        'task chair' => self::TASK_CHAIRS,
        'chair' => self::SEATING,       // seating fallback for any office chair
    ];

    public function handle(MediaIngestor $ingestor): int
    {
        @ini_set('memory_limit', '512M');

        $apply = (bool) $this->option('apply');
        $limit = (int) $this->option('limit');

        // Resolve every confirmed leaf from the DB up front; abort if any mapped
        // path is missing so products are never mis-filed.
        $paths = array_values(array_unique(array_filter(array_merge(
            array_values(self::LEAF_MAP),
            array_values(self::NAME_MAP),
        ))));
        /** @var array<string, int> $categoryIds */
        $categoryIds = [];
        foreach ($paths as $path) {
            $id = Category::query()->where('path', $path)->value('id');
            if ($id === null) {
                $this->error("Category missing: {$path}");

                return self::FAILURE;
            }
            $categoryIds[$path] = (int) $id;
        }

        $urls = $this->productUrls();
        $this->line('Product URLs in sitemap (en_US, de-duplicated): '.count($urls));

        $created = 0;
        $updated = 0;
        $photos = 0;
        $failed = 0;
        $byDept = [];        // skipped, by non-office department
        $skippedNonFurniture = 0;
        $skippedNoData = 0;  // Office items whose PDP carries no product JSON-LD
        $byCategory = [];
        $seenSlugs = [];

        foreach ($urls as $url) {
            if ($limit > 0 && ($created + $updated) >= $limit && $apply) {
                break;
            }
            if ($limit > 0 && array_sum($byCategory) >= $limit && ! $apply) {
                break;
            }

            $pdp = $this->parsePdp($url);
            if ($pdp === null) {
                continue;
            }
            [$name, $image, $crumbs] = [$pdp['name'], $pdp['image'], $pdp['crumbs']];

            // Office scope gate: first crumb must be the Office department.
            $dept = $crumbs[0] ?? '(none)';
            if ($dept !== self::OFFICE_DEPARTMENT) {
                $byDept[$dept] = ($byDept[$dept] ?? 0) + 1;

                continue;
            }

            // Office department, but the PDP carries no product JSON-LD (only a
            // breadcrumb) - the Collegiate Collection's university-branded chairs
            // and a few others. There is no clean structured name/image, so they
            // are skipped rather than fabricated, and counted so the gap is
            // visible rather than silent.
            $slug = Str::slug($name);
            if ($name === '' || $image === '' || $slug === '') {
                $skippedNoData++;

                continue;
            }
            if (isset($seenSlugs[$slug])) {
                continue;
            }

            // Office, but map to a furniture leaf. null => not furniture, skip.
            $path = $this->categoryFor($crumbs, $name);
            if ($path === null) {
                $skippedNonFurniture++;

                continue;
            }
            $seenSlugs[$slug] = true;

            $byCategory[$path] = ($byCategory[$path] ?? 0) + 1;

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
                        // A template blurb is machine copy: stamp it so the
                        // spec-driven DescriptionDrafter may replace it and so the
                        // readiness gate holds it until it is approved.
                        'description_generated_at' => now(),
                        'primary_category_id' => $categoryIds[$path],
                        'availability' => 'lead_time',
                    ],
                );
                $product->categories()->syncWithoutDetaching([$categoryIds[$path] => ['sort_order' => 0]]);
                $exists ? $updated++ : $created++;

                // Record the product's real, factual specs (type, brand, size,
                // configuration options) so each draft clears the content-
                // readiness floor of three specs. Never HM's marketing prose.
                ProductSpecs::set($product, $this->specsFrom($pdp));

                if (! $product->media()->exists()) {
                    $clean = strtok($image, '?');
                    $tmp = tempnam(sys_get_temp_dir(), 'hm').'.'.(pathinfo($clean, PATHINFO_EXTENSION) ?: 'png');
                    $response = Http::timeout(60)->retry(2, 1500, throw: false)
                        ->withHeaders(['User-Agent' => self::UA])->sink($tmp)->get($image);

                    if ($response->successful()) {
                        $media = $ingestor->ingest($tmp, [
                            'alt_text' => $name,
                            'source' => 'hermanmiller',
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

        $this->newLine();
        $this->table(['Outcome', 'Count'], [
            [$apply ? 'Created' : 'Would import', $apply ? $created : array_sum($byCategory)],
            ['Updated (already existed)', $updated],
            ['Photos imported', $photos],
            ['Skipped: Office but not furniture', $skippedNonFurniture],
            ['Skipped: Office, no product JSON-LD', $skippedNoData],
            ['Skipped: non-office departments', array_sum($byDept)],
            ['Failed', $failed],
        ]);

        $this->newLine();
        $this->line('Office furniture by category:');
        ksort($byCategory);
        foreach ($byCategory as $path => $n) {
            $this->line("  {$n}\t{$path}");
        }

        $this->newLine();
        $this->line('Skipped non-office departments:');
        arsort($byDept);
        foreach ($byDept as $dept => $n) {
            $this->line("  {$n}\t{$dept}");
        }

        if (! $apply) {
            $this->comment('Dry run. Add --apply to create the draft products and import the photos.');
        } else {
            $this->info('Created as DRAFT. Review and publish in the admin. Derivatives are generating on the media queue.');
        }

        return self::SUCCESS;
    }

    /**
     * Every en_US PDP URL from the product sitemap, de-duplicated by the numeric
     * product-group id (the trailing /<id>.html), so each product is visited
     * once regardless of locale alternates.
     *
     * @return list<string>
     */
    private function productUrls(): array
    {
        $xml = Http::timeout(60)->retry(2, 2000, throw: false)
            ->withHeaders(['User-Agent' => self::UA])
            ->get(self::SITEMAP)
            ->body();

        if ($xml === '' || ! preg_match_all('#<loc>\s*(https://store\.hermanmiller\.com/[^<\s]+?/(\d+)\.html\?lang=en_US)\s*</loc>#', $xml, $m, PREG_SET_ORDER)) {
            return [];
        }

        $out = [];
        $seen = [];
        foreach ($m as $row) {
            $id = $row[2];
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $out[] = $row[1];
        }

        return $out;
    }

    /**
     * Fetch one PDP and return its product-group name, primary hi-res image and
     * breadcrumb crumb names from the schema.org JSON-LD. The breadcrumb is
     * always returned when present (it is what the Office scope gate needs);
     * name/image come back empty for a PDP that ships only a breadcrumb and no
     * Product/ProductGroup block, so the caller can count that gap. Returns null
     * only when the page has no breadcrumb at all.
     *
     * @return array{name: string, image: string, crumbs: list<string>, brand: string, options: array<string, string>, colours: string, details: array<string, string>}|null
     */
    private function parsePdp(string $url): ?array
    {
        $html = Http::timeout(45)->retry(2, 1500, throw: false)
            ->withHeaders(['User-Agent' => self::UA])
            ->get($url)
            ->body();

        if ($html === '' || ! preg_match_all('#<script[^>]*type="application/ld\+json"[^>]*>(.*?)</script>#s', $html, $scripts)) {
            return null;
        }

        $name = '';
        $image = '';
        $crumbs = [];
        $brand = '';
        /** @var array<string, array<string, true>> $options  option name => set of distinct values */
        $options = [];
        /** @var array<string, true> $colours */
        $colours = [];

        foreach ($scripts[1] as $json) {
            $data = json_decode(trim($json), true);
            if (! is_array($data)) {
                continue;
            }

            foreach ($this->flatten($data) as $node) {
                $type = $node['@type'] ?? null;

                if (($type === 'ProductGroup' || $type === 'Product') && $name === '' && isset($node['name'])) {
                    $name = $this->tidyName((string) $node['name']);
                    $image = $this->firstImage($node);
                }

                if ($type === 'BreadcrumbList' && $crumbs === [] && isset($node['itemListElement'])) {
                    foreach ((array) $node['itemListElement'] as $el) {
                        $n = trim((string) ($el['name'] ?? ''));
                        if ($n !== '') {
                            $crumbs[] = $n;
                        }
                    }
                }

                // Brand is carried on the group/variant node as {name: ...}.
                if ($brand === '') {
                    $b = $node['brand'] ?? null;
                    $brand = trim((string) (is_array($b) ? ($b['name'] ?? '') : (is_string($b) ? $b : '')));
                }

                // The ProductGroup's variants each carry the configuration facts
                // (Arms, Tilt, Back Support, Base, Seat Style, Caster, ...) as
                // schema.org PropertyValue pairs, plus a colour. Aggregate the
                // distinct values across every variant so the product records the
                // real options it ships in, not one configuration.
                foreach ($this->propertyPairs($node['additionalProperty'] ?? null) as [$pn, $pv]) {
                    $options[$pn][$pv] = true;
                }
                $colour = $node['color'] ?? null;
                if (is_string($colour) && $colour !== '') {
                    // "Graphite, Black" (seat, frame) -> the leading colour name.
                    $lead = trim((string) strtok($colour, ','));
                    if ($lead !== '') {
                        $colours[$lead] = true;
                    }
                }
            }
        }

        if ($crumbs === []) {
            return null;
        }

        /** @var array<string, string> $optionStrings */
        $optionStrings = [];
        foreach ($options as $pn => $set) {
            $optionStrings[$pn] = Str::limit(implode(', ', array_slice(array_keys($set), 0, 8)), 200, '');
        }

        return [
            'name' => $name,
            'image' => $image,
            'crumbs' => $crumbs,
            'brand' => $brand,
            'options' => $optionStrings,
            'colours' => Str::limit(implode(', ', array_slice(array_keys($colours), 0, 10)), 200, ''),
            'details' => $this->htmlSpecs($html),
        ];
    }

    /**
     * Normalise a schema.org additionalProperty (a single PropertyValue or a
     * list of them) to a list of [name, value] string pairs, blanks dropped.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function propertyPairs(mixed $prop): array
    {
        if (! is_array($prop)) {
            return [];
        }
        // A single {name, value} object vs a list of them.
        $list = isset($prop['name']) ? [$prop] : $prop;

        $out = [];
        foreach ($list as $pv) {
            if (! is_array($pv)) {
                continue;
            }
            $n = trim((string) ($pv['name'] ?? ''));
            $v = trim((string) ($pv['value'] ?? ''));
            if ($n !== '' && $v !== '') {
                $out[] = [$n, $v];
            }
        }

        return $out;
    }

    /**
     * Factual specs rendered server-side into the SFCC PDP summary as a
     * definition list (<dt>label</dt><dd>value</dd>) and the detailed dimension
     * list. Only whitelisted, genuinely factual labels are kept (brand,
     * collection, dimensions, weight, assembly, warranty, and the ergonomic
     * seat-height / weight-capacity measures); marketing blurbs and icon-only
     * "lifestyle" rows are ignored.
     *
     * @return array<string, string>
     */
    private function htmlSpecs(string $html): array
    {
        $decode = static fn (string $s): string => trim(preg_replace('/\s+/', ' ',
            strip_tags(html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8'))) ?? '');

        // dt => canonical spec label.
        $wanted = [
            'Brand' => 'Brand',
            'Collection' => 'Collection',
            'General Dimensions' => 'Dimensions',
            'Product Weight' => 'Weight',
            'Assembly' => 'Assembly',
            'Warranty' => 'Warranty',
        ];

        $out = [];
        if (preg_match_all('#<dt>\s*(.*?)\s*</dt>\s*<dd[^>]*>(.*?)</dd>#s', $html, $pairs, PREG_SET_ORDER)) {
            foreach ($pairs as $pair) {
                $label = $decode($pair[1]);
                if (! isset($wanted[$label]) || isset($out[$wanted[$label]])) {
                    continue;
                }
                $value = $decode($pair[2]);
                if ($label === 'Warranty') {
                    // Drop the boilerplate tail ("Terms and conditions ... Learn more").
                    $value = trim((string) preg_split('/\s*Terms and conditions/i', $value)[0]);
                }
                if ($value !== '') {
                    $out[$wanted[$label]] = Str::limit($value, 240, '');
                }
            }
        }

        // The detailed dimension block adds ergonomic measures (seat height,
        // weight capacity) that the one-line General Dimensions summary omits.
        if (preg_match('#<ul[^>]*dimension-body[^>]*>(.*?)</ul>#s', $html, $ul)
            && preg_match_all('#<li[^>]*>(.*?)</li>#s', $ul[1], $lis)) {
            $ergonomic = ['Seat Height' => 'Seat height', 'Weight Capacity' => 'Weight capacity'];
            foreach ($lis[1] as $li) {
                // e.g. "Seat Height (in): 18 1/2" or "Weight Capacity (lbs): 300".
                if (preg_match('/^(.*?)\s*\(([^)]*)\)\s*:\s*(.+)$/', $decode($li), $m)) {
                    $label = trim($m[1]);
                    if (isset($ergonomic[$label]) && ! isset($out[$ergonomic[$label]])) {
                        $out[$ergonomic[$label]] = Str::limit(trim($m[3]).' '.trim($m[2]), 60, '');
                    }
                }
            }
        }

        return $out;
    }

    /**
     * Assemble the product's factual spec map from the parsed PDP. Order matters:
     * most identifying facts first. Blank values are dropped by ProductSpecs.
     *
     * @param  array{name: string, image: string, crumbs: list<string>, brand: string, options: array<string, string>, colours: string, details: array<string, string>}  $pdp
     * @return array<string, string>
     */
    private function specsFrom(array $pdp): array
    {
        $details = $pdp['details'];

        $specs = [
            // Breadcrumb leaf is the product type (e.g. "Side Chairs").
            'Type' => $this->tidyName((string) end($pdp['crumbs'])),
            'Brand' => $pdp['brand'] !== '' ? $pdp['brand'] : 'Herman Miller',
            'Collection' => $details['Collection'] ?? '',
            'Dimensions' => $details['Dimensions'] ?? '',
            'Seat height' => $details['Seat height'] ?? '',
            'Weight capacity' => $details['Weight capacity'] ?? '',
            'Weight' => $details['Weight'] ?? '',
        ];

        // Configuration facts from the variants (Arms, Tilt, Back Support, Base,
        // Seat Style, Caster, ...). Each becomes a shared, filterable facet.
        foreach ($pdp['options'] as $name => $value) {
            $specs[$name] = $value;
        }

        if ($pdp['colours'] !== '') {
            $specs['Colour options'] = $pdp['colours'];
        }
        $specs['Assembly'] = $details['Assembly'] ?? '';
        $specs['Warranty'] = $details['Warranty'] ?? '';

        return $specs;
    }

    /**
     * Best primary image for a Product / ProductGroup node: its own `image`, else
     * the first variant that carries one. HM serves these as hi-res PNGs on
     * images.hermanmiller.group.
     *
     * @param  array<string, mixed>  $node
     */
    private function firstImage(array $node): string
    {
        $pick = static function ($img): string {
            if (is_string($img)) {
                return $img;
            }
            if (is_array($img)) {
                $first = $img[0] ?? null;

                return is_string($first) ? $first : (is_array($first) ? (string) ($first['url'] ?? '') : '');
            }

            return '';
        };

        $own = $pick($node['image'] ?? null);
        if ($own !== '') {
            return $own;
        }

        foreach ((array) ($node['hasVariant'] ?? []) as $variant) {
            if (is_array($variant)) {
                $img = $pick($variant['image'] ?? null);
                if ($img !== '') {
                    return $img;
                }
            }
        }

        return '';
    }

    /**
     * Recursively collect every associative node from a decoded JSON-LD tree
     * (handles @graph and nested arrays), so ProductGroup and BreadcrumbList are
     * found wherever they sit.
     *
     * @param  array<mixed>  $data
     * @return list<array<string, mixed>>
     */
    private function flatten(array $data): array
    {
        $out = [];

        if (isset($data['@type'])) {
            $out[] = $data;
        }

        foreach ($data as $value) {
            if (is_array($value)) {
                $out = array_merge($out, $this->flatten($value));
            }
        }

        return $out;
    }

    /**
     * Resolve a confirmed OfficeLand leaf for an Office-department product.
     * Prefer the breadcrumb (leaf crumb, then the second-level crumb), then fall
     * back to name keywords. Returns null for Office items that are not furniture
     * (desk accessories, lighting, gaming, etc.), which are then skipped.
     *
     * @param  list<string>  $crumbs
     */
    private function categoryFor(array $crumbs, string $name): ?string
    {
        // Leaf crumb (most specific) then the broader second-level crumb.
        $candidates = [];
        if ($crumbs !== []) {
            $candidates[] = mb_strtolower((string) end($crumbs));
        }
        if (isset($crumbs[1])) {
            $candidates[] = mb_strtolower($crumbs[1]);
        }
        foreach ($candidates as $c) {
            if (array_key_exists($c, self::LEAF_MAP)) {
                return self::LEAF_MAP[$c];
            }
        }

        // Name-keyword fallback. A null hit (e.g. "monitor arm", "gaming") means
        // Office-but-not-furniture, which is a deliberate skip.
        $lower = mb_strtolower($name);
        foreach (self::NAME_MAP as $keyword => $path) {
            if (str_contains($lower, $keyword)) {
                return $path;
            }
        }

        // Office furniture we could not place precisely: fall to the broadest
        // sensible bucket rather than dropping it. Chairs -> seating, else desks.
        if (str_contains($lower, 'chair') || str_contains($lower, 'seat') || str_contains($lower, 'stool')) {
            return self::SEATING;
        }

        return null;
    }

    /**
     * Drop Herman Miller's trailing colour/option descriptors so the product name
     * is the model, not one configuration (e.g. "Aeron Chair, Size C, Graphite"
     * -> "Aeron Chair"). Only a comma-led tail is trimmed.
     */
    private function tidyName(string $name): string
    {
        $name = trim($name);
        $comma = mb_strpos($name, ',');

        return $comma === false ? $name : trim(mb_substr($name, 0, $comma));
    }

    private function shortDescription(string $name): string
    {
        return Str::limit(
            $name.' - Herman Miller office furniture supplied by OfficeLand across Nigeria with nationwide delivery. Request a quote for configurations, finishes and lead time.',
            155,
            '',
        );
    }

    private function description(string $name): string
    {
        return $name.' is part of the Herman Miller office furniture range that OfficeLand supplies to businesses, institutions and workspaces across Nigeria. '
            .'It is specified for professional office environments and is available in the model\'s configurations, finishes and sizes to order. '
            .'Request a quote for the available options, specifications, pricing and lead time; delivery is available nationwide.';
    }
}
