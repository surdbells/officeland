<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

/**
 * Derive brand, model code and (optionally) MPN for imported products that have
 * none, so Icecat enrichment has something to match on.
 *
 *   php artisan catalogue:derive-identifiers                    # dry run
 *   php artisan catalogue:derive-identifiers --apply            # brand + model code
 *   php artisan catalogue:derive-identifiers --apply --mpn-from=name
 *
 * The WooCommerce import left every product without mpn/brand/ean, but the
 * identifiers sit in the product names ("Fellowes Powershred LX200") and SKUs.
 * This reads them out:
 *
 *   - BRAND: the first known manufacturer (from CANONICAL_BRANDS) that appears
 *     as a whole word near the start of the product name. The brands table on a
 *     fresh WooCommerce import is empty, so matched brands are CREATED (on
 *     --apply); the earliest match wins so a maker leading the title beats a
 *     brand named later in a compatibility list.
 *   - MODEL CODE: a model-code-shaped token from the name (letters+digits, e.g.
 *     LX200, CF410A). Display/search only, never a match key, so it also always
 *     runs when the field is empty.
 *   - MPN: only with --mpn-from, because MPN is the Icecat match key and a wrong
 *     value matches the WRONG Icecat product. 'sku' copies the SKU; 'name' uses
 *     the extracted model code. Review a dry run, then validate the matches with
 *     `catalogue:icecat-enrich --dump` before enriching for real.
 *
 * Open Icecat only covers sponsoring brands (D-022), so only the mainstream
 * brands here (HP, Canon, Epson, Dell, Lenovo, Brother, Logitech, Kingston…)
 * will ever enrich - the rest still gain a brand facet, which is worth having.
 */
final class DeriveIdentifiers extends Command
{
    protected $signature = 'catalogue:derive-identifiers
        {--apply : Write changes. Without this the command only reports.}
        {--mpn-from=none : Where to derive MPN from: none, sku, or name.}
        {--brand= : Restrict to products whose name matches this brand (for spot checks).}
        {--limit=0 : Maximum products to process (0 = no limit).}
        {--samples=15 : How many example derivations to print.}';

    protected $description = 'Derive brand, model code and optional MPN from product names and SKUs';

    /**
     * Known manufacturers to recognise in product names, canonical spelling.
     *
     * Led by the brands the catalogue is being built around (the IT lines and
     * shredders imported/enriched from Icecat), followed by the other makers
     * already present in the migrated WooCommerce data. It is a curated list on
     * purpose: matching the first word of every title would turn "Newest", "Buy"
     * and "MONITOR" into brands. Order does not matter - matching is by position
     * in the name - so add new brands anywhere.
     *
     * @var list<string>
     */
    private const CANONICAL_BRANDS = [
        // Target brands for the Icecat build.
        'Apple', 'TP-Link', 'Ubiquiti', 'UniFi', 'Dell', 'Lenovo', 'ASUS', 'Logitech',
        'Canon', 'Havit', 'Belkin', 'WiWU', 'Fellowes', 'Rexel', 'Leitz', 'GoECOlife',
        // Other manufacturers seen in the migrated catalogue.
        'HP', 'Epson', 'Brother', 'Xerox', 'Ricoh', 'Kyocera', 'Samsung', 'LG', 'Sony',
        'MSI', 'Acer', 'Toshiba', 'Fujitsu', 'Microsoft', 'Intel', 'AMD', 'Nvidia',
        'Gigabyte', 'Kingston', 'SanDisk', 'Western Digital', 'Seagate', 'Crucial',
        'Transcend', 'APC', 'CyberPower', 'Anker', 'Ugreen', 'Baseus', 'Oraimo', 'Remax',
        'Hoco', 'Porodo', 'Promate', 'Hikvision', 'Dahua', 'Netgear', 'D-Link', 'Tenda',
        'MikroTik', 'Zebra', 'Dymo', 'Durable', 'Esselte', 'Elba', 'Bantex', 'Avery',
        'Faber-Castell', 'Staedtler', 'Stabilo', 'Pentel', 'Pilot', 'BIC', 'Maped',
        'Deli', 'Grandluxe', 'Pelikan', 'Parker', 'Casio', 'Nikon', 'JBL', 'Razer',
        'Redragon', 'Corsair', 'HyperX', 'A4Tech', 'Rapoo', 'Genius', 'Philips',
        'Panasonic',
    ];

