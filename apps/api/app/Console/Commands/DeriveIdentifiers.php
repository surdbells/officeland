<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
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
 *   - BRAND: the first known brand whose name appears as a whole word in the
 *     product name. Matched against the brands already in the table, longest
 *     name first so "Double A" wins over a stray "A". Safe, so it always runs.
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

    private int $brandsSet = 0;
    private int $modelCodesSet = 0;
    private int $mpnsSet = 0;
    private int $skippedUnique = 0;

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

        // Known brands, longest name first so "Double A" is tried before a bare
        // "A" would be, and the house brand is excluded (every product is ours).
        $brands = Brand::query()
            ->whereRaw('lower(name) <> ?', ['officeland'])
            ->get(['id', 'name'])
            ->sortByDesc(fn (Brand $b): int => mb_strlen((string) $b->name))
            ->values();

        $processed = 0;

        $this->candidates()->chunkById(500, function (Collection $chunk) use (
            $brands, $mpnFrom, $apply, $sampleLimit, &$processed
        ): void {
            foreach ($chunk as $product) {
                $processed++;
                $this->derive($product, $brands, $mpnFrom, $apply, $sampleLimit);
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

    /** @param Collection<int, Brand> $brands */
    private function derive(Product $product, Collection $brands, string $mpnFrom, bool $apply, int $sampleLimit): void
    {
        $changed = false;
        $setBrand = null;
        $setModel = null;
        $setMpn = null;

        if ($product->brand_id === null && ($brand = $this->matchBrand((string) $product->name, $brands)) !== null) {
            $product->brand_id = $brand->id;
            $product->brand_name_cached = $brand->name;
            $setBrand = $brand->name;
            $this->brandsSet++;
            $changed = true;
        }

        $modelCode = $this->extractModelCode((string) $product->name);

        if (($product->model_code ?? '') === '' && $modelCode !== null) {
            $product->model_code = $modelCode;
            $setModel = $modelCode;
            $this->modelCodesSet++;
            $changed = true;
        }

        if ($mpnFrom !== 'none' && ($product->mpn ?? '') === '') {
            $mpn = $mpnFrom === 'sku'
                ? $this->cleanOrNull((string) $product->sku)
                : $modelCode;

            if ($mpn !== null) {
                $product->mpn = $mpn;
                $setMpn = $mpn;
                $this->mpnsSet++;
                $changed = true;
            }
        }

        if (! $changed) {
            return;
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

        try {
            $product->save();
        } catch (Throwable $e) {
            // The (brand_id, mpn) unique index can collide when two products in
            // the same brand carry the same SKU/code. Skip rather than abort.
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

    /** @param Collection<int, Brand> $brands */
    private function matchBrand(string $name, Collection $brands): ?Brand
    {
        // Brands lead a product title ("HP LaserJet…", "Fellowes Powershred…"),
        // so match only at the START, after an optional filler word. Matching
        // anywhere mis-branded compatibility accessories whose names list every
        // printer they fit ("… cleaning kit for Epson Canon HP Brother"); a
        // start-anchored match attributes those to their actual maker instead.
        $head = preg_replace('/^(original|genuine|new|the)\s+/i', '', trim($name)) ?? trim($name);

        foreach ($brands as $brand) {
            // Start-anchored, case-insensitive, with a trailing word boundary so
            // "HP" does not match the start of "HPE" or "Hporz".
            $pattern = '/^'.preg_quote((string) $brand->name, '/').'(?![\p{L}\p{N}])/iu';

            if (preg_match($pattern, $head) === 1) {
                return $brand;
            }
        }

        return null;
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
