<?php

declare(strict_types=1);

namespace App\Icecat;

use App\Jobs\PrepareIcecatImport;
use App\Models\ImportRun;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Throwable;

/**
 * Launches a background Icecat import and returns its ImportRun.
 *
 * One place that turns "the admin asked for an import" into a tracked, cancelable
 * job batch: it records the run, kicks off the prepare job inside a batch, and
 * wires the batch's terminal callback back to the run so it always reaches a
 * resting status - even if whoever launched it has long since closed the tab.
 */
final class ImportLauncher
{
    /**
     * @param  array<int, string>  $brands  config supplier keys
     * @param  array<string, mixed>  $options  since, catids, limit, max_images
     */
    public function launch(array $brands, array $options, ?int $userId = null): ImportRun
    {
        $brands = array_values(array_unique(array_filter(array_map('strval', $brands))));

        $run = ImportRun::create([
            'source' => 'icecat',
            'label' => $this->label($brands, $options),
            'brands' => $brands,
            'options' => $options,
            'status' => 'preparing',
            'started_by_user_id' => $userId,
        ]);

        $runId = $run->id;

        $batch = Bus::batch([new PrepareIcecatImport($runId)])
            ->name('icecat-import:'.$run->uuid)
            ->onQueue('imports')
            // Individual product failures are expected (a 404 data-sheet, a bad
            // image) and must not fail the whole batch; they are counted instead.
            ->allowFailures()
            ->finally(function (Batch $batch) use ($runId): void {
                $run = ImportRun::find($runId);

                if ($run === null || $run->status === 'failed') {
                    return;
                }

                $run->update([
                    'status' => $batch->cancelled() ? 'cancelled' : 'completed',
                    'finished_at' => now(),
                    'message' => $run->message ?? sprintf(
                        '%s: %d created, %d skipped, %d failed of %d.',
                        $batch->cancelled() ? 'Cancelled' : 'Done',
                        $run->created,
                        $run->skipped,
                        $run->failed,
                        $run->total,
                    ),
                ]);
            })
            ->dispatch();

        $run->update(['batch_id' => $batch->id]);

        return $run;
    }

    /** Try to cancel a run's batch. Safe to call on an already-finished run. */
    public function cancel(ImportRun $run): void
    {
        if ($run->batch_id === null) {
            return;
        }

        try {
            Bus::findBatch($run->batch_id)?->cancel();
        } catch (Throwable) {
            // Batch pruned or gone; the finally callback already settled the run.
        }
    }

    /**
     * @param  array<int, string>  $brands
     * @param  array<string, mixed>  $options
     */
    private function label(array $brands, array $options): string
    {
        $all = array_keys((array) config('icecat.suppliers'));
        $brandLabel = count($brands) >= count($all) && $all !== []
            ? 'All brands'
            : collect($brands)->map(static fn (string $b): string => Str::title($b))->join(', ');

        $since = (string) ($options['since'] ?? '');

        return $since !== '' ? $brandLabel.' · since '.$since : $brandLabel;
    }
}
