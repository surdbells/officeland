<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Attribute;
use App\Search\PostgresSearchEngine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Decide, from the data, which attributes are worth faceting on.
 *
 * The storefront PLP computes one live COUNT(*) GROUP BY per filterable
 * attribute, over the active catalogue, on every listing request. That cost is
 * linear in the number of filterable attributes, so the set has to stay small
 * and genuinely useful - a dozen, not two hundred. The Icecat ingest and the
 * supplier importers each minted an Attribute per spec name and marked them all
 * filterable, which took the catalogue to ~200 facet groups and made the
 * unfiltered homepage compute ~200 full-catalogue aggregates per request: the
 * October incident (a 52k publish turned that from slow to fatal).
 *
 * A good facet is low-cardinality (a handful of values a buyer would choose
 * between, e.g. Colour, Material) and broadly covered (enough products carry it
 * to be worth a filter). A manufacturer code, a packing note or a carton
 * quantity is neither - one value per product, no buyer would filter on it - so
 * those are stored as specs but never faceted. This promotes to filterable only
 * the attributes that clear both bars, newest-coverage first, capped. Everything
 * else is set non-filterable. Idempotent, and safe to re-run after any import.
 *
 *   php artisan catalogue:curate-facets            # report what it would keep
 *   php artisan catalogue:curate-facets --apply
 */
final class CurateFacets extends Command
{
    protected $signature = 'catalogue:curate-facets
        {--apply : Write is_filterable. Without this it only reports.}
        {--max=24 : Keep at most this many facets (the most-covered win).}
        {--min-products=8 : An attribute must cover at least this many active products.}
        {--max-values=40 : An attribute with more distinct values than this is too high-cardinality to be a facet.}';

    protected $description = 'Promote only low-cardinality, well-covered attributes to filterable facets';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $max = max(1, (int) $this->option('max'));
        $minProducts = max(1, (int) $this->option('min-products'));
        $maxValues = max(2, (int) $this->option('max-values'));

        // Cardinality and coverage per attribute, counted over the ACTIVE
        // catalogue only - a facet exists to filter what is actually listed.
        $stats = DB::table('attributes as a')
            ->join('product_attribute_values as pav', 'pav.attribute_id', '=', 'a.id')
            ->join('products as p', function ($join): void {
                $join->on('p.id', '=', 'pav.product_id')->where('p.status', '=', 'active');
            })
            ->groupBy('a.id', 'a.code', 'a.name')
            ->select([
                'a.id',
                'a.code',
                'a.name',
                DB::raw('count(distinct pav.value_raw) as distinct_values'),
                DB::raw('count(distinct pav.product_id) as product_count'),
            ])
            ->get();

        $eligible = $stats
            ->filter(fn ($row): bool => (int) $row->product_count >= $minProducts
                && (int) $row->distinct_values >= 2
                && (int) $row->distinct_values <= $maxValues)
            ->sortByDesc(fn ($row): int => (int) $row->product_count)
            ->take($max)
            ->values();

        $keepIds = $eligible->pluck('id')->map(fn ($id): int => (int) $id)->all();

        if ($apply) {
            DB::transaction(function () use ($keepIds): void {
                // whereNotIn([]) matches every row, so an empty keep set turns
                // all facets off - the intended "nothing qualifies" outcome.
                Attribute::query()->whereNotIn('id', $keepIds)->update(['is_filterable' => false]);
                if ($keepIds !== []) {
                    Attribute::query()->whereIn('id', $keepIds)->update(['is_filterable' => true]);
                }
            });

            // Invalidate every cached facet payload at once: the set of facets
            // just changed, so last generation's cache is wrong.
            Cache::add(PostgresSearchEngine::FACET_CACHE_GENERATION_KEY, 0);
            Cache::increment(PostgresSearchEngine::FACET_CACHE_GENERATION_KEY);
        }

        $this->newLine();
        $this->line(($apply ? 'Kept filterable' : 'Would keep filterable').': '.count($keepIds).' facet(s).');
        $this->table(
            ['Code', 'Name', 'Values', 'Active products'],
            $eligible->map(fn ($row): array => [
                $row->code,
                $row->name,
                (int) $row->distinct_values,
                (int) $row->product_count,
            ])->all(),
        );

        $turnedOff = Attribute::query()->count() - count($keepIds);
        $this->line(($apply ? 'Set non-filterable' : 'Would set non-filterable').': '.$turnedOff.' attribute(s).');

        if (! $apply) {
            $this->comment('Dry run. Re-run with --apply to write is_filterable and clear the facet cache.');
        } else {
            $this->info('Facet set curated and facet cache invalidated.');
        }

        return self::SUCCESS;
    }
}
