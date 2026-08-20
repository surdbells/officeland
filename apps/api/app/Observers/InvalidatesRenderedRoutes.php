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
    public function __construct(
        private readonly RouteResolver $resolver,
        private readonly RenderQueue $queue,
    ) {}

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
        $this->queue->push($this->resolver->resolve($model));
    }
}
