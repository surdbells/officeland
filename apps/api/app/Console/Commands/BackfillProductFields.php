<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Fill the commercial fields the content floor requires but the importers leave
 * blank: lead time, an "in the box" line, and a warranty statement.
 *
 * These are business facts, not product facts, so they are defaults rather than
 * scraped data: a house lead time, a one-line box contents, and a warranty that
 * OfficeLand (as the reseller) offers, varied by department. Only BLANK fields
 * are filled - a value already on the product (or set by hand) is never
 * overwritten - so this is safe to re-run and safe to run after a human edit.
 *
 *   php artisan catalogue:backfill-product-fields                 # report
 *   php artisan catalogue:backfill-product-fields --apply
 *   php artisan catalogue:backfill-product-fields --apply --lead=21
 */
final class BackfillProductFields extends Command
{
    protected $signature = 'catalogue:backfill-product-fields
        {--apply : Write the fields (otherwise report only).}
        {--lead=14 : Default lead time in working days for lead-time products.}
        {--all : Include non-draft products too.}';

    protected $description = 'Fill lead time, in-the-box and warranty on draft products that lack them';

    /**
     * Warranty statement by top-level department (the first path segment).
     * OfficeLand's reseller terms; edit the wording here.
     */
    private const WARRANTY = [
        'it-products' => '12-month warranty against manufacturing defects.',
        'smart-tech' => '12-month warranty against manufacturing defects.',
        'printers-consumables' => '12-month warranty against manufacturing defects.',
        'office-furniture' => '24-month warranty against manufacturing defects.',
        'pvc-doors-interiors' => '12-month warranty against manufacturing defects.',
        'office-supplies' => 'Faulty or damaged items replaced on delivery.',
        'paper-notebooks' => 'Faulty or damaged items replaced on delivery.',
        'school-supplies' => 'Faulty or damaged items replaced on delivery.',
    ];

    private const WARRANTY_DEFAULT = "Covered by the manufacturer's warranty; terms confirmed with your quote.";

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $lead = (int) $this->option('lead');
        $all = (bool) $this->option('all');

        $leadSet = 0;
        $boxSet = 0;
        $warrantySet = 0;
        $scanned = 0;

        Product::query()
            ->when(! $all, fn (Builder $q) => $q->where('status', 'draft'))
            ->with('primaryCategory:id,path')
            ->orderBy('id')
            ->chunkById(500, function ($products) use ($apply, $lead, &$leadSet, &$boxSet, &$warrantySet, &$scanned): void {
                foreach ($products as $product) {
                    $scanned++;
                    $changes = [];

                    if ($product->lead_time_days === null && $product->availability !== 'in_stock') {
                        $changes['lead_time_days'] = $lead;
                        $leadSet++;
                    }

                    if ($this->blank($product->box_contents)) {
                        $changes['box_contents'] = '1 × '.$product->name;
                        $boxSet++;
                    }

                    if ($this->blank($product->warranty_text)) {
                        $changes['warranty_text'] = $this->warrantyFor($product);
                        $warrantySet++;
                    }

                    if ($apply && $changes !== []) {
                        $product->forceFill($changes)->save();
                    }
                }
            }, 'id');

        $this->newLine();
        $this->table(['Field', $apply ? 'Set' : 'Would set'], [
            ["Lead time ({$lead} working days)", $leadSet],
            ['In the box', $boxSet],
            ['Warranty', $warrantySet],
            ['Products scanned', $scanned],
        ]);

        if (! $apply) {
            $this->comment('Report only. Re-run with --apply to write the fields.');
        }

        return self::SUCCESS;
    }

    private function warrantyFor(Product $product): string
    {
        $path = (string) ($product->primaryCategory?->path ?? '');
        $segment = Str::of($path)->trim('/')->before('/')->toString();

        return self::WARRANTY[$segment] ?? self::WARRANTY_DEFAULT;
    }

    private function blank(?string $value): bool
    {
        return trim(strip_tags((string) $value)) === '';
    }
}