    private int $brandsSet = 0;
    private int $modelCodesSet = 0;
    private int $mpnsSet = 0;
    private int $skippedUnique = 0;

    /** @var array<string, Brand> lower(name) => brand, created/found this run */
    private array $brandCache = [];

    /** @var list<array{slug: string, brand: ?string, model_code: ?string, mpn: ?string}> */
    private array $samples = [];

    public function handle(): int
    {
        $mpnFrom = (string) $this->option('mpn-from');

        if (! in_array($mpnFrom, ['none', 'sku', 'name'], true)) {
            $this->error("--mpn-from must be one of: none, sku, name (got '{$mpnFrom}').");

            return self::INVALID;
        }

        $apply = (bool) $this->option('apply');
        $sampleLimit = (int) $this->option('samples');

        $processed = 0;

        $this->candidates()->chunkById(500, function (Collection $chunk) use (
            $mpnFrom, $apply, $sampleLimit, &$processed
        ): void {
            foreach ($chunk as $product) {
                $processed++;
                $this->derive($product, $mpnFrom, $apply, $sampleLimit);
            }
        });

        $this->report($processed, $apply, $mpnFrom);

        return self::SUCCESS;
    }

    /** @return Builder<Product> */
    private function candidates(): Builder
    {
        $brand = (string) $this->option('brand');

        return Product::query()
            ->when($brand !== '', fn (Builder $q) => $q->whereRaw('lower(name) like ?', ['%'.mb_strtolower($brand).'%']))
            ->orderBy('id')
            ->when((int) $this->option('limit') > 0, fn (Builder $q) => $q->limit((int) $this->option('limit')));
    }

    private function derive(Product $product, string $mpnFrom, bool $apply, int $sampleLimit): void
    {
        // Brand is computed as a NAME here and only created/assigned on --apply,
        // so a dry run writes nothing (not even a brand row).
        $setBrand = $product->brand_id === null
            ? $this->matchBrandName((string) $product->name)
            : null;

        $modelCode = $this->extractModelCode((string) $product->name);
        $setModel = ($product->model_code ?? '') === '' && $modelCode !== null ? $modelCode : null;

        $setMpn = null;
        if ($mpnFrom !== 'none' && ($product->mpn ?? '') === '') {
            $setMpn = $mpnFrom === 'sku' ? $this->cleanOrNull((string) $product->sku) : $modelCode;
        }

        if ($setBrand === null && $setModel === null && $setMpn === null) {
            return;
        }

        if ($setBrand !== null) {
            $this->brandsSet++;
        }
        if ($setModel !== null) {
            $this->modelCodesSet++;
        }
        if ($setMpn !== null) {
            $this->mpnsSet++;
        }

        if (count($this->samples) < $sampleLimit) {
            $this->samples[] = [
                'slug' => (string) $product->slug,
                'brand' => $setBrand,
                'model_code' => $setModel,
                'mpn' => $setMpn,
            ];
        }

        if (! $apply) {
            return;
        }

        if ($setBrand !== null) {
            $brand = $this->createBrand($setBrand);
            $product->brand_id = $brand->id;
            $product->brand_name_cached = $brand->name;
        }
        if ($setModel !== null) {
            $product->model_code = $setModel;
        }
        if ($setMpn !== null) {
            $product->mpn = $setMpn;
        }

        try {
            $product->save();
        } catch (Throwable $e) {
            // The (brand_id, mpn) unique index can collide when two products in
            // the same brand carry the same code. Skip rather than abort.
            $this->skippedUnique++;

            if ($setBrand !== null) {
                $this->brandsSet--;
            }
            if ($setModel !== null) {
                $this->modelCodesSet--;
            }
            if ($setMpn !== null) {
                $this->mpnsSet--;
            }
        }
    }

    /**
     * The canonical name of the first known manufacturer that appears as a whole
     * word near the start of the product name, or null.
     *
     * Matched against CANONICAL_BRANDS rather than the first word of the title,
     * so "Newest", "Buy" and "MONITOR" are not turned into brands, and the
     * EARLIEST match wins so a maker leading the title beats a brand named later
     * in a compatibility list ("… for HP Canon Brother"). A match beyond the
     * first ~40 characters is ignored for the same reason.
     */
    private function matchBrandName(string $name): ?string
    {
        $best = null;
        $bestPos = PHP_INT_MAX;

        foreach (self::CANONICAL_BRANDS as $brand) {
            // Whole word, case-insensitive. The boundaries stop "HP" matching
            // inside "SHARP" or after a digit in "731HP", and "LG" inside "LGBT".
            $pattern = '/(?<![\p{L}\p{N}])'.preg_quote($brand, '/').'(?![\p{L}\p{N}])/iu';

            if (preg_match($pattern, $name, $m, PREG_OFFSET_CAPTURE) !== 1) {
                continue;
            }

            $pos = (int) $m[0][1];

            if ($pos > 40) {
                continue;
            }

            // Earliest wins; on a tie the longer (more specific) brand does.
            if ($pos < $bestPos || ($pos === $bestPos && mb_strlen($brand) > mb_strlen((string) $best))) {
                $best = $brand;
                $bestPos = $pos;
            }
        }

        return $best;
    }

