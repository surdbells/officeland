<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\BrandResource;
use App\Http\Resources\ProductCardResource;
use App\Models\Brand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class BrandController extends Controller
{
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

    public function products(Request $request, Brand $brand): AnonymousResourceCollection
    {
        $products = $brand->products()
            ->active()
            ->with(['brand', 'attributeValues.attribute'])
            ->orderByDesc('published_at')
            // Cursor pagination, not offset: section 8.3 requires it on any
            // list that can exceed 1,000 rows, and offset pagination degrades
            // linearly as a crawler walks deep into a large brand.
            ->cursorPaginate($this->perPage($request));

        return ProductCardResource::collection($products);
    }

    private function perPage(Request $request): int
    {
        return min(max((int) $request->integer('per_page', 24), 1), 50);
    }
}
