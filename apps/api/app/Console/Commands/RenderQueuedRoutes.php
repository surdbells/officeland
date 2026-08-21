<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Rendering\RenderQueue;
use App\Rendering\RouteRenderer;
use Illuminate\Console\Command;

/**
 * Drains the render queue (Phase 8c/8e).
 *
 * Under D-009 there is no TTL and no request-time fallback: a page that is not
 * regenerated serves stale content until something regenerates it. This command
 * is what closes that loop, and its failure mode is silent by nature, so it
 * reports counts rather than succeeding quietly.
 *
 * Run continuously by the render worker container, or on demand. Safe to run
 * concurrently with itself — RenderQueue::claim() pops atomically, so two
 * workers cannot claim the same route.
 */
final class RenderQueuedRoutes extends Command
{
    protected $signature = 'render:queue
        {--limit=100 : Routes to claim in one pass}
        {--once : Drain what is pending and stop, rather than looping}
        {--sleep=5 : Seconds to wait when the queue is empty}';

    protected $description = 'Render routes that catalogue changes have invalidated';

    public function handle(RenderQueue $queue, RouteRenderer $renderer): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $once = (bool) $this->option('once');
        $sleep = max(1, (int) $this->option('sleep'));

        $rendered = 0;
        $failed = 0;

        do {
            $routes = $queue->claim($limit);

            if ($routes === []) {
                if ($once) {
                    break;
                }

                sleep($sleep);

                continue;
            }

            $result = $renderer->render($routes);

            $rendered += $result->rendered;
            $failed += count($result->failures);

            foreach ($result->failures as $route => $reason) {
                /*
                 * Reported, not re-queued. A route that fails to render will
                 * almost always fail again for the same reason, and requeueing
                 * turns one broken page into an infinite loop that starves
                 * every other route behind it.
                 */
                $this->error("render failed: {$route} — {$reason}");
            }

            $this->info("rendered {$result->rendered} route(s) in {$result->durationMs}ms");
        } while (! $once);

        $this->info("total rendered: {$rendered}, failed: {$failed}");

        // A non-zero exit so a scheduler or CI step notices. The queue has
        // already been drained either way.
        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
