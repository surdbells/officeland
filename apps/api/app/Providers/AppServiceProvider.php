<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Media;
use App\Models\Product;
use App\Models\User;
use App\Observers\InvalidatesRenderedRoutes;
use App\Observers\WritesAuditLog;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
         * Every mutation is recorded: who, what, when, before and after
         * (section 18). Bound to models rather than controllers so Filament,
         * the CSV importer and Icecat ingestion are all covered without each
         * remembering to log.
         */
        foreach ([Product::class, Category::class, Brand::class, Media::class, User::class] as $auditable) {
            $auditable::observe(WritesAuditLog::class);
        }

        /*
         * Fail loudly on a missing relationship rather than silently issuing
         * an N+1 query. A PDP payload that quietly fires 40 queries is
         * invisible in development and expensive when the render worker walks
         * 15,000 products.
         */
        /*
         * The admin panel is the credentialed surface, so its limit is far
         * below the storefront's. Keyed by IP because an attacker guessing
         * credentials has no session to key on (section 18).
         */
        RateLimiter::for('admin', fn (Request $request) => Limit::perMinute(
            (int) config('admin.rate_limit', 60)
        )->by($request->ip() ?? 'unknown'));

        Model::preventLazyLoading(! $this->app->isProduction());

        // Assigning an attribute that is not fillable is a mistake, not a
        // silent no-op.
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());
    }
}
