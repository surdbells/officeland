<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Icecat\IcecatImporter;
use App\Models\ImportRun;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Imports ONE Icecat product (fetch data sheet → create draft → mirror images).
 *
 * The unit of the queue-driven importer: a dispatcher (CLI) or the prepare job
 * (admin) enqueues one of these per new product, and the worker drains them in
 * the background. That is what makes a large import survive an SSH disconnect or
 * a closed browser tab, retry transient Icecat/network failures on its own, and
 * spread the work over time instead of a single foreground run that dies partway
 * and competes with live traffic.
 *
 * Idempotent: the importer dedups by icecat_id, so a re-dispatched or retried
 * job for an already-imported product is a no-op. Unique by product id while
 * queued, so a re-dispatch does not double up.
 *
 * When part of an admin import batch it carries the ImportRun id and advances its
 * live counters atomically - the progress the admin polls.
 */
final class ImportIcecatProduct implements ShouldBeUnique, ShouldQueue
{
    use Batchable;
    use Queueable;

    public int $tries = 3;

    public int $timeout = 180;

    /** Transient Icecat/network blips recover; back off rather than hammer. */
    public array $backoff = [30, 120, 300];

    /** Only hold the uniqueness lock briefly - long enough to dedup a burst. */
    public int $uniqueFor = 3600;

    /**
     * @param  array{product_id: int, mpn: ?string, supplier_id: int, catid: ?int, model_name: ?string, ean: ?string}  $entry
     */
    public function __construct(
        public readonly array $entry,
        public readonly string $brandKey,
        public readonly ?int $maxImages = null,
        public readonly bool $queueDerivatives = true,
        public readonly ?int $importRunId = null,
    ) {}

    public function uniqueId(): string
    {
        return 'icecat-product:'.$this->entry['product_id'];
    }

    public function queue(): string
    {
        return 'imports';
    }

    public function handle(IcecatImporter $importer): void
    {
        // A cancelled batch stops doing new work immediately; remaining jobs
        // drain as no-ops.
        if ($this->batch()?->cancelled()) {
            return;
        }

        // Memory headroom: even with downloads streamed to disk, decoding
        // Icecat's 4200px originals needs more than the 128 MB queue default.
        @ini_set('memory_limit', '512M');

        $product = $importer->importEntry(
            $this->entry,
            $this->brandKey,
            $this->maxImages,
            $this->queueDerivatives,
        );

        // null = already imported or no usable data; a real product = created.
        $this->bump($product !== null ? 'created' : 'skipped');

        if ($product !== null) {
            Log::info('icecat product imported', [
                'icecat_id' => $this->entry['product_id'],
                'slug' => $product->slug,
                'brand' => $this->brandKey,
            ]);
        }
    }

    /** Runs once, after the final retry is exhausted. */
    public function failed(?Throwable $e): void
    {
        $this->bump('failed');

        Log::warning('icecat product import failed', [
            'icecat_id' => $this->entry['product_id'] ?? null,
            'brand' => $this->brandKey,
            'error' => $e?->getMessage(),
        ]);
    }

    /** Advance one of the run's counters atomically, if this job belongs to a run. */
    private function bump(string $column): void
    {
        if ($this->importRunId !== null) {
            ImportRun::query()->whereKey($this->importRunId)->increment($column);
        }
    }
}
