<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\BuildsListingResponses;
use App\Http\Controllers\Controller;
use App\Http\Resources\BrandResource;
use App\Http\Resources\ProductCardResource;
use App\Models\Brand;
use App\Search\SearchEngine;
use App\Search\SearchQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class BrandController extends Controller
{
    use BuildsListingResponses;

    public function __construct(private readonly SearchEngine $engine) {}

    public function index(): AnonymousResourceCollection
    {
        return BrandResource::collection(
            Brand::query()->orderBy('name')->get()
        );
    }

    public function show(Brand $brand): JsonResponse
    {
        return response()->json(['data' => new BrandResource($brand)]);
    }

    /**
     * A brand hub listing.
     *
     * This is the surface that replaces the legacy `/product-category/hp/`
     * pattern (defect 4E), where brands were modelled as categories — 35 of
     * 161 taxonomy terms were brand branches. A brand is a facet of the
     * catalogue, not a place in the tree, and it gets its own hub instead.
     *
     * ── Offset pagination, and the §8.3 conflict ─────────────────────────
     * §8.3 requires cursor pagination on any list that can exceed 1,000 rows,
     * because offset pagination degrades linearly as a crawler walks deep.
     * That is right for an API consumer walking a large collection, and wrong
     * here, for a reason §8.3 predates: under D-009 every page of every
     * listing is a prerendered FILE with crawlable numbered anchors, and a
     * cursor cannot produce one. The cursor is opaque, so page 3 is
     * unreachable without first fetching page 2, and no `?page=3` anchor can
     * be rendered at build time.
     *
     * Both requirements survive by serving different consumers: listings
     * intended for humans and crawlers are offset-paginated and bounded (a
     * 5,000-product brand is ~208 pages at 24 a page), while the render
     * worker walks the catalogue through /api/v1/render/manifest, which is
     * not paginated at all. See D-031.
     */
    public function products(Request $request, Brand $brand): JsonResponse
    {
        $query = new SearchQuery(
            filters: $this->filters($request),
            brandId: $brand->id,
            page: max(1, $request->integer('page', 1)),
            perPage: $this->perPage($request),
            sort: (string) $request->query('sort', 'newest'),
        );

        $result = $this->engine->search($query);

        return response()->json([
            'data' => ProductCardResource::collection($result->items),
            'meta' => $this->listingMeta($query, $result),
            'facets' => $this->facetPayload($result),
        ]);
    }
}
