<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\ProductCardResource;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class CategoryController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return CategoryResource::collection(
            Category::query()->depth(1)->orderBy('sort_order')->with('children')->get()
        );
    }

    public function show(string $path): JsonResponse
    {
        $category = $this->resolve($path);
        $category->load('children');

        return response()->json(['data' => new CategoryResource($category)]);
    }

    /**
     * Products in a category, including its whole subtree.
     *
     * A department listing must show products filed against its leaves —
     * otherwise /c/office-furniture/ appears empty while every chair sits three
     * levels down.
     */
    public function products(Request $request, string $path): AnonymousResourceCollection
    {
        $category = $this->resolve($path);

        $descendantIds = Category::query()
            ->subtreeOf($category->path)
            ->pluck('id');

        $products = Product::query()
            ->active()
            ->whereHas('categories', fn ($q) => $q->whereIn('categories.id', $descendantIds))
            ->with(['brand', 'attributeValues.attribute'])
            ->orderByDesc('published_at')
            ->cursorPaginate($this->perPage($request));

        return ProductCardResource::collection($products);
    }

    /**
     * Resolves a category from its materialized path.
     *
     * The route delivers 'office-furniture/seating', while the stored path is
     * '/office-furniture/seating/' — normalising here keeps the slash
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

    private function perPage(Request $request): int
    {
        return min(max((int) $request->integer('per_page', 24), 1), 50);
    }
}
