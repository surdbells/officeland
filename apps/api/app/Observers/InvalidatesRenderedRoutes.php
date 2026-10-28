<?php

declare(strict_types=1);

namespace App\Observers;

use App\Rendering\RenderQueue;
use App\Rendering\RouteResolver;
use Illuminate\Database\Eloquent\Model;

/**
 * Enqueues affected routes whenever catalogue content changes.
 *
 * Registered for Product, Category and Brand in AppServiceProvider.
 *
 * Deliberately observes saved/deleted/restored rather than being called from
 * controllers: under D-009 a page that is not regenerated is a page serving
 * stale content forever, so invalidation must not depend on every write path
 * remembering to ask. Filament, the CSV importer, Icecat ingestion and tinker
 * all mutate models, and all of them must invalidate.
 */
final class InvalidatesRenderedRoutes
{
    /**
     * Suppression depth. While > 0, saves do NOT enqueue routes.
     *
     * A per-save enqueue is right for an editor saving one product, and wrong
     * for a bulk command touching tens of thousands: `finalise-products`
     * publishing 52k products enqueued ~90k routes in one run, and draining
     * them stampeded the API (the October incident). Bulk writers wrap their
     * loop in withoutInvalidation() and enqueue once at the end - or leave the
     * rebuild to a deliberate `render:full`. Counter, not a bool, so nested
     * suppression composes; try/finally guarantees it is always lifted.
     */
    private static int $suppressed = 0;

    public function __construct(
        private readonly RouteResolver $resolver,
        private readonly RenderQueue $queue,
    ) {}

    /**
     * Run $callback with per-save route invalidation suppressed. Returns the
     * callback's own return value.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function withoutInvalidation(callable $callback): mixed
    {
        self::$suppressed++;

        try {
            return $callback();
        } finally {
            self::$suppressed = max(0, self::$suppressed - 1);
        }
    }

    public static function isSuppressed(): bool
    {
        return self::$suppressed > 0;
    }

    public function saved(Model $model): void
    {
        $this->invalidate($model);
    }

    public function deleted(Model $model): void
    {
        $this->invalidate($model);
    }

    public function restored(Model $model): void
    {
        $this->invalidate($model);
    }

    private function invalidate(Model $model): void
    {
        if (self::$suppressed > 0) {
            return;
        }

        $this->queue->push($this->resolver->resolve($model));
    }
}
