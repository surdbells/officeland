<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\BuildsListingResponses;
use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\ProductCardResource;
use App\Models\Category;
use App\Search\SearchEngine;
use App\Search\SearchQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class CategoryController extends Controller
{
    use BuildsListingResponses;

    public function __construct(private readonly SearchEngine $engine) {}

    public function index(): AnonymousResourceCollection
    {
        return CategoryResource::collection(
            Category::query()->depth(1)->orderBy('sort_order')->with('children')->get()
        );
    }

    public function show(string $path): JsonResponse
    {
        $category = $this->resolve($path);
        // The hero and its derivatives in one go: ImageResource builds srcsets
        // from media_derivatives, and without them it would emit a bare src.
        // Two levels of children, not one.
        //
        // A department that lists only its subcategories puts every leaf 2
        // clicks from home and every product 4 - which breaks the 3-click
        // guarantee (D-011, SEO_SPEC 10.4) the moment a catalogue is big
        // enough that products stop fitting on page 1 of an ancestor listing.
        // With ten products in the database it passed; with a real catalogue
        // 224 products were 4 clicks deep.
        //
        // `parent.children` is for the sibling row a leaf page shows in place
        // of children it does not have. Loaded here rather than fetched by the
        // storefront: it is one query alongside the others, and the navigation
        // payload the client already holds does not carry hero images.
        $category->load([
            'children.children',
            'children.heroMedia.derivatives',
            'parent.children.heroMedia.derivatives',
            'heroMedia.derivatives',
        ]);

        return response()->json(['data' => (new CategoryResource($category))->asSubject()]);
    }

    /**
     * Products in a category, including its whole subtree.
     *
     * A department listing must show products filed against its leaves -
     * otherwise /c/office-furniture/ appears empty while every chair sits three
     * levels down.
     *
     * ── Why this runs through SearchEngine ───────────────────────────────
     * A listing and a search result are the same question with a different
     * filter, and §16 puts facets behind the engine. Building the PLP on a
     * separate Eloquent query would mean two implementations of subtree
     * resolution, attribute filtering and facet counting, which would disagree
     * the first time either changed.
     *
     * ── Why page-based and not cursor pagination ─────────────────────────
     * Cursor pagination cannot produce a crawlable `?page=2`: the cursor is
     * opaque, so page 3 is unreachable without first fetching page 2, and no
     * anchor can be rendered for it at build time. Under D-009 every page of
     * every listing is a prerendered file, so the pagination must be
     * addressable by number. Offset pagination is the cost of that, and the
     * `products_active_idx` index carries it at this catalogue size.
     */
    public function products(Request $request, string $path): JsonResponse
    {
        $category = $this->resolve($path);

        $query = new SearchQuery(
            filters: $this->filters($request),
            categoryId: $category->id,
            brandId: $request->integer('brand') ?: null,
            page: max(1, $request->integer('page', 1)),
            perPage: $this->perPage($request),
            // Relevance is meaningless without a term; newest-first is the
            // listing default and the only order that is stable across pages.
            sort: (string) $request->query('sort', 'newest'),
        );

        $result = $this->engine->search($query);

        return response()->json([
            'data' => ProductCardResource::collection($result->items),
            'meta' => $this->listingMeta($query, $result),
            'facets' => $this->facetPayload($result),
        ]);
    }

    /**
     * Resolves a category from its materialized path.
     *
     * The route delivers 'office-furniture/seating', while the stored path is
     * '/office-furniture/seating/' - normalising here keeps the slash
     * convention in exactly one place.
     */
    private function resolve(string $path): Category
    {
        $normalised = '/'.trim($path, '/').'/';

        $category = Category::query()->where('path', $normalised)->first();

        if ($category === null) {
            throw new NotFoundHttpException("No category at path {$normalised}");
        }

        return $category;
    }
}
