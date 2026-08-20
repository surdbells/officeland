<?php

declare(strict_types=1);

use App\Http\Middleware\SetCachePolicy;
use App\Http\Middleware\TraceId;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        then: function (): void {
            // Registered outside the web/api groups: probes must not open
            // sessions or be rate limited. See routes/health.php.
            Route::prefix('health')
                ->middleware(TraceId::class)
                ->group(base_path('routes/health.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Every request carries a trace_id through the API, the queue and the
        // logs (MASTER BUILD PROMPT section 19.1).
        $middleware->append(TraceId::class);
        $middleware->api(prepend: [TraceId::class]);

        // Strong ETags and cache headers on catalogue reads (section 8.3).
        $middleware->api(append: [SetCachePolicy::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
         * Consistent error envelope (MASTER BUILD PROMPT section 8.3):
         *
         *   { "error": { "code", "message", "details": [], "trace_id" } }
         *
         * trace_id correlates to the structured logs, so a reported failure
         * resolves to a log entry without guesswork.
         */
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $status = $e instanceof HttpExceptionInterface
                ? $e->getStatusCode()
                : 500;

            $traceId = Context::get('trace_id');

            return response()->json([
                'error' => [
                    'code' => class_basename($e),
                    // Never leak internals in production. The trace_id is how
                    // support finds the real message.
                    'message' => $status < 500 || config('app.debug')
                        ? $e->getMessage()
                        : 'An unexpected error occurred.',
                    'details' => [],
                    'trace_id' => is_string($traceId) ? $traceId : null,
                ],
            ], $status);
        });
    })->create();
