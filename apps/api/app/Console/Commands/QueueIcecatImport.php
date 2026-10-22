<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Icecat\IcecatIndex;
use App\Jobs\ImportIcecatProduct;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Queue a resumable, background import of Icecat products for one brand or all.
 *
 *   php artisan catalogue:icecat-import --brand=dell --since=2022 --apply
 *   php artisan catalogue:icecat-import --all --since=2022 --apply
 *
 * ── Why this exists (vs catalogue:icecat-discover) ───────────────────────────
 * discover is a single foreground loop that fetches each data sheet and builds
 * images inline. On a large brand that run is long, memory-heavy, and dies the
 * moment the SSH session drops - taking the import with it. This command does
 * almost no work itself: it streams the index and enqueues ONE small job per new
 * product (ImportIcecatProduct). An always-on worker (the `worker` service)
 * drains the queue in the background, with retries and a memory cap per job, so
 * the import survives disconnects, recovers from transient Icecat errors, and
 * spreads the load instead of competing with live traffic in one burst.
 *
 * Resumable: products already imported (dedup by icecat_id) are never re-queued,
 * so re-running only enqueues what is genuinely new. Dispatching is fast enough
 * to finish in one sitting even for a brand with hundreds of thousands of SKUs.
 */
final class QueueIcecatImport extends Command
{
    protected $signature = 'catalogue:icecat-import
        {--brand= : Brand key from config/icecat.php suppliers (e.g. dell).}
        {--all : Enqueue every configured brand in a single index pass.}
        {--apply : Actually enqueue jobs. Without this the command only counts.}
        {--since= : Only products Icecat catalogued on/after this date (YYYY, YYYY-MM, YYYY-MM-DD).}
        {--catids= : Comma-separated Icecat category ids to import; others skipped.}
        {--limit=0 : Maximum NEW products to enqueue (0 = no limit).}
        {--max-images= : Images to mirror per product (default: config icecat.max_images).}
        {--refresh-index : Re-download the repository index even if the cache is fresh.}';

    protected $description = 'Queue a resumable background import of Icecat products';

    private int $seen = 0;

    private int $queued = 0;

    private int $skippedExisting = 0;

    public function handle(): int
    {
        if ((string) config('icecat.username') === '') {
            $this->warn('ICECAT_USERNAME is not set; relying on IP whitelisting alone.');
        }

        // brandKey => supplierId for the brands we will import, and the reverse
        // map so a single index pass can route each entry back to its brand.
        $suppliers = $this->resolveSuppliers();

        if ($suppliers === []) {
            return self::INVALID;
        }

        /** @var array<int, string> $bySupplier supplierId => brandKey */
        $bySupplier = [];
        foreach ($suppliers as $key => $id) {
            $bySupplier[$id] = $key;
        }

        $indexPath = $this->ensureIndex();

        if ($indexPath === null) {
            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $limit = (int) $this->option('limit');
        $since = $this->parseSince((string) $this->option('since'));
        $maxImages = $this->option('max-images') !== null
            ? max(1, (int) $this->option('max-images'))
            : null;

        $catids = array_values(array_filter(array_map(
            static fn ($c): int => (int) trim($c),
            explode(',', (string) $this->option('catids')),
        )));

        $this->info('Scanning the index for '.count($suppliers).' brand(s)…');

        foreach (IcecatIndex::stream('compress.zlib://'.$indexPath, array_values($suppliers)) as $entry) {
            if ($limit > 0 && $this->queued >= $limit) {
                break;
            }

            if (in_array($entry['catid'], (array) config('icecat.exclude_catids'), true)) {
                continue;
            }

            if ($catids !== [] && ! in_array($entry['catid'], $catids, true)) {
                continue;
            }

            if ($since !== null && ($entry['date_added'] === null || $entry['date_added'] < $since)) {
                continue;
            }

            $this->seen++;

            // Dedup here keeps the queue lean on re-runs; the job dedups again at
            // run time in case it was imported between enqueue and execution.
            if (Product::query()->where('icecat_id', $entry['product_id'])->exists()) {
                $this->skippedExisting++;

                continue;
            }

            $brandKey = $bySupplier[$entry['supplier_id']] ?? 'unknown';

            if ($apply) {
                ImportIcecatProduct::dispatch($entry, $brandKey, $maxImages);
            }

            $this->queued++;

            if ($this->queued % 500 === 0) {
                $this->line("  …{$this->queued} queued");
            }
        }

        $this->report($apply);

        return self::SUCCESS;
    }

    /**
     * The brands to import, as brandKey => supplierId.
     *
     * @return array<string, int>
     */
    private function resolveSuppliers(): array
    {
        /** @var array<string, int|string> $all */
        $all = (array) config('icecat.suppliers');

        if ($this->option('all')) {
            return array_map(static fn ($id): int => (int) $id, $all);
        }

        $brand = Str::lower(trim((string) $this->option('brand')));

        if ($brand === '') {
            $this->error('Pass --brand=<key> or --all.');

            return [];
        }

        if (! isset($all[$brand])) {
            $this->error("Unknown brand '{$brand}'. Known: ".implode(', ', array_keys($all)));

            return [];
        }

        return [$brand => (int) $all[$brand]];
    }

    /** Download the repository index to the cache if missing, stale, or forced. */
    private function ensureIndex(): ?string
    {
        $path = (string) config('icecat.index_cache');
        $ttl = (int) config('icecat.index_ttl_hours', 24) * 3600;
        $fresh = is_file($path) && (time() - (int) filemtime($path)) < $ttl;

        if ($fresh && ! $this->option('refresh-index')) {
            return $path;
        }

        @mkdir(dirname($path), 0775, true);
        $this->info('Downloading the Icecat index (~310 MB; cached for next time)…');

        try {
            $response = Http::withBasicAuth((string) config('icecat.username'), (string) config('icecat.password'))
                ->timeout(1800)
                ->sink($path)
                ->get((string) config('icecat.endpoints.index'));
        } catch (Throwable $e) {
            $this->error('Index download failed: '.$e->getMessage());

            return null;
        }

        if (! $response->successful() || ! is_file($path) || (filesize($path) ?: 0) < 1_000_000) {
            $this->error('Index download failed (HTTP '.$response->status().'). Check credentials and IP whitelisting.');
            @unlink($path);

            return null;
        }

        return $path;
    }

    /** "2023", "2023-05" or "2023-05-10" → YYYYMMDD int, or null. */
    private function parseSince(string $value): ?int
    {
        $digits = preg_replace('/\D/', '', $value) ?? '';

        return match (strlen($digits)) {
            0 => null,
            4 => (int) ($digits.'0101'),
            6 => (int) ($digits.'01'),
            default => (int) substr(str_pad($digits, 8, '0'), 0, 8),
        };
    }

    private function report(bool $apply): void
    {
        $this->newLine();
        $this->table(['Metric', 'Count'], [
            ['Index entries matched', $this->seen],
            ['Already imported (skipped)', $this->skippedExisting],
            [$apply ? 'Jobs queued' : 'Would queue', $this->queued],
        ]);

        if (! $apply) {
            $this->info('Dry run. Re-run with --apply to enqueue the import jobs.');

            return;
        }

        $this->info('Jobs queued on the "imports" queue; derivatives will queue on "media".');
        $this->line('The worker service drains them in the background. Watch progress:');
        $this->line('  docker compose exec -T api php artisan queue:monitor imports,media');
        $this->line('Then publish the renderable drafts:  php artisan catalogue:icecat-publish --brand=<b> --apply');
    }
}
