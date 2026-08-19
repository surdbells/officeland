<?php

declare(strict_types=1);

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Health routes
|--------------------------------------------------------------------------
|
| Registered without the web or api middleware groups: probes must not open
| sessions, touch CSRF, or be rate limited. An orchestrator polling readiness
| every few seconds would otherwise exhaust the rate limiter and take the
| instance out of service for the wrong reason.
|
| Excluded from the sitemap and served with noindex at the edge.
|
*/

Route::get('/live', [HealthController::class, 'live'])->name('health.live');
Route::get('/ready', [HealthController::class, 'ready'])->name('health.ready');
