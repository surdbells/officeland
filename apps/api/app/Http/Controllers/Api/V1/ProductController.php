<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\BuildsListingResponses;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProductCardResource;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Search\SearchEngine;
use App\Search\SearchQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ProductController extends Controller
{
    use BuildsListingResponses;

    public function __construct(private readonly SearchEngine $engine) {}

    /**
     * The whole catalogue as one listing - the shop page.
     *
     * Same engine, same envelope and same facets as a category listing, with
     * the category filter left out. That is the entire difference, and it is
     * deliberately the entire difference: a second implementation of subtree
     * resolution, attribute filtering and facet counting is a second thing to
     * disagree with the first.
     *
     * Why it exists at all when every category already has a page: a buyer who
     * knows they want an HP anything, or everything under ₦X once prices
     * arrive, has no category to start from. Faceting across the catalogue is
     * the only route to that, and the reference site has no equivalent.
     *
     * Noindex is set by the storefront, not here (SEO_SPEC §10.3): an
     * unfiltered listing of the whole catalogue competes with the category
     * pages that carry the copy, and filtered permutations are the classic
     * infinite crawl space.
     */
    public function index(Request $request): JsonResponse
    {
        $query = new SearchQuery(
            filters: $this->filters($request),
            categoryId: null,
            brandId: $request->integer('brand') ?: null,
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

    /**
     * Full PDP payload.
     *
     * Everything the page needs in one request: the product, its brand, its
     * primary category for the breadcrumb, grouped specifications, variants
     * and typed relations. Under D-009 this is consumed at build time by the
     * render worker, so one complete payload beats several round trips.
     */
    #[OA\Get(
        path: '/api/v1/products/{slug}',
        operationId: 'getProduct',
        description: 'Full PDP payload: brand, primary category, breadcrumb, grouped specifications, variants and typed relations.',
        summary: 'Product detail',
        tags: ['Products'],
        parameters: [new OA\Parameter(name: 'slug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'OK'),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        ],
    )]
    public function show(string $slug): JsonResponse
    {
        $product = Product::query()
            ->where('slug', $slug)
            ->active()
            ->with([
                'brand',
                'primaryCategory',
                'variants',
                'attributeValues.attribute.group',
                'media.derivatives',
                // Related cards need their own primary image, so the rail is not
                // a row of grey boxes. Bounded at 24 either way.
                'relatedProducts' => fn ($q) => $q->active()
                    ->with(['brand', 'media.derivatives'])
                    ->limit(24),
            ])
            ->first();

        if ($product === null) {
            throw new NotFoundHttpException("No product with slug {$slug}");
        }

        return response()->json(['data' => new ProductResource($product)]);
    }
}
