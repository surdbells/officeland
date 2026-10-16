<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Media\DerivativeGenerator;
use App\Media\MediaIngestor;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Imports the live catalogue from the current WooCommerce site.
 *
 * ── What this is for ─────────────────────────────────────────────────────
 * The storefront shipped on an invented demo catalogue (DemoCatalogueSeeder):
 * realistic volume, no real products, no photographs. This replaces it with
 * the real thing, read from the WooCommerce Store API the current site exposes
 * at /wp-json/wc/store/v1 - products, images, descriptions, brands.
 *
 * ── The decisions it encodes ─────────────────────────────────────────────
 * - No prices. WooCommerce carries them; D-002 does not. They are dropped.
 * - Products land in the curated 170-category tree, not WooCommerce's own
 *   taxonomy. MAP routes each product to a leaf by keyword, most specific
 *   first; the fallback is the department, so nothing lands nowhere.
 * - Everything imports is_indexable = false. The content floor (§14.7) is the
 *   gate to indexing, and a thin WooCommerce description does not clear it;
 *   enrichment flips individual products later. The storefront still shows
 *   them - with a real photo - it just does not offer them to Google yet.
 * - Idempotent and resumable: matched on slug, skips an image a product
 *   already has, so a run killed at product 3,000 resumes where it stopped.
 *
 * Usage:
 *   php artisan woo:import --limit=20            # a trial slice
 *   php artisan woo:import                       # the whole catalogue
 *   php artisan woo:import --no-images           # structure only, images later
 *   php artisan woo:import --from-page=12        # resume from a page
 */
