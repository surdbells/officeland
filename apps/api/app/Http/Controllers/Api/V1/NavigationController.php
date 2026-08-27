<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ImageResource;
use App\Models\Category;
use App\Models\Media;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

/**
 * The full category tree plus featured brands.
 *
 * Feeds the megamenu (section 23.1 rule 7 - the menu is data-driven and
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

    /** A panel footer is a signpost, not a directory. */
    private const BRANDS_PER_DEPARTMENT = 8;

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

            return [
                'departments' => $this->tree($categories, null, $this->brandsByDepartment()),
            ];
        });

        return response()->json(['data' => $payload]);
    }

    /**
     * Builds the tree in memory from a single flat query rather than recursing
     * per node - 170 categories would otherwise be 170 queries.
     *
     * @param  Collection<int, Category>  $all
     * @param  array<string, list<array<string, mixed>>>  $brands
     * @return list<array<string, mixed>>
     */
    private function tree(Collection $all, ?int $parentId, array $brands = []): array
    {
        $nodes = [];

        foreach ($all->where('parent_id', $parentId) as $category) {
            $node = [
                'name' => $category->name,
                'slug' => $category->slug,
                'url' => $category->url(),
                'depth' => $category->depth,
                'product_count' => $category->product_count_cached,
                'children' => $this->tree($all, $category->id, $brands),
            ];

            // Only departments carry a brands row; it is a panel footer, and
            // only departments have panels.
            if ($category->depth === 1) {
                $node['brands'] = $brands[$category->path] ?? [];
            }

            $nodes[] = $node;
        }

        return $nodes;
    }

    /**
     * The brands a department actually carries, keyed by department path.
     *
     * ── Why this replaced one global list ────────────────────────────────
     * The megamenu showed the same six featured brands on every panel, so
     * "Office Furniture" advertised HP, Canon, Epson and Kingston. That is
     * worse than showing nothing: a brands row is a claim about what is
     * behind the link, and this one was contradicted by the products.
     *
     * ── Derived, not curated ─────────────────────────────────────────────
     * A brand belongs on a department's panel when it has an active product
     * filed somewhere in that department's subtree. Nothing to maintain and
     * nothing to go stale - a brand added to the catalogue appears, and one
     * whose last product is retired disappears, without anybody remembering.
     *
     * The alternative was a brand-to-department mapping table. It gives
     * editorial control over an empty department, and buys it with a second
     * source of truth that starts drifting the day the catalogue changes.
     *
     * A department with no products yields no brands, and the storefront
     * renders no row. That is the honest state of a catalogue mid-import,
     * and it corrects itself as products land rather than needing a revisit.
     *
     * ── One query for every department ───────────────────────────────────
     * Nine departments, and a per-department query would be nine round trips
     * inside a response every page render depends on. The join carries the
     * department path down with the brand so PHP can group it, and the whole
     * thing is inside the same hour-long cache as the tree.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function brandsByDepartment(): array
    {
        $rows = DB::table('brands')
            ->join('products', 'products.brand_id', '=', 'brands.id')
            ->join('product_category', 'product_category.product_id', '=', 'products.id')
            ->join('categories', 'categories.id', '=', 'product_category.category_id')
            // The department is the first path segment, and every descendant
            // path starts with it - so the subtree comes from a prefix match
            // on the materialized path rather than a recursive walk.
            ->join('categories as departments', function ($join): void {
                $join->on('categories.path', 'like', DB::raw("departments.path || '%'"))
                    ->where('departments.depth', '=', 1);
            })
            ->whereNull('brands.deleted_at')
            ->whereNull('products.deleted_at')
            // Matches Product::scopeActive; a draft or archived product must
            // not put its brand on a panel.
            ->where('products.status', 'active')
            ->select([
                'departments.path as department_path',
                'brands.name',
                'brands.slug',
                'brands.sort_order',
                'brands.logo_media_id',
            ])
            ->distinct()
            ->orderBy('departments.path')
            ->orderBy('brands.sort_order')
            ->orderBy('brands.name')
            ->get();

        /** @var list<int> $ids */
        $ids = array_values(array_unique(array_map(
            static fn ($value): int => (int) $value,
            array_filter($rows->pluck('logo_media_id')->all()),
        )));

        $logos = $this->logos($ids);

        $grouped = [];

        foreach ($rows as $row) {
            $path = (string) $row->department_path;

            // A panel is a signpost, not a directory. Past about eight the row
            // wraps and stops being scannable, and the brand hub index is one
            // click away for the rest.
            if (count($grouped[$path] ?? []) >= self::BRANDS_PER_DEPARTMENT) {
                continue;
            }

            $grouped[$path][] = [
                'name' => $row->name,
                'slug' => $row->slug,
                'url' => '/brands/'.$row->slug.'/',
                'logo' => $logos[$row->logo_media_id] ?? null,
            ];
        }

        return $grouped;
    }

    /**
     * Brand logos, shaped like every other image on the site.
     *
     * `logo_media_id` has been a column since Phase 2 with nothing reading it,
     * exactly as `hero_media_id` was. Null until somebody uploads one, and the
     * storefront falls back to the brand name as text - which is what it has
     * always shown, so a missing logo costs nothing.
     *
     * @param  list<int>  $ids
     * @return array<int, array<string, mixed>>
     */
    private function logos(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $logos = [];

        foreach (Media::query()->with('derivatives')->whereKey($ids)->get() as $medium) {
            $logos[$medium->id] = (new ImageResource($medium))->resolve();
        }

        return $logos;
    }
}
