<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Observers\InvalidatesRenderedRoutes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        /*
         * Under D-009 a page that is not regenerated serves stale content
         * indefinitely — there is no TTL to fall back on. Invalidation is
         * therefore bound to the model rather than to controllers, so that
         * Filament, the CSV importer, Icecat ingestion and tinker all
         * invalidate without each remembering to.
         */
        Product::observe(InvalidatesRenderedRoutes::class);
        Category::observe(InvalidatesRenderedRoutes::class);
        Brand::observe(InvalidatesRenderedRoutes::class);

        /*
         * Fail loudly on a missing relationship rather than silently issuing
         * an N+1 query. A PDP payload that quietly fires 40 queries is
         * invisible in development and expensive when the render worker walks
         * 15,000 products.
         */
        Model::preventLazyLoading(! $this->app->isProduction());

        // Assigning an attribute that is not fillable is a mistake, not a
        // silent no-op.
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());
    }
}