final class ImportWooCommerce extends Command
{
    protected $signature = 'woo:import
        {--base=https://officelandng.com : The WooCommerce site origin}
        {--limit=0 : Stop after this many products (0 = all)}
        {--per-page=50 : Products per API page}
        {--from-page=1 : Start from this page (resume)}
        {--no-images : Skip image download and derivative generation}';

    protected $description = 'Import the real catalogue from the live WooCommerce site.';

    /**
     * WooCommerce keyword -> officeland leaf path, most specific first. The
     * first keyword found in a product's name + its WooCommerce category names
     * wins, so "laser printer" beats "printer" and "executive chair" beats
     * "chair". The fallback map below catches whatever none of these do.
     *
     * @var list<array{0: string, 1: string}>
     */
    private const MAP = [
        // Disambiguators - most specific, matched first so a "laptop stand" is
        // an accessory not a laptop, a "tablet stand" is not a table, and a
        // "height adjustable desk" is a desk not an ergonomic chair.
        ['laptop stand', '/it-products/computer-accessories/stands-mounts/'],
        ['tablet stand', '/it-products/computer-accessories/stands-mounts/'],
        ['phone stand', '/it-products/computer-accessories/stands-mounts/'],
        ['monitor stand', '/it-products/computer-accessories/stands-mounts/'],
        ['monitor arm', '/it-products/computer-accessories/stands-mounts/'],
        ['tablet holder', '/it-products/computer-accessories/stands-mounts/'],
        ['tablet', '/it-products/computer-accessories/stands-mounts/'],
        ['standing desk', '/office-furniture/desks-tables/height-adjustable-desks/'],
        ['height adjustable', '/office-furniture/desks-tables/height-adjustable-desks/'],
        ['height-adjustable', '/office-furniture/desks-tables/height-adjustable-desks/'],
        // IT - computers
        ['laptop', '/it-products/computers/laptops/'],
        ['notebook computer', '/it-products/computers/laptops/'],
        ['all-in-one pc', '/it-products/computers/desktops-all-in-ones/'],
        ['desktop', '/it-products/computers/desktops-all-in-ones/'],
        ['server', '/it-products/computers/workstations-servers/'],
        // IT - displays
        ['projector', '/it-products/monitors-displays/projectors-screens/'],
        ['monitor', '/it-products/monitors-displays/monitors/'],
        // IT - networking
        ['access point', '/it-products/networking/routers-access-points/'],
        ['router', '/it-products/networking/routers-access-points/'],
        ['network switch', '/it-products/networking/switches-adapters/'],
        ['network cable', '/it-products/networking/network-cables/'],
        ['networking', '/it-products/networking/routers-access-points/'],
        // IT - storage
        ['ssd', '/it-products/storage-memory/ssds-internal-drives/'],
        ['hard drive', '/it-products/storage-memory/external-hard-drives/'],
        ['flash drive', '/it-products/storage-memory/flash-drives/'],
        ['memory card', '/it-products/storage-memory/memory-cards/'],
        ['sd card', '/it-products/storage-memory/memory-cards/'],
        ['microsd', '/it-products/storage-memory/memory-cards/'],
        ['usb drive', '/it-products/storage-memory/flash-drives/'],
        // IT - accessories
        ['keyboard', '/it-products/computer-accessories/keyboards-mice/'],
        ['mouse', '/it-products/computer-accessories/keyboards-mice/'],
        ['headset', '/it-products/computer-accessories/headsets-audio/'],
        ['headphone', '/it-products/computer-accessories/headsets-audio/'],
        ['earphone', '/it-products/computer-accessories/headsets-audio/'],
        ['earbud', '/it-products/computer-accessories/headsets-audio/'],
        ['speaker', '/it-products/computer-accessories/headsets-audio/'],
        ['microphone', '/it-products/computer-accessories/headsets-audio/'],
        ['laptop bag', '/it-products/computer-accessories/laptop-bags-cases/'],
        ['laptop sleeve', '/it-products/computer-accessories/laptop-bags-cases/'],
        ['backpack', '/it-products/computer-accessories/laptop-bags-cases/'],
        ['power bank', '/it-products/computer-accessories/chargers-power-banks/'],
        ['charger', '/it-products/computer-accessories/chargers-power-banks/'],
        ['docking', '/it-products/computer-accessories/hubs-docking-stations/'],
        ['usb hub', '/it-products/computer-accessories/hubs-docking-stations/'],
        ['adapter', '/it-products/computer-accessories/cables-adapters/'],
        ['hdmi', '/it-products/computer-accessories/cables-adapters/'],
        ['cable', '/it-products/computer-accessories/cables-adapters/'],
        ['computer accessor', '/it-products/computer-accessories/keyboards-mice/'],
        // IT - software
        ['antivirus', '/it-products/software/antivirus-security/'],
        ['software', '/it-products/software/office-productivity/'],
        // Printers & consumables
        ['toner', '/printers-consumables/ink-toner/toner-cartridges/'],
        ['drum', '/printers-consumables/ink-toner/drums-maintenance-kits/'],
        ['ink cartridge', '/printers-consumables/ink-toner/ink-cartridges/'],
        ['ink bottle', '/printers-consumables/ink-toner/ink-cartridges/'],
        ['ink advantage', '/printers-consumables/ink-toner/ink-cartridges/'],
        ['scanner', '/printers-consumables/scanners/'],
        ['copier', '/printers-consumables/copiers/'],
        ['laserjet', '/printers-consumables/printers/laser-printers/'],
        ['laser printer', '/printers-consumables/printers/laser-printers/'],
        ['inkjet', '/printers-consumables/printers/inkjet-printers/'],
        ['deskjet', '/printers-consumables/printers/inkjet-printers/'],
        ['plotter', '/printers-consumables/printers/large-format-plotters/'],
        ['pagewide', '/printers-consumables/printers/inkjet-printers/'],
        ['printer', '/printers-consumables/printers/laser-printers/'],
        ['ink', '/printers-consumables/ink-toner/ink-cartridges/'],
        // Office furniture - seating
        ['executive chair', '/office-furniture/seating/executive-chairs/'],
        ['ergonomic', '/office-furniture/seating/ergonomic-task-chairs/'],
        ['task chair', '/office-furniture/seating/ergonomic-task-chairs/'],
        ['conference chair', '/office-furniture/seating/conference-chairs/'],
        ['meeting chair', '/office-furniture/seating/conference-chairs/'],
        ['visitor chair', '/office-furniture/seating/visitor-reception-chairs/'],
        ['reception chair', '/office-furniture/seating/visitor-reception-chairs/'],
        ['recliner', '/office-furniture/seating/recliner-lounge-chairs/'],
        ['lounge chair', '/office-furniture/seating/recliner-lounge-chairs/'],
        ['office chair', '/office-furniture/seating/executive-chairs/'],
        // Office furniture - desks
        ['executive desk', '/office-furniture/desks-tables/executive-desks/'],
        ['conference table', '/office-furniture/desks-tables/conference-tables/'],
        ['meeting table', '/office-furniture/desks-tables/conference-tables/'],
        ['reception desk', '/office-furniture/desks-tables/reception-desks/'],
        ['standing desk', '/office-furniture/desks-tables/height-adjustable-desks/'],
        ['height adjustable', '/office-furniture/desks-tables/height-adjustable-desks/'],
        ['height-adjustable', '/office-furniture/desks-tables/height-adjustable-desks/'],
        ['workstation', '/office-furniture/desks-tables/workstations/'],
        ['cubicle', '/office-furniture/desks-tables/workstations/'],
        ['office table', '/office-furniture/desks-tables/executive-desks/'],
        ['office desk', '/office-furniture/desks-tables/executive-desks/'],
        // Office furniture - storage & partitions
        ['bookcase', '/office-furniture/storage-furniture/bookcases-shelving/'],
        ['shelv', '/office-furniture/storage-furniture/bookcases-shelving/'],
        ['cupboard', '/office-furniture/storage-furniture/cupboards-lockers/'],
        ['locker', '/office-furniture/storage-furniture/cupboards-lockers/'],
        ['filing cabinet', '/office-furniture/storage-furniture/cupboards-lockers/'],
        ['partition', '/office-furniture/partitions-screens/'],
        ['office furniture', '/office-furniture/seating/executive-chairs/'],
        // Filing & storage
        ['ring binder', '/filing-storage/files-folders/ring-binders/'],
        ['lever arch', '/filing-storage/files-folders/lever-arch-files/'],
        ['suspension file', '/filing-storage/files-folders/suspension-files/'],
        ['manila', '/filing-storage/files-folders/manila-folders/'],
        ['document tray', '/filing-storage/desk-filing/document-trays/'],
        ['magazine', '/filing-storage/desk-filing/magazine-holders/'],
        ['folder', '/filing-storage/files-folders/manila-folders/'],
        ['box file', '/filing-storage/files-folders/document-files-boxes/'],
        // Office supplies - machines & desk
        ['shredder', '/office-supplies/office-machines/shredders/'],
        ['laminator', '/office-supplies/office-machines/laminators/'],
        ['binding machine', '/office-supplies/office-machines/binding-machines/'],
        ['calculator', '/office-supplies/office-machines/calculators/'],
        ['stapler', '/office-supplies/desk-accessories/staplers-punches/'],
        ['punch', '/office-supplies/desk-accessories/staplers-punches/'],
        ['whiteboard', '/office-supplies/boards-presentation/whiteboards/'],
        ['notice board', '/office-supplies/boards-presentation/bulletin-boards/'],
        // Office supplies - writing
        ['marker', '/office-supplies/writing/markers-highlighters/'],
        ['highlighter', '/office-supplies/writing/markers-highlighters/'],
        ['pencil', '/office-supplies/writing/pencils/'],
        ['ballpoint', '/office-supplies/writing/pens/'],
        ['pen', '/office-supplies/writing/pens/'],
        // Office supplies - adhesives & labels
        ['tape', '/office-supplies/adhesives-tapes/tapes/'],
        ['glue', '/office-supplies/adhesives-tapes/glue/'],
        ['label', '/office-supplies/labelling-identification/labels/'],
        ['stamp', '/office-supplies/labelling-identification/stamps/'],
        // Paper
        ['photo paper', '/paper-notebooks/specialty-paper/photo-paper/'],
        ['printing paper', '/paper-notebooks/printing-paper/'],
        ['a4 paper', '/paper-notebooks/printing-paper/'],
        ['notebook', '/paper-notebooks/notebooks-pads/'],
        ['envelope', '/paper-notebooks/envelopes-mailing/'],
        ['sticky note', '/paper-notebooks/sticky-notes/'],
        // Smart tech
        ['cctv', '/smart-tech/security-surveillance/cctv-cameras/'],
        ['spotlight camera', '/smart-tech/security-surveillance/cctv-cameras/'],
        ['security camera', '/smart-tech/security-surveillance/cctv-cameras/'],
        ['doorbell', '/smart-tech/security-surveillance/video-doorbells/'],
        ['webcam', '/smart-tech/conferencing-av/webcams/'],
        ['digital camera', '/smart-tech/cameras-imaging/digital-cameras/'],
        ['ups', '/smart-tech/power-backup/ups-systems/'],
        ['inverter', '/smart-tech/power-backup/inverters/'],
        ['stabiliser', '/smart-tech/power-backup/voltage-stabilisers/'],
        ['stabilizer', '/smart-tech/power-backup/voltage-stabilisers/'],
        ['solar', '/smart-tech/power-backup/solar-panels/'],
        // Long-tail coverage, lowest priority: the leftovers that fell to the
        // catch-all on the first full run - stationery, filing, storage boxes,
        // external drives, machine consumables, cases, labelling. Generic and
        // last, so they only catch what no specific rule above claimed.
        ['file cabinet', '/office-furniture/storage-furniture/cupboards-lockers/'],
        ['transfer file', '/filing-storage/files-folders/document-files-boxes/'],
        ['portfolio file', '/filing-storage/files-folders/manila-folders/'],
        ['box file', '/filing-storage/files-folders/document-files-boxes/'],
        ['reinforced pocket', '/filing-storage/files-folders/display-books-clear-files/'],
        ['sheet protector', '/filing-storage/files-folders/display-books-clear-files/'],
        ['pocket', '/filing-storage/files-folders/display-books-clear-files/'],
        ['storage box', '/filing-storage/archive-boxes/'],
        ['bankers box', '/filing-storage/archive-boxes/'],
        ['archive box', '/filing-storage/archive-boxes/'],
        ['file', '/filing-storage/files-folders/manila-folders/'],
        ['hdd', '/it-products/storage-memory/external-hard-drives/'],
        ['backup plus', '/it-products/storage-memory/external-hard-drives/'],
        ['seagate', '/it-products/storage-memory/external-hard-drives/'],
        ['external drive', '/it-products/storage-memory/external-hard-drives/'],
        ['compactflash', '/it-products/storage-memory/memory-cards/'],
        ['dual drive', '/it-products/storage-memory/flash-drives/'],
        ['pen drive', '/it-products/storage-memory/flash-drives/'],
        ['laminating', '/office-supplies/office-machines/laminators/'],
        ['laminate', '/office-supplies/office-machines/laminators/'],
        ['binding ring', '/office-supplies/office-machines/binding-machines/'],
        ['binding cover', '/office-supplies/office-machines/binding-machines/'],
        ['binding comb', '/office-supplies/office-machines/binding-machines/'],
        ['guillotine', '/office-supplies/office-machines/paper-trimmers/'],
        ['ruler', '/school-supplies/maths-geometry/rulers-stencils/'],
        ['eraser', '/office-supplies/writing/correction-erasers/'],
        ['correction', '/office-supplies/writing/correction-erasers/'],
        ['fineliner', '/office-supplies/writing/pens/'],
        ['legal pad', '/paper-notebooks/notebooks-pads/'],
        ['writing pad', '/paper-notebooks/notebooks-pads/'],
        ['notepad', '/paper-notebooks/notebooks-pads/'],
        ['index card', '/paper-notebooks/notebooks-pads/'],
        ['stock register', '/paper-notebooks/notebooks-pads/'],
        ['bristol board', '/paper-notebooks/specialty-paper/colour-paper/'],
        ['cardboard', '/paper-notebooks/specialty-paper/colour-paper/'],
        ['cutting mat', '/office-supplies/desk-accessories/cutting-tools/'],
        ['id card', '/office-supplies/labelling-identification/badges-lanyards/'],
        ['lanyard', '/office-supplies/labelling-identification/badges-lanyards/'],
        ['badge', '/office-supplies/labelling-identification/badges-lanyards/'],
        ['labeller', '/office-supplies/labelling-identification/labels/'],
        ['privacy filter', '/it-products/computer-accessories/stands-mounts/'],
        ['privacy screen', '/it-products/computer-accessories/stands-mounts/'],
        ['screen cleaner', '/it-products/computer-accessories/cables-adapters/'],
        ['sleeve', '/it-products/computer-accessories/laptop-bags-cases/'],
        ['pouch', '/it-products/computer-accessories/laptop-bags-cases/'],
        ['carrying case', '/it-products/computer-accessories/laptop-bags-cases/'],
        ['case', '/it-products/computer-accessories/laptop-bags-cases/'],
        ['literature organizer', '/office-supplies/desk-accessories/organisers-holders/'],
        ['literature sorter', '/office-supplies/desk-accessories/organisers-holders/'],
        ['organizer', '/office-supplies/desk-accessories/organisers-holders/'],
        ['organiser', '/office-supplies/desk-accessories/organisers-holders/'],
        ['book end', '/office-supplies/desk-accessories/organisers-holders/'],
        ['dvd', '/it-products/storage-memory/flash-drives/'],
        ['cd-r', '/it-products/storage-memory/flash-drives/'],
    ];

    /**
     * Department-level fallback when no keyword matched, keyed on the words
     * WooCommerce's own top categories use. The value is a leaf inside that
     * department - a product filed here is in the right department even if its
     * exact shelf is a guess.
     *
     * @var array<string, string>
     */
    private const FALLBACK = [
        'it product' => '/it-products/computer-accessories/keyboards-mice/',
        'computer' => '/it-products/computer-accessories/keyboards-mice/',
        'ink' => '/printers-consumables/ink-toner/ink-cartridges/',
        'toner' => '/printers-consumables/ink-toner/toner-cartridges/',
        'print' => '/printers-consumables/printers/laser-printers/',
        'furniture' => '/office-furniture/seating/executive-chairs/',
        'chair' => '/office-furniture/seating/executive-chairs/',
        'table' => '/office-furniture/desks-tables/executive-desks/',
        'paper' => '/paper-notebooks/printing-paper/',
        'stationery' => '/office-supplies/desk-accessories/organisers-holders/',
        'office supplies' => '/office-supplies/desk-accessories/organisers-holders/',
    ];

    /** The leaf a product lands in when nothing else matches at all. */
    private const CATCH_ALL = '/office-supplies/desk-accessories/organisers-holders/';

    /** @var array<string, Category> path => category */
    private array $categoryCache = [];

    /** @var array<string, Brand> slug => brand */
    private array $brandCache = [];

    public function handle(MediaIngestor $ingestor, DerivativeGenerator $generator): int
    {
        $base = rtrim((string) $this->option('base'), '/');
        $limit = (int) $this->option('limit');
        $perPage = max(1, min(100, (int) $this->option('per-page')));
        $page = max(1, (int) $this->option('from-page'));
        $withImages = ! (bool) $this->option('no-images');

        $this->info("Importing from {$base} (per_page={$perPage}, from page {$page}".($limit ? ", limit {$limit}" : '').")");

        $created = 0;
        $updated = 0;
        $imaged = 0;
        $skipped = 0;
        $processed = 0;

        while (true) {
            /*
             * A page fetch that fails every retry must not kill a run that has
             * already imported thousands of products. `retry()` throws on final
             * failure, so the whole fetch is wrapped: a page is attempted a few
             * times with backoff, and only a persistently unreachable page ends
             * the run - gracefully, with a resume hint, so the work stands.
             */
            $items = null;
            for ($attempt = 1; $attempt <= 4 && $items === null; $attempt++) {
                try {
                    $response = Http::timeout(45)->acceptJson()->get(
                        "{$base}/wp-json/wc/store/v1/products",
                        ['per_page' => $perPage, 'page' => $page, 'catalog_visibility' => 'visible'],
                    );

                    if ($response->successful()) {
                        $items = $response->json();
                    } else {
                        $this->warn("  page {$page}: HTTP {$response->status()} (attempt {$attempt})");
                        sleep(3 * $attempt);
                    }
                } catch (\Throwable $e) {
                    $this->warn("  page {$page}: {$e->getMessage()} (attempt {$attempt})");
                    sleep(3 * $attempt);
                }
            }

            if ($items === null) {
                $this->error("Page {$page} unreachable after 4 attempts. Resume with --from-page={$page}.");
                break;
            }

            if ($items === []) {
                break;
            }

            // Be a civil client: a short pause between pages keeps a 100-page
            // crawl from looking like an attack to the source site's firewall,
            // which is the likeliest cause of a page suddenly refusing.
            usleep(400000);

            foreach ($items as $item) {
                // Variations are imported through their parent, never on their
                // own: a "Blue / Large" row is not a product a buyer lands on.
                if (($item['type'] ?? 'simple') === 'variation') {
                    continue;
                }

                // Some WooCommerce rows cram the whole description into the
                // name field, well past the 255-char column. Cap it, and the
                // slug derived from it, so a verbose supplier cannot abort the
                // import. 200 characters is a long product name and short of
                // the limit with room to spare.
                $name = Str::limit($this->clean((string) ($item['name'] ?? '')), 200, '');
                if ($name === '') {
                    $skipped++;
                    continue;
                }

                // One malformed product is skipped, never fatal: a 5,000-row
                // import cannot be held hostage by a single bad row.
                try {
                    $result = $this->importOne($item, $name, $ingestor, $generator, $withImages);
                } catch (\Throwable $e) {
                    $skipped++;
                    $this->warn("  skipped \"{$name}\": {$e->getMessage()}");
                    continue;
                }
                $processed++;
                match ($result) {
                    'created' => $created++,
                    'updated' => $updated++,
                    default => null,
                };
                if ($withImages && in_array($result, ['created', 'updated'], true)) {
                    // imaged is counted inside importOne via return marker
                }

                if ($limit && $processed >= $limit) {
                    break 2;
                }

                if ($processed % 50 === 0) {
                    $this->line("  ...{$processed} processed (created {$created}, updated {$updated}, page {$page})");
                }
            }

            $page++;
        }

        $this->refreshCounts();

        $this->newLine();
        $this->table(
            ['Processed', 'Created', 'Updated', 'Skipped'],
            [[$processed, $created, $updated, $skipped]],
        );
        $this->info('Done. Imported products are is_indexable = false until they clear the content floor.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function importOne(
        array $item,
        string $name,
        MediaIngestor $ingestor,
        DerivativeGenerator $generator,
        bool $withImages,
    ): string {
        $slug = rtrim(Str::limit(Str::slug((string) ($item['slug'] ?? $name)) ?: Str::slug($name), 190, ''), '-');
        $leaf = $this->resolveCategory($item, $name);
        $brand = $this->resolveBrand($item);

        $sku = $this->cleanSku((string) ($item['sku'] ?? ''));
        $short = $this->clean(strip_tags((string) ($item['short_description'] ?? '')));
        $descriptionHtml = $this->sanitiseHtml((string) ($item['description'] ?? ''));
        $inStock = (bool) ($item['is_in_stock'] ?? true);

        $existing = Product::query()->where('slug', $slug)->first();

        $attributes = [
            'name' => $name,
            'sku' => $sku !== '' ? $sku : null,
            'brand_id' => $brand?->id,
            'brand_name_cached' => $brand?->name,
            'primary_category_id' => $leaf->id,
            'status' => 'active',
            'availability' => $inStock ? 'in_stock' : 'made_to_order',
            'pricing_mode' => 'quote_only',
            'is_indexable' => false,
            'min_order_qty' => 1,
            'qty_step' => 1,
            'lead_time_days' => $inStock ? 3 : 14,
            'short_description' => $short !== '' ? Str::limit($short, 480, '') : null,
            'description_html' => $descriptionHtml !== '' ? $descriptionHtml : null,
            'source' => 'csv',
            'published_at' => now(),
        ];

        if ($existing) {
            $existing->fill($attributes)->save();
            // sync, not syncWithoutDetaching: a re-import must be able to CORRECT
            // a mapping, which means the old (wrong) category has to go, not
            // accumulate beside the new one.
            $existing->categories()->sync([$leaf->id => ['sort_order' => 0]]);
            $product = $existing;
            $outcome = 'updated';
        } else {
            $product = Product::query()->create(['uuid' => (string) Str::uuid(), 'slug' => $slug] + $attributes);
            $product->categories()->attach($leaf->id, ['sort_order' => 0]);
            $outcome = 'created';
        }

        if ($withImages && ! $product->media()->exists()) {
            $this->attachImage($item, $product, $name, $ingestor, $generator);
        }

        return $outcome;
    }

    /**
     * Downloads the primary image, ingests it through the media pipeline and
     * attaches it as the product's primary. A failure here is logged and
     * swallowed: a product without its photo is worth more than a halted
     * import, and the next run retries it (the product has no media yet).
     *
     * @param  array<string, mixed>  $item
     */
    private function attachImage(
        array $item,
        Product $product,
        string $name,
        MediaIngestor $ingestor,
        DerivativeGenerator $generator,
    ): void {
        $images = $item['images'] ?? [];
        $src = is_array($images) && isset($images[0]['src']) ? (string) $images[0]['src'] : '';
        if ($src === '') {
            return;
        }

        try {
            $response = Http::timeout(45)->retry(2, 1500)->get($src);
            if (! $response->successful()) {
                return;
            }

            $ext = pathinfo(parse_url($src, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION) ?: 'jpg';
            $tmp = tempnam(sys_get_temp_dir(), 'woo').'.'.strtolower($ext);
            file_put_contents($tmp, $response->body());

            $media = $ingestor->ingest($tmp, [
                'alt_text' => $name,
                'source' => 'manual',
            ]);
            $generator->generate($media);

            $product->media()->syncWithoutDetaching([
                $media->id => ['role' => 'primary', 'sort_order' => 0],
            ]);

            @unlink($tmp);
        } catch (\Throwable $e) {
            $this->warn("  image failed for {$name}: {$e->getMessage()}");
        }
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function resolveCategory(array $item, string $name): Category
    {
        // Two passes, categories before name. The merchant's own category is
        // the trustworthy signal; a product NAME can lie - an earbuds set named
        // "Laptop Yoga 500" would land in Laptops if the name were weighed
        // first. So MAP (then FALLBACK) is tried against the WooCommerce
        // category names, and only if nothing there matches is the name tried.
        $categories = Str::lower($this->categoryNames($item));
        $named = Str::lower($name);

        foreach ([$categories, $named] as $haystack) {
            if ($haystack === '') {
                continue;
            }
            foreach (self::MAP as [$needle, $path]) {
                if ($this->matches($haystack, $needle)) {
                    return $this->category($path);
                }
            }
        }

        foreach ([$categories, $named] as $haystack) {
            foreach (self::FALLBACK as $needle => $path) {
                if ($haystack !== '' && $this->matches($haystack, $needle)) {
                    return $this->category($path);
                }
            }
        }

        return $this->category(self::CATCH_ALL);
    }

    /**
     * Whole-word match, so "table" does not match "Tablet", "pen" does not
     * match "expensive", and "ink" does not match "thinking". Substring
     * matching misfiled a tablet stand as a desk on the first run; a word
     * boundary is the fix.
     */
    private function matches(string $haystack, string $needle): bool
    {
        return preg_match('~(?<![a-z])'.preg_quote($needle, '~').'(?![a-z])~', $haystack) === 1;
    }

    /** @param array<string, mixed> $item */
    private function categoryNames(array $item): string
    {
        $names = [];
        foreach (($item['categories'] ?? []) as $c) {
            if (isset($c['name'])) {
                $names[] = html_entity_decode((string) $c['name']);
            }
        }

        return implode(' ', $names);
    }

    private function category(string $path): Category
    {
        if (isset($this->categoryCache[$path])) {
            return $this->categoryCache[$path];
        }

        $category = Category::query()->where('path', $path)->first();

        if ($category === null) {
            // A mapped leaf that is not in the taxonomy is a bug in MAP, not a
            // reason to lose the product: climb to the nearest ancestor that
            // does exist, down to the catch-all.
            $parts = array_values(array_filter(explode('/', $path)));
            while (count($parts) > 0 && $category === null) {
                array_pop($parts);
                $tryPath = $parts === [] ? self::CATCH_ALL : '/'.implode('/', $parts).'/';
                $category = Category::query()->where('path', $tryPath)->first();
            }
            $category ??= Category::query()->where('path', self::CATCH_ALL)->firstOrFail();
        }

        return $this->categoryCache[$path] = $category;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function resolveBrand(array $item): ?Brand
    {
        $raw = '';
        foreach (($item['brands'] ?? []) as $b) {
            if (isset($b['name'])) {
                $raw = (string) $b['name'];
                break;
            }
        }

        $raw = Str::limit(trim(html_entity_decode($raw)), 120, '');
        if ($raw === '' || Str::lower($raw) === 'all products') {
            return null;
        }

        $slug = Str::slug($raw);
        if (isset($this->brandCache[$slug])) {
            return $this->brandCache[$slug];
        }

        $brand = Brand::query()->firstOrCreate(
            ['slug' => $slug],
            ['uuid' => (string) Str::uuid(), 'name' => $raw],
        );

        return $this->brandCache[$slug] = $brand;
    }

    private function clean(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', html_entity_decode($value)) ?? '');
    }

    private function cleanSku(string $sku): string
    {
        return trim(str_replace('#', '', $sku));
    }

    /**
     * Keeps the structural tags a description actually uses and drops the rest,
     * so authored copy survives but no script or style rides in. The storefront
     * renders this with [innerHTML] through Angular's sanitiser as a second
     * line of defence.
     */
    private function sanitiseHtml(string $html): string
    {
        $allowed = '<p><br><ul><ol><li><strong><b><em><i><h2><h3><h4><table><thead><tbody><tr><th><td>';
        $clean = strip_tags($html, $allowed);

        return trim(preg_replace('/\s+/', ' ', $clean) ?? '');
    }

    private function refreshCounts(): void
    {
        \Illuminate\Support\Facades\DB::statement(<<<'SQL'
            UPDATE categories AS c
            SET product_count_cached = COALESCE(counted.total, 0)
            FROM (
                SELECT ancestors.id, COUNT(DISTINCT pc.product_id) AS total
                FROM categories AS ancestors
                LEFT JOIN categories AS descendants
                    ON descendants.path LIKE ancestors.path || '%'
                LEFT JOIN product_category AS pc ON pc.category_id = descendants.id
                LEFT JOIN products AS p
                    ON p.id = pc.product_id AND p.status = 'active' AND p.deleted_at IS NULL
                GROUP BY ancestors.id
            ) AS counted
            WHERE c.id = counted.id
        SQL);
    }
}
