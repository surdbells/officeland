<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Icecat\IcecatIndex;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Remove already-imported products whose Icecat Catid is on the exclude list -
 * the warranties, care packs and software licences a manufacturer's index is
 * full of but OfficeLand does not sell.
 *
 * They were imported before config('icecat.exclude_catids') existed. Products do
 * not store their raw Catid, so this re-streams the index, matches the excluded
 * entries to imported products by icecat_id, and SOFT-deletes them - recoverable
 * from the trashed filter if a Catid turns out to be wanted after all.
 *
 *   php artisan catalogue:prune-icecat --brand=hp            # report only
 *   php artisan catalogue:prune-icecat --brand=hp --apply
 */
final class PruneIcecatNonProducts extends Command
{
    protected $signature = 'catalogue:prune-icecat
        {--brand= : Brand key from config/icecat.php suppliers (e.g. hp).}
        {--apply : Actually delete. Without this it only reports.}
        {--refresh-index : Re-download the repository index first.}';

    protected $description = 'Soft-delete imported products in excluded Icecat categories (warranties, licences, …)';

    public function handle(): int
    {
        $brand = Str::lower(trim((string) $this->option('brand')));
        $supplierId = config("icecat.suppliers.{$brand}");

        if ($supplierId === null) {
            $this->error('Pass a known --brand. Known: '.implode(', ', array_keys((array) config('icecat.suppliers'))));

            return self::INVALID;
        }

        $exclude = (array) config('icecat.exclude_catids');

        if ($exclude === []) {
            $this->info('No excluded Catids are configured (icecat.exclude_catids is empty).');

            return self::SUCCESS;
        }

        $indexPath = IcecatIndex::ensureCached((bool) $this->option('refresh-index'));

        if ($indexPath === null) {
            $this->error('Could not download the Icecat index. Check credentials and IP whitelisting.');

            return self::FAILURE;
        }

        // icecat_id => index, for O(1) membership tests, of this brand's imports.
        $imported = Product::query()
            ->whereNotNull('icecat_id')
            ->pluck('icecat_id')
            ->flip();

        $apply = (bool) $this->option('apply');
        $this->info('Scanning the index for excluded categories…');

        $toDelete = [];
        /** @var array<int, int> $byCat */
        $byCat = [];

        foreach (IcecatIndex::stream('compress.zlib://'.$indexPath, [(int) $supplierId]) as $entry) {
            if (! in_array($entry['catid'], $exclude, true)) {
                continue;
            }

            if (! $imported->has($entry['product_id'])) {
                continue;
            }

            $toDelete[] = $entry['product_id'];
            $byCat[$entry['catid']] = ($byCat[$entry['catid']] ?? 0) + 1;
        }

        $count = count($toDelete);

        if ($count === 0) {
            $this->info('Nothing to prune — no imported products are in an excluded category.');

            return self::SUCCESS;
        }

        if ($apply) {
            foreach (array_chunk($toDelete, 500) as $batch) {
                Product::query()->whereIn('icecat_id', $batch)->delete();
            }
        }

        $rows = [];
        foreach ($byCat as $catid => $n) {
            $rows[] = [$catid, $n];
        }

        $this->newLine();
        $this->table(['Excluded Catid', 'Products'], $rows);
        $this->info(($apply ? 'Soft-deleted' : 'Would soft-delete').": {$count} product(s).");

        if (! $apply) {
            $this->comment('Dry run. Add --apply to remove them (recoverable from the trashed filter).');
        }

        return self::SUCCESS;
    }
}
