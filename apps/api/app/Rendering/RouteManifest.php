<?php

declare(strict_types=1);

namespace App\Rendering;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Every route the storefront should publish (D-009).
 *
 * Used by the /api/v1/render/manifest endpoint, which the `ng build`
 * prerender reads, and directly by `render:full`, which runs inside this
 * application and has no reason to call itself over HTTP.
 *
 * One implementation, because the full build and the prerender publishing
 * different sets of routes is precisely the failure that leaves pages with no
 * file behind them.
 */
final class RouteManifest
{
    /**
     * Must match the storefront listing page size, and the default in
     * BuildsListingResponses::perPage(). If they disagree, the manifest names
     * a different number of pages than the listing links to.
     */
    private const PER_PAGE = 24;

    /**
     * Product slugs with a page. `active`, not `indexable`: a discontinued or
     * draft product has no page at all, but a live product held back by the
     * section 14.7 content floor still needs one to link to.
     *
     * @return list<string>
     */
    public function productSlugs(): array
    {
        /** @var list<string> $slugs */
        $slugs = array_values(Product::query()->active()->orderBy('slug')->pluck('slug')->all());

        return $slugs;
    }

    /**
     * Every route, already prefixed and slash-terminated.
     *
     * @return list<string>
     */
    public function routes(): array
    {
        // The static routes have no data behind them but still need files.
        $routes = ['/', '/c/', '/brands/', '/search/', '/404/'];

        foreach ($this->categoryRoutes() as $path) {
            $routes[] = '/c/'.trim($path, '/').'/';
        }

        foreach ($this->brandRoutes() as $path) {
            $routes[] = '/brands/'.trim($path, '/').'/';
        }

        foreach ($this->productSlugs() as $slug) {
            $routes[] = '/p/'.trim($slug, '/').'/';
        }

        return array_values(array_unique($routes));
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
     * category would be unreachable - failing the crawl-depth gate for
     * exactly the large categories that need it most.
     *
     * ── One query, not 248 ───────────────────────────────────────────────
     * The obvious implementation loops the categories and counts each subtree,
     * which is two queries per category. At 124 categories that already
     * exceeded the 30-second request limit; at the full taxonomy against
     * 15,000 products it would be hopeless, and this endpoint is on the
     * critical path of every build.
     *
     * The self-join on the materialized path does the subtree expansion in
     * SQL. `count(distinct)` is load-bearing: a product filed against both a
     * parent and one of its children appears twice in the join, and summing
     * per-category counts would over-report the page count and render an
     * empty trailing page.
     *
     * @return list<string>
     */
    public function categoryRoutes(): array
    {
        $totals = DB::table('categories as ancestor')
            ->join('categories as descendant', function ($join): void {
                $join->whereColumn('descendant.path', 'like', DB::raw("ancestor.path || '%'"));
            })
            ->join('product_category as pc', 'pc.category_id', '=', 'descendant.id')
            ->join('products as p', 'p.id', '=', 'pc.product_id')
            ->where('p.status', 'active')
            ->whereNull('p.deleted_at')
            ->whereNull('descendant.deleted_at')
            ->groupBy('ancestor.path')
            ->selectRaw('ancestor.path as path, count(distinct pc.product_id) as total')
            ->pluck('total', 'path');

        $routes = [];

        foreach (Category::query()->orderBy('path')->pluck('path') as $path) {
            $base = trim((string) $path, '/');
            $routes[] = $base;

            $pages = (int) ceil(((int) ($totals[$path] ?? 0)) / self::PER_PAGE);

            for ($page = 2; $page <= $pages; $page++) {
                $routes[] = $base.'/page/'.$page;
            }
        }

        return $routes;
    }

    /**
     * Every brand hub, plus a `page/N` route per additional page.
     *
     * Brand hubs replace the legacy `/product-category/hp/` pattern (defect
     * 4E) and are a redirect destination, so a missing hub is a broken
     * redirect rather than merely a missing page.
     *
     * @return list<string>
     */
    public function brandRoutes(): array
    {
        $totals = Product::query()
            ->active()
            ->whereNotNull('brand_id')
            ->groupBy('brand_id')
            ->selectRaw('brand_id, count(*) as total')
            ->pluck('total', 'brand_id');

        $routes = [];

        foreach (Brand::query()->orderBy('slug')->get(['id', 'slug']) as $brand) {
            $slug = (string) $brand->slug;
            $routes[] = $slug;

            $pages = (int) ceil(((int) ($totals[$brand->id] ?? 0)) / self::PER_PAGE);

            for ($page = 2; $page <= $pages; $page++) {
                $routes[] = $slug.'/page/'.$page;
            }
        }

        return $routes;
    }
}
