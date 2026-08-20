<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use OpenApi\Attributes as OA;

/**
 * The full category tree plus featured brands.
 *
 * Feeds the megamenu (section 23.1 rule 7 — the menu is data-driven and
 * reorderable from admin, never hardcoded). Heavily cached: it changes only
 * when the taxonomy does, and it is needed by every page render.
 *
 * Levels 1 and 2 ship as real anchors in the prerendered HTML; level 3 loads
 * on intent (section 23.1 rule 2). The full tree is returned here so category
 * pages can render their own children as crawlable anchors (D-011).
 */
final class NavigationController extends Controller
{
    private const CACHE_KEY = 'api:navigation:v1';

    private const CACHE_TTL = 3600;

    #[OA\Get(
        path: '/api/v1/navigation',
        operationId: 'getNavigation',
        description: 'Full category tree plus featured brands. Feeds the megamenu; heavily cached.',
        summary: 'Navigation tree',
        tags: ['Navigation'],
        responses: [new OA\Response(response: 200, description: 'OK')],
    )]
    public function __invoke(): JsonResponse
    {
        $payload = Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function (): array {
            $categories = Category::query()
                ->orderBy('depth')
                ->orderBy('sort_order')
                ->get(['id', 'uuid', 'parent_id', 'name', 'slug', 'path', 'depth', 'product_count_cached']);

            $brands = [];

            foreach (Brand::featured()->get() as $brand) {
                $brands[] = [
                    'name' => $brand->name,
                    'slug' => $brand->slug,
                    'url' => $brand->url(),
                ];
            }

            return [
                'departments' => $this->tree($categories, null),
                'brands' => $brands,
            ];
        });

        return response()->json(['data' => $payload]);
    }

    /**
     * Builds the tree in memory from a single flat query rather than recursing
     * per node — 124 categories would otherwise be 124 queries.
     *
     * @param  Collection<int, Category>  $all
     * @return list<array<string, mixed>>
     */
    private function tree(Collection $all, ?int $parentId): array
    {
        $nodes = [];

        foreach ($all->where('parent_id', $parentId) as $category) {
            $nodes[] = [
                'name' => $category->name,
                'slug' => $category->slug,
                'url' => $category->url(),
                'depth' => $category->depth,
                'product_count' => $category->product_count_cached,
                'children' => $this->tree($all, $category->id),
            ];
        }

        return $nodes;
    }
}
