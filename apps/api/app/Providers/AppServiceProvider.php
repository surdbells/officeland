<?php

declare(strict_types=1);

namespace App\Providers;

use App\Listeners\LogAuthenticationEvents;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Media;
use App\Models\Product;
use App\Models\User;
use App\Observers\InvalidatesRenderedRoutes;
use App\Observers\WritesAuditLog;
use App\Rendering\StaticSite;
use App\Search\PostgresSearchEngine;
use App\Search\SearchEngine;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /*
         * Section 16 requires search to sit behind an interface so
         * Meilisearch or Typesense can replace it without touching callers.
         * Binding it here is what makes that swap a one-line change.
         */
        $this->app->bind(
            SearchEngine::class,
            PostgresSearchEngine::class,
        );

        // The static root is configuration, not a constructor default: the
        // render container and the API container mount the same volume at the
        // same path, and a hardcoded default would silently write to the
        // wrong one if either ever moved.
        $this->app->singleton(
            StaticSite::class,
            static fn (): StaticSite => new StaticSite((string) config('rendering.static_root')),
        );
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

        /*
         * Public API limits (Phase 13a).
         *
         * Until this existed the public API had no limit of any kind: the only
         * throttled surfaces were the admin panel and quote submission. Search
         * in particular runs FTS, then a trigram fallback, then facet
         * aggregation — it was both the most expensive endpoint in the system
         * and completely open, which is the cheapest way to take the site down.
         *
         * See config/api.php for why the numbers differ per surface and why
         * internal traffic is exempt.
         */
        foreach (['catalogue', 'search', 'basket', 'webhook'] as $surface) {
            RateLimiter::for($surface, function (Request $request) use ($surface): Limit {
                $ip = $request->ip() ?? 'unknown';

                // The render worker walks 15,000+ routes in a full build.
                // Throttling it turns a template fix into a half-built site.
                if ($this->isInternal($ip)) {
                    return Limit::none();
                }

                return Limit::perMinute((int) config("api.rate_limits.{$surface}", 60))->by($ip);
            });
        }

        /*
         * The authentication trail (Phase 13a, OWASP A09).
         *
         * Registered explicitly rather than by auto-discovery: these are the
         * events a compromise investigation starts from, and a listener that
         * silently stops being discovered is a log that silently stops being
         * written.
         */
        Event::listen(Failed::class, [LogAuthenticationEvents::class, 'handleFailed']);
        Event::listen(Lockout::class, [LogAuthenticationEvents::class, 'handleLockout']);
        Event::listen(Login::class, [LogAuthenticationEvents::class, 'handleLogin']);
        Event::listen(Logout::class, [LogAuthenticationEvents::class, 'handleLogout']);
        Event::listen(PasswordReset::class, [LogAuthenticationEvents::class, 'handlePasswordReset']);

        Model::preventLazyLoading(! $this->app->isProduction());

        // Assigning an attribute that is not fillable is a mistake, not a
        // silent no-op.
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());
    }

    /**
     * Whether this address belongs to the container network rather than a user.
     *
     * Prefix matching rather than CIDR: the set being recognised is four
     * constants describing a Docker network, and a CIDR library for that would
     * be more code to be wrong in. See config/api.php for why an internet
     * client cannot reach this branch.
     */
    private function isInternal(string $ip): bool
    {
        /** @var list<string> $prefixes */
        $prefixes = (array) config('api.trusted_prefixes', []);

        foreach ($prefixes as $prefix) {
            if ($prefix !== '' && str_starts_with($ip, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
