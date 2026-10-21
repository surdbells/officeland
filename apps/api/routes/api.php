<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\BasketController;
use App\Http\Controllers\Api\V1\BrandController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\HomeController;
use App\Http\Controllers\Api\V1\MailWebhookController;
use App\Http\Controllers\Api\V1\NavigationController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\QuoteController;
use App\Http\Controllers\Api\V1\RenderManifestController;
use App\Http\Controllers\Api\V1\SearchController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 — public catalogue reads
|--------------------------------------------------------------------------
|
| All cacheable, all returning a strong ETag (App\Http\Middleware\SetCachePolicy).
|
| Under D-009 the storefront is prerendered, so these are consumed mainly by
| the render worker at build time and by client-side facet filtering. They are
| not on the critical path of a page view.
|
| Search, quotes, pages and articles arrive in Phases 6, 9 and 12. They are
| absent rather than stubbed — section 2.6.
|
*/

Route::prefix('v1')->name('api.')->middleware('throttle:catalogue')->group(function (): void {
    Route::get('navigation', NavigationController::class)->name('navigation');

    // Curated homepage content (hero, category tiles, best-sellers). Empty
    // sections fall back to the storefront's built-in derived behaviour.
    Route::get('home', HomeController::class)->name('home');

    Route::get('brands', [BrandController::class, 'index'])->name('brands.index');
    Route::get('brands/{brand}', [BrandController::class, 'show'])->name('brands.show');
    Route::get('brands/{brand}/products', [BrandController::class, 'products'])->name('brands.products');

    Route::get('categories', [CategoryController::class, 'index'])->name('categories.index');

    // The prerender route list (D-009). Declared before the category catch-all
    // so 'render/manifest' is not swallowed as a category path.
    Route::get('render/manifest', RenderManifestController::class)->name('render.manifest');
    // The whole catalogue as one faceted listing (the /shop/ page). Declared
    // before 'products/{slug}' so 'products' is not read as a slug.
    Route::get('products', [ProductController::class, 'index'])->name('products.index');

    Route::get('products/{slug}', [ProductController::class, 'show'])->name('products.show');

    /*
     * Quote basket and submission (Phase 9).
     *
     * The only WRITE surface on the public API, and the only one that is not
     * cacheable: identity comes from a signed HttpOnly cookie, and the server
     * is authoritative for every line (section 9a).
     */
    Route::middleware('throttle:basket')->group(function (): void {
        Route::get('basket', [BasketController::class, 'show'])->name('basket.show');
        Route::post('basket/items', [BasketController::class, 'addProduct'])->name('basket.add');
        Route::post('basket/items/free-text', [BasketController::class, 'addFreeText'])->name('basket.add-free-text');
        Route::patch('basket/items/{item}', [BasketController::class, 'updateItem'])->name('basket.update');
        Route::delete('basket/items/{item}', [BasketController::class, 'removeItem'])->name('basket.remove');
        Route::delete('basket', [BasketController::class, 'clear'])->name('basket.clear');
    });

    Route::post('quotes', [QuoteController::class, 'store'])->name('quotes.store');

    /*
     * ZeptoMail bounce and complaint webhooks (section 10d).
     *
     * Unauthenticated by design — the caller is ZeptoMail, which cannot hold
     * our credentials — and therefore verified by HMAC signature inside the
     * controller. An unverified webhook could suppress an arbitrary address,
     * so the check is the whole security boundary here.
     */
    Route::post('webhooks/mail', MailWebhookController::class)->name('webhooks.mail')->middleware('throttle:webhook');

    // Search results are noindex (SEO_SPEC.md section 2) and client-rendered,
    // so these are the one catalogue surface consumed at request time rather
    // than at build time.
    Route::middleware('throttle:search')->group(function (): void {
        Route::get('search', SearchController::class)->name('search');
        Route::get('search/suggest', [SearchController::class, 'suggest'])->name('search.suggest');
    });

    // Catch-all paths must come last, and the more specific /products suffix
    // must be declared before the bare path — otherwise 'a/b/products' is
    // captured as a category path called "a/b/products".
    Route::get('categories/{path}/products', [CategoryController::class, 'products'])
        ->where('path', '.*')
        ->name('categories.products');

    Route::get('categories/{path}', [CategoryController::class, 'show'])
        ->where('path', '.*')
        ->name('categories.show');
});
