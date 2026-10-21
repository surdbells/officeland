<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Icecat\IcecatImporter;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Imports ONE Icecat product (fetch data sheet → create draft → mirror images).
 *
 * The unit of the queue-driven importer: a dispatcher streams the index and
 * enqueues one of these per new product, and the worker drains them in the
 * background. That is what makes a large import survive an SSH disconnect, retry
 * transient Icecat/network failures on its own, and spread the work over time
 * instead of a single foreground run that dies partway and competes with live
 * traffic.
 *
 * Idempotent: the importer dedups by icecat_id, so a re-dispatched or retried
 * job for an already-imported product is a no-op. Unique by product id while
 * queued, so a re-dispatch does not double up.
 */
final class ImportIcecatProduct implements ShouldBeUnique, ShouldQueue
{
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
        // Memory headroom: even with downloads streamed to disk, decoding
        // Icecat's 4200px originals needs more than the 128 MB queue default.
        @ini_set('memory_limit', '512M');

        $product = $importer->importEntry(
            $this->entry,
            $this->brandKey,
            $this->maxImages,
            $this->queueDerivatives,
        );

        if ($product === null) {
            // Already imported, or no usable data. Not a failure.
            return;
        }

        Log::info('icecat product imported', [
            'icecat_id' => $this->entry['product_id'],
            'slug' => $product->slug,
            'brand' => $this->brandKey,
        ]);
    }
}
