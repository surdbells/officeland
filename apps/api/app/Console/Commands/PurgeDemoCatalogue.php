<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use Database\Seeders\DemoCatalogueSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Removes every product DemoCatalogueSeeder created.
 *
 * The seeder exists so the design can be judged against a full catalogue.
 * This exists so that catalogue can be taken out again in one step — before a
 * real import, or the moment anybody is tempted to point production at this
 * database.
 *
 * Identified by the DEMO- SKU prefix, which is the only marker: `source` is
 * constrained to icecat/csv/manual at the database level, so there is no
 * "demo" value to filter on and the prefix does that job instead.
 */
final class PurgeDemoCatalogue extends Command
{
    protected $signature = 'demo:purge {--apply : Delete. Without this the command only reports.}';

    protected $description = 'Remove every DEMO- product created by DemoCatalogueSeeder';

    public function handle(): int
    {
        $ids = Product::withTrashed()
            ->where('sku', 'like', DemoCatalogueSeeder::SKU_PREFIX.'%')
            ->pluck('id');

        if ($ids->isEmpty()) {
            $this->info('No demo products.');

            return self::SUCCESS;
        }

        if (! $this->option('apply')) {
            $this->warn("{$ids->count()} demo product(s) would be deleted. Add --apply.");

            return self::SUCCESS;
        }

        DB::table('product_category')->whereIn('product_id', $ids)->delete();
        DB::table('product_relations')->whereIn('product_id', $ids)->delete();
        DB::table('product_relations')->whereIn('related_product_id', $ids)->delete();

        // Force, not soft: a soft-deleted product still holds its slug, and
        // the next import would collide with rows nobody can see.
        $deleted = Product::withTrashed()->whereIn('id', $ids)->forceDelete();

        $this->info("Deleted {$deleted} demo product(s).");
        $this->comment('Rebuild the storefront so their pages stop being served.');

        return self::SUCCESS;
    }
}
