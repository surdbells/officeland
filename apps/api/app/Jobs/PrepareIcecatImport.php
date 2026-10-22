<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Icecat\IcecatIndex;
use App\Models\ImportRun;
use App\Models\Product;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Prepares an admin import: streams the index and fills the batch with one
 * per-product job for every NEW product, in chunks.
 *
 * This is itself a batched job, so the whole thing runs on the worker, off the
 * request path - the admin launches it and can walk away. It does the cheap part
 * (reading the index, deduping, enqueuing); the per-product jobs it adds do the
 * expensive fetching and image work, spread across the worker(s). Adding in
 * chunks keeps memory flat and lets the batch start processing products before
 * the whole 3.9 GB index has been read.
 *
 * Resumable by construction: products already imported (dedup by icecat_id) are
 * never enqueued, so re-launching a run only adds what is genuinely new.
 */
final class PrepareIcecatImport implements ShouldQueue
{
    use Batchable;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    /** How many per-product jobs to add to the batch at a time. */
    private const CHUNK = 250;

    public function __construct(public readonly int $importRunId) {}

    public function queue(): string
    {
        return 'imports';
    }

    public function handle(): void
    {
        $run = ImportRun::find($this->importRunId);

        if ($run === null || $this->batch()?->cancelled()) {
            return;
        }

        @ini_set('memory_limit', '512M');

        $suppliers = $this->resolveSuppliers($run->brands);

        if ($suppliers === []) {
            $run->update(['status' => 'failed', 'message' => 'No known brands to import.', 'finished_at' => now()]);

            return;
        }

        $indexPath = IcecatIndex::ensureCached();

        if ($indexPath === null) {
            $run->update([
                'status' => 'failed',
                'message' => 'Could not download the Icecat index. Check credentials and IP whitelisting.',
                'finished_at' => now(),
            ]);

            return;
        }

        $options = $run->options;
        $limit = (int) ($options['limit'] ?? 0);
        $maxImages = isset($options['max_images']) ? max(1, (int) $options['max_images']) : null;
        $since = $this->parseSince((string) ($options['since'] ?? ''));
        $catids = array_values(array_filter(array_map(
            static fn ($c): int => (int) trim((string) $c),
            is_array($options['catids'] ?? null) ? $options['catids'] : explode(',', (string) ($options['catids'] ?? '')),
        )));
        $exclude = (array) config('icecat.exclude_catids');

        /** @var array<int, string> $bySupplier supplierId => brandKey */
        $bySupplier = [];
        foreach ($suppliers as $key => $id) {
            $bySupplier[$id] = $key;
        }

        $run->update(['status' => 'running', 'started_at' => $run->started_at ?? now()]);

        $queued = 0;
        $chunk = [];

        try {
            foreach (IcecatIndex::stream('compress.zlib://'.$indexPath, array_values($suppliers)) as $entry) {
                if ($this->batch()?->cancelled()) {
                    break;
                }

                if ($limit > 0 && $queued >= $limit) {
                    break;
                }

                // Never import warranties, care packs or software licences.
                if (in_array($entry['catid'], $exclude, true)) {
                    continue;
                }

                if ($catids !== [] && ! in_array($entry['catid'], $catids, true)) {
                    continue;
                }

                if ($since !== null && ($entry['date_added'] === null || $entry['date_added'] < $since)) {
                    continue;
                }

                if (Product::query()->where('icecat_id', $entry['product_id'])->exists()) {
                    continue;
                }

                $chunk[] = new ImportIcecatProduct(
                    entry: $entry,
                    brandKey: $bySupplier[$entry['supplier_id']] ?? 'unknown',
                    maxImages: $maxImages,
                    queueDerivatives: true,
                    importRunId: $run->id,
                );
                $queued++;

                if (count($chunk) >= self::CHUNK) {
                    $this->batch()?->add($chunk);
                    $run->increment('total', count($chunk));
                    $chunk = [];
                }
            }

            if ($chunk !== []) {
                $this->batch()?->add($chunk);
                $run->increment('total', count($chunk));
            }
        } catch (Throwable $e) {
            Log::error('icecat import prepare failed', ['run' => $run->uuid, 'error' => $e->getMessage()]);
            $run->update(['status' => 'failed', 'message' => 'Index scan failed: '.$e->getMessage(), 'finished_at' => now()]);

            return;
        }

        // Nothing new to import: finish cleanly rather than sit "running" forever
        // (the finally callback only fires when there are product jobs to wait on
        // beyond this one, so close it out here).
        if ($queued === 0) {
            $run->update(['status' => 'completed', 'message' => 'Nothing new to import.', 'finished_at' => now()]);
        }

        Log::info('icecat import prepared', ['run' => $run->uuid, 'queued' => $queued]);
    }

    /**
     * Map the run's brand keys to supplier ids, dropping unknowns.
     *
     * @param  array<int, string>  $brandKeys
     * @return array<string, int>
     */
    private function resolveSuppliers(array $brandKeys): array
    {
        /** @var array<string, int|string> $all */
        $all = (array) config('icecat.suppliers');
        $out = [];

        foreach ($brandKeys as $key) {
            $key = strtolower(trim($key));
            if (isset($all[$key])) {
                $out[$key] = (int) $all[$key];
            }
        }

        return $out;
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
}
