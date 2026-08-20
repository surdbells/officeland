<?php

declare(strict_types=1);

namespace App\Rendering;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Model;

/**
 * Maps a domain change to the set of static routes it invalidates.
 *
 * This is gap G7, and under D-009 it is the load-bearing piece of the whole
 * architecture. The storefront is prerendered to disk, so correctness comes
 * entirely from regenerating the right routes — there is no TTL to fall back
 * on. Resolve too few and the site serves stale pages indefinitely; resolve
 * too many and a bulk import regenerates the catalogue several times over.
 *
 * See docs/ARCHITECTURE.md section 4.3.
 */
final class RouteResolver
{
    /**
     * Beyond this many cascade routes, enqueueing individually is worse than
     * rebuilding the affected subtree. Re-categorising a department would
     * otherwise fan out across thousands of pages.
     */
    public const CASCADE_CAP = 250;

    /** Products per listing page. Determines which paginated page a product lands on. */
    public const PER_PAGE = 24;

    /**
     * @return list<RenderRequest>
     */
    public function resolve(Model $model): array
    {
        return match (true) {
            $model instanceof Product => $this->forProduct($model),
            $model instanceof Category => $this->forCategory($model),
            $model instanceof Brand => $this->forBrand($model),
            default => [],
        };
    }

    /**
     * @return list<RenderRequest>
     */
    private function forProduct(Product $product): array
    {
        $direct = [$product->url()];

        // Every category the product appears in, plus the ancestors of each —
        // a listing page shows products from its whole subtree, so a change
        // deep in the tree is visible further up.
        foreach ($product->categories()->get() as $category) {
            $direct[] = $category->url();

            foreach ($category->ancestors() as $ancestor) {
                $direct[] = $ancestor->url();
            }
        }

        if ($product->primaryCategory !== null) {
            $direct[] = $product->primaryCategory->url();
        }

        if ($product->brand !== null) {
            $direct[] = $product->brand->url();
        }

        // Sitemap lastmod changes whenever an indexable product changes.
        if ($product->is_indexable) {
            $direct[] = '/sitemap-products.xml';
        }

        $requests = [new RenderRequest(
            $this->normalise($direct),
            RenderRequest::PRIORITY_DIRECT,
            "product:{$product->slug}",
        )];

        $cascade = $this->cascadeForProduct($product);

        if (! $cascade->isEmpty()) {
            $requests[] = $cascade;
        }

        return $requests;
    }

    /**
     * Pages whose related-product rails display this product.
     *
     * Rails are baked into the HTML deliberately — section 14.7 counts them as
     * internal linking, and internal linking is a primary ranking asset for a
     * domain with no accumulated authority. Rendering them client-side would
     * make them invisible to crawlers.
     *
     * The cost is this cascade, which is therefore bounded and deprioritised
     * rather than eliminated.
     */
    private function cascadeForProduct(Product $product): RenderRequest
    {
        $referrers = $product->relatedFrom()
            ->select('products.slug')
            ->limit(self::CASCADE_CAP + 1)
            ->pluck('slug')
            ->all();

        if (count($referrers) > self::CASCADE_CAP) {
            // Escalate rather than enqueue thousands of individual routes.
            // The renderer treats a subtree marker as "rebuild everything
            // below this prefix" (Phase 8).
            return new RenderRequest(
                ['/p/**'],
                RenderRequest::PRIORITY_CASCADE,
                "product:{$product->slug} exceeded cascade cap; escalated to subtree rebuild",
            );
        }

        return new RenderRequest(
            $this->normalise(array_map(static fn (string $slug): string => "/p/{$slug}/", $referrers)),
            RenderRequest::PRIORITY_CASCADE,
            "related rails referencing product:{$product->slug}",
        );
    }

    /**
     * @return list<RenderRequest>
     */
    private function forCategory(Category $category): array
    {
        $routes = [$category->url()];

        // The parent renders this category in its child list (D-011), so a
        // rename or reorder changes the parent page too.
        foreach ($category->ancestors() as $ancestor) {
            $routes[] = $ancestor->url();
        }

        // Children carry the breadcrumb, which includes this category's name.
        foreach ($category->children as $child) {
            $routes[] = $child->url();
        }

        $routes[] = '/c/';
        $routes[] = '/sitemap-categories.xml';

        $requests = [new RenderRequest(
            $this->normalise($routes),
            RenderRequest::PRIORITY_DIRECT,
            "category:{$category->path}",
        )];

        // Every product in the subtree carries this category in its
        // breadcrumb. Bounded the same way as the related-rail cascade.
        $productCount = $category->products()->count();

        if ($productCount > 0) {
            $requests[] = $productCount > self::CASCADE_CAP
                ? new RenderRequest(
                    ['/p/**'],
                    RenderRequest::PRIORITY_CASCADE,
                    "category:{$category->path} affects {$productCount} products; escalated",
                )
                : new RenderRequest(
                    $this->normalise(
                        $category->products()->pluck('slug')
                            ->map(static fn (string $slug): string => "/p/{$slug}/")
                            ->all()
                    ),
                    RenderRequest::PRIORITY_CASCADE,
                    "breadcrumbs under category:{$category->path}",
                );
        }

        return $requests;
    }

    /**
     * @return list<RenderRequest>
     */
    private function forBrand(Brand $brand): array
    {
        $requests = [new RenderRequest(
            $this->normalise([$brand->url(), '/brands/', '/sitemap-brands.xml']),
            RenderRequest::PRIORITY_DIRECT,
            "brand:{$brand->slug}",
        )];

        // The brand name is rendered on every one of its product pages.
        $count = $brand->products()->count();

        if ($count > 0) {
            $requests[] = $count > self::CASCADE_CAP
                ? new RenderRequest(
                    ['/p/**'],
                    RenderRequest::PRIORITY_CASCADE,
                    "brand:{$brand->slug} affects {$count} products; escalated",
                )
                : new RenderRequest(
                    $this->normalise(
                        $brand->products()->pluck('slug')
                            ->map(static fn (string $slug): string => "/p/{$slug}/")
                            ->all()
                    ),
                    RenderRequest::PRIORITY_CASCADE,
                    "products of brand:{$brand->slug}",
                );
        }

        return $requests;
    }

    /**
     * Deduplicate and order.
     *
     * A product in three categories sharing two ancestors would otherwise
     * enqueue the same listing page several times per edit.
     *
     * @param  array<int, string>  $routes
     * @return list<string>
     */
    private function normalise(array $routes): array
    {
        $unique = array_unique(array_filter($routes));
        sort($unique);

        /** @var list<string> $unique */
        return $unique;
    }
}