    /** Find or create the brand row for a canonical name (cached per run). */
    private function createBrand(string $name): Brand
    {
        $key = Str::lower($name);

        return $this->brandCache[$key] ??= Brand::query()->firstOrCreate(
            ['slug' => Str::slug($name)],
            ['uuid' => (string) Str::uuid(), 'name' => $name],
        );
    }

    /**
     * A model-code-shaped token from the name. "Powershred LX200" → LX200,
     * "… SDCZ73-O32G-G46" → SDCZ73-O32G-G46; but NOT "10000mAh" (a capacity),
     * "3-Port" or "12-in-1" (a spec phrase that happens to carry a number).
     *
     * The test that separates a code from those is: at least one hyphen-free
     * SEGMENT mixes letters and digits within itself (LX200, CF410A, SDCZ73).
     * "3-Port" splits into "3" and "Port" - neither mixes - so it is rejected;
     * a capacity like "10000mAh" mixes but is caught by the unit blocklist. The
     * longest qualifying token wins, a real code being more specific than a
     * stray "A4". Precision over recall on purpose: a missed code (null) is
     * harmless, a wrong one shows on every card and, as an MPN, mis-matches
     * Icecat.
     */
    private function extractModelCode(string $name): ?string
    {
        if (preg_match_all('/\b[A-Za-z0-9]+(?:-[A-Za-z0-9]+)*\b/', $name, $matches) === false) {
            return null;
        }

        $units = 'mah|wh|mwh|gb|tb|mb|kb|hz|khz|mhz|ghz|ppm|dpi|rpm|nm|mm|cm|ml|kg|inch|in|[kpwvag]';
        $best = null;

        foreach ($matches[0] as $token) {
            if (mb_strlen($token) < 4) {
                continue;
            }

            $qualifies = false;

            foreach (explode('-', $token) as $segment) {
                $hasLetter = preg_match('/[A-Za-z]/', $segment) === 1;
                $hasDigit = preg_match('/\d/', $segment) === 1;
                $isCapacity = preg_match('/^\d+(?:'.$units.')$/i', $segment) === 1;

                if ($hasLetter && $hasDigit && ! $isCapacity) {
                    $qualifies = true;
                    break;
                }
            }

            if ($qualifies && ($best === null || mb_strlen($token) > mb_strlen($best))) {
                $best = $token;
            }
        }

        return $best;
    }

    private function cleanOrNull(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function report(int $processed, bool $apply, string $mpnFrom): void
    {
        $this->newLine();

        if ($this->samples !== []) {
            $this->table(
                ['Product', 'Brand', 'Model code', 'MPN'],
                array_map(fn (array $s): array => [
                    \Illuminate\Support\Str::limit($s['slug'], 40),
                    $s['brand'] ?? '-',
                    $s['model_code'] ?? '-',
                    $s['mpn'] ?? '-',
                ], $this->samples),
            );
        }

        $this->table(['Metric', 'Count'], [
            ['Processed', $processed],
            [$apply ? 'Brands set' : 'Brands (would)', $this->brandsSet],
            [$apply ? 'Model codes set' : 'Model codes (would)', $this->modelCodesSet],
            [$apply ? 'MPNs set' : 'MPNs (would)', $mpnFrom === 'none' ? 'n/a (--mpn-from=none)' : $this->mpnsSet],
            ['Skipped (unique clash)', $this->skippedUnique],
        ]);

        if (! $apply) {
            $this->newLine();
            $this->info('Dry run. Re-run with --apply to write. Add --mpn-from=sku|name to populate MPN for Icecat matching.');
        } elseif ($mpnFrom !== 'none') {
            $this->newLine();
            $this->info('MPNs set. Validate matches with: php artisan catalogue:icecat-enrich --dump --limit=5');
        }
    }
}
