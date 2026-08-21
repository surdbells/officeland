<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\BuildsListingResponses;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProductCardResource;
use App\Search\SearchEngine;
use App\Search\SearchQuery;
use App\Search\SearchQueryLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

final class SearchController extends Controller
{
    use BuildsListingResponses;

    public function __construct(
        private readonly SearchEngine $engine,
        private readonly SearchQueryLogger $logger,
    ) {}

    #[OA\Get(
        path: '/api/v1/search',
        operationId: 'search',
        description: 'Full-text search with trigram fallback, synonym expansion and faceted filtering. Results pages are noindex (SEO_SPEC.md section 2).',
        summary: 'Search products',
        tags: ['Search'],
        parameters: [
            new OA\Parameter(name: 'q', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 24)),
            new OA\Parameter(name: 'sort', in: 'query', schema: new OA\Schema(type: 'string', enum: ['relevance', 'newest', 'name'])),
        ],
        responses: [new OA\Response(response: 200, description: 'OK')],
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $query = new SearchQuery(
            term: (string) $request->query('q', ''),
            filters: $this->filters($request),
            categoryId: $request->integer('category') ?: null,
            brandId: $request->integer('brand') ?: null,
            page: max(1, $request->integer('page', 1)),
            perPage: min(max($request->integer('per_page', 24), 1), 50),
            sort: (string) $request->query('sort', 'relevance'),
        );

        $result = $this->engine->search($query);

        // Logged after the search so the result count is real. Zero-result
        // terms are the point (section 14.10).
        if ($query->hasTerm()) {
            $this->logger->record($query->normalisedTerm(), $result->total);
        }

        return response()->json([
            'data' => ProductCardResource::collection($result->items),
            'meta' => $this->listingMeta($query, $result),
            'facets' => $this->facetPayload($result),
        ]);
    }

    #[OA\Get(
        path: '/api/v1/search/suggest',
        operationId: 'searchSuggest',
        description: 'Typeahead. Grouped into products, categories and brands. Budgeted at p95 <= 50ms.',
        summary: 'Search suggestions',
        tags: ['Search'],
        parameters: [new OA\Parameter(name: 'q', in: 'query', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [new OA\Response(response: 200, description: 'OK')],
    )]
    public function suggest(Request $request): JsonResponse
    {
        $suggestions = $this->engine->suggest((string) $request->query('q', ''));

        return response()->json([
            'data' => [
                'products' => $suggestions->products,
                'categories' => $suggestions->categories,
                'brands' => $suggestions->brands,
            ],
            'meta' => ['duration_ms' => $suggestions->durationMs],
        ]);
    }
}
