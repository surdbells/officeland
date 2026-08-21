<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\JsonResponse;

/**
 * The complete list of routes the storefront prerenders (D-009).
 *
 * Consumed by apps/web/src/app/core/rendering/route-manifest.ts during
 * `ng build`. It is the full-rebuild input; Phase 8 adds the incremental path,
 * where a publish enqueues only the routes RouteResolver names.
 *
 * ── Rendered is not the same as indexed ──────────────────────────────────
 * This deliberately does NOT filter on `is_indexable`. Three separate
 * questions get conflated otherwise:
 *
 *   does the file exist?   → this manifest
 *   does it say noindex?   → SeoService, from the `is_indexable` column
 *   is it in the sitemap?  → Phase 14, which DOES filter on is_indexable
 *
 * Filtering here would 404 links the site itself publishes. Every category in
 * the tree is linked from the megamenu and from its parent's child list
 * (D-011), and every taxonomy category currently seeds with
 * `is_indexable = false` pending intro copy. An indexable-only manifest would
 * therefore render almost nothing while the header linked to all 124 of them —
 * failing the orphan gate and the crawl-depth gate at once, and turning an
 * editorial state ("no intro copy yet") into a broken link.
 *
 * The relationship that must hold is one-directional: sitemap ⊆ manifest. A
 * sitemap URL with no file behind it is a 404 reported to Search Console.
 *
 * ── Why this is not paginated ────────────────────────────────────────────
 * Every other collection endpoint is cursor-paginated. This one is not,
 * because a partial manifest is indistinguishable from a complete one at the
 * point of use: the build would succeed and silently publish a subset of the
 * catalogue. At 15,000 products this is roughly 600 KB of slugs, requested
 * once per build by one client on the local network.
 *
 * ── Why the query is not `select *` ──────────────────────────────────────
 * Only the slug and path columns are read. Hydrating 15,000 models to
 * concatenate two strings each is the difference between a request that costs
 * nothing and one that costs a hundred megabytes.
 */
final class RenderManifestController
{
    /**
     * Must match the storefront listing page size, and the default in
     * BuildsListingResponses::perPage(). If they disagree, the manifest
     * renders a different number of pages than the listing links to.
     */
    private const PER_PAGE = 24;

    public function __invoke(): JsonResponse
    {
        // `active`, not `indexable`: a discontinued or draft product has no
        // page at all, but a live product held back by the section 14.7
        // content floor still needs one to link to.
        $products = Product::query()
            ->active()
            ->orderBy('slug')
            ->pluck('slug')
            ->all();

        $categories = $this->categoryRoutes();

        return response()->json([
            'data' => [
                'categories' => $categories,
                'products' => $products,
            ],
            'meta' => [
                'category_count' => count($categories),
                'product_count' => count($products),
            ],
        ]);
    }

    /**
     * Every category path, plus a `page/N` route for each additional page of
     * its listing.
     *
     * Pagination is a path segment rather than a query parameter (D-032),
     * because Angular prerenders paths and cannot emit a file for a query
     * string. Without these routes the pagination anchors the listing renders
     * would point at files that do not exist: the static host would answer
     * page 2 with the 404 template, and every product past the first 24 in a
     * category would be unreachable — failing the crawl-depth gate for
     * exactly the large categories that need it most.
     *
     * Counted per subtree, matching what CategoryController@products lists.
     *
     * @return list<string>
     */
    private function categoryRoutes(): array
    {
        $categories = Category::query()->orderBy('path')->get(['id', 'path']);

        $routes = [];

        foreach ($categories as $category) {
            $base = trim((string) $category->path, '/');
            $routes[] = $base;

            $descendantIds = Category::query()
                ->subtreeOf((string) $category->path)
                ->pluck('id');

            $total = Product::query()
                ->active()
                ->whereHas('categories', fn ($q) => $q->whereIn('categories.id', $descendantIds))
                ->count();

            $pages = (int) ceil($total / self::PER_PAGE);

            for ($page = 2; $page <= $pages; $page++) {
                $routes[] = $base.'/page/'.$page;
            }
        }

        return $routes;
    }
}
