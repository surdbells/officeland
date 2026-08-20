<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\BrandController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\NavigationController;
use App\Http\Controllers\Api\V1\ProductController;
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

Route::prefix('v1')->name('api.')->group(function (): void {
    Route::get('navigation', NavigationController::class)->name('navigation');

    Route::get('brands', [BrandController::class, 'index'])->name('brands.index');
    Route::get('brands/{brand}', [BrandController::class, 'show'])->name('brands.show');
    Route::get('brands/{brand}/products', [BrandController::class, 'products'])->name('brands.products');

    Route::get('categories', [CategoryController::class, 'index'])->name('categories.index');

    Route::get('products/{slug}', [ProductController::class, 'show'])->name('products.show');

    // Search results are noindex (SEO_SPEC.md section 2) and client-rendered,
    // so these are the one catalogue surface consumed at request time rather
    // than at build time.
    Route::get('search', SearchController::class)->name('search');
    Route::get('search/suggest', [SearchController::class, 'suggest'])->name('search.suggest');

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
