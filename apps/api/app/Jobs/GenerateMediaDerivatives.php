<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Media\DerivativeGenerator;
use App\Models\Media;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

/**
 * Generates the derivative set for one original.
 *
 * One job per original, not one per derivative. 75,000 originals is a
 * manageable queue; 1.1M jobs is not, and the fifteen derivatives of an image
 * share the decode of its source.
 *
 * Resumable by construction: the generator skips derivatives that already
 * exist on disk AND in the database, so re-running after an interruption costs
 * a stat() per file rather than redoing the work.
 */
final class GenerateMediaDerivatives implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    /**
     * Retry with increasing delay - a transient disk-full recovers, a corrupt
     * image does not.
     *
     * @var list<int>
     */
    public array $backoff = [10, 60, 300];

    public function __construct(
        public readonly int $mediaId,
        public readonly bool $force = false,
    ) {
        // Route to the media queue. This MUST be set here (or via the $queue
        // property): Laravel's dispatcher reads $job->queue, it does NOT call a
        // queue() method. The old queue() method below was dead code, so every
        // derivative job silently fell to the 'default' queue - which the worker
        // drains LAST, behind the whole imports backlog, so a bulk import left
        // thousands of images without derivatives (broken on the storefront)
        // while `queue:monitor media` showed an always-empty queue.
        $this->onQueue((string) config('media.queue', 'media'));
    }

    /** @return list<object> */
    public function middleware(): array
    {
        // Two workers generating the same original would duplicate ~15 encodes
        // and race on the same output paths.
        return [(new WithoutOverlapping((string) $this->mediaId))->expireAfter(600)];
    }

    public function handle(DerivativeGenerator $generator): void
    {
        $media = Media::find($this->mediaId);

        if ($media === null) {
            // Deleted between enqueue and execution. Not an error.
            return;
        }

        $result = $generator->generate($media, $this->force);

        Log::info('media derivatives generated', [
            'media_uuid' => $media->uuid,
            'generated' => $result['generated'],
            'skipped' => $result['skipped'],
            'failed' => $result['failed'],
        ]);

        // A partial failure must not be swallowed: the page would render with
        // some formats missing and the <picture> fallback would quietly serve
        // a larger image than intended.
        if ($result['failed'] > 0) {
            $this->fail(new \RuntimeException(
                "{$result['failed']} derivative(s) failed for media {$media->uuid}"
            ));
        }
    }
}
