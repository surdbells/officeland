<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ProductController extends Controller
{
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
