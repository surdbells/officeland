<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\GenerateMediaDerivatives;
use App\Models\Media;
use Illuminate\Console\Command;

/**
 * Generate the <picture> derivatives for any media that lacks them.
 *
 * The storefront builds its srcset from derivatives, not the original, so a
 * media row with none renders as a broken image even though the admin still
 * shows it (the admin falls back to the original). During a large import the
 * derivative jobs can lag far behind the product jobs, or a few can fail; this
 * re-queues exactly the ones still missing, idempotently (jobs that already have
 * their derivatives are skipped by the generator and the WithoutOverlapping
 * middleware).
 *
 *   php artisan catalogue:backfill-derivatives            # queue missing
 *   php artisan catalogue:backfill-derivatives --sync     # run inline now
 *   php artisan catalogue:backfill-derivatives --force    # regenerate all media
 */
final class BackfillDerivatives extends Command
{
    protected $signature = 'catalogue:backfill-derivatives
        {--force : Regenerate derivatives for ALL media, not only those missing them.}
        {--sync : Generate inline now instead of queuing.}
        {--limit=0 : Cap how many media to process (0 = all).}';

    protected $description = 'Generate <picture> derivatives for media that is missing them';

    public function handle(): int
    {
        $force = (bool) $this->option('force');
        $limit = (int) $this->option('limit');
        $sync = (bool) $this->option('sync');

        $query = Media::query();

        if (! $force) {
            $query->whereDoesntHave('derivatives');
        }

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info('Every media row already has derivatives. Use --force to regenerate.');

            return self::SUCCESS;
        }

        $this->info(($force ? 'Regenerating' : 'Backfilling').' derivatives for '.number_format($total).' media'.($limit > 0 ? " (capped at {$limit})" : '').'…');

        $dispatched = 0;

        $query->orderBy('id')
            ->when($limit > 0, fn ($q) => $q->limit($limit))
            ->cursor()
            ->each(function (Media $media) use ($force, $sync, &$dispatched): void {
                $job = new GenerateMediaDerivatives($media->id, $force);
                $sync ? dispatch_sync($job) : dispatch($job);
                $dispatched++;
            });

        $verb = $sync ? 'Generated' : 'Queued';
        $this->info("{$verb} {$dispatched} job(s)".($sync ? '.' : ' on the media queue; the worker is building them now.'));

        if (! $sync) {
            $this->line('Large backlog? Scale the worker:  docker compose up -d --scale worker=3');
        }

        return self::SUCCESS;
    }
}
