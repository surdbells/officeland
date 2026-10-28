<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ImageResource;
use App\Http\Resources\ProductCardResource;
use App\Models\Brand;
use App\Models\Category;
use App\Models\HeroSlide;
use App\Models\HomepageCategoryPick;
use App\Models\HomepageProductPick;
use App\Models\Media;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * Editorial content for the homepage.
 *
 * Everything the merchandising team curates - the hero carousel, the "shop by
 * category" grid, the "best sellers" band - lives here, managed from Filament.
 * Each section is optional: an empty array is the signal for the storefront to
 * keep the derived behaviour it has always had (department-derived hero,
 * built-in category list, first-page-of-catalogue band). So an uncurated site
 * looks exactly as it does today, and each section switches to curated content
 * the moment a row is published.
 *
 * Like the rest of the catalogue API this is consumed mainly by the build-time
 * render worker (D-009), so the handful of queries here run once per build, not
 * per visitor. It is deliberately NOT cached: a build is the only reader, and a
 * stale cache would mean an admin edit does not show until the TTL expires.
 *
 * The announcement bar is global chrome, not homepage content, so it rides on
 * the navigation payload (NavigationController) that every page already loads -
 * not here.
 */
final class HomeController extends Controller
{
    /** Products shown in each department rail on the homepage. */
    private const RAIL_SIZE = 12;

    #[OA\Get(
        path: '/api/v1/home',
        operationId: 'getHome',
        description: 'Curated homepage content. Empty sections mean the storefront keeps its built-in derived behaviour.',
        summary: 'Homepage content',
        tags: ['Home'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'OK',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', ref: '#/components/schemas/Home'),
                ], type: 'object'),
            ),
        ],
    )]
    public function __invoke(): JsonResponse
    {
        return response()->json(['data' => [
            'hero_slides' => $this->heroSlides(),
            'category_picks' => $this->categoryPicks(),
            'product_picks' => $this->productPicks(),
            'brands' => $this->brands(),
            'department_rails' => $this->departmentRails(),
        ]]);
    }

    /**
     * Every brand, as a card with its logo and name.
     *
     * The homepage brand rail represents the whole roster, not a curated few,
     * so a buyer can see at a glance that OfficeLand carries their brand.
     * Ordered by how much the brand actually contributes (live product count),
     * then by name, so the brands with real depth lead. logo_media_id is shaped
     * like every other image; a brand without a logo yet falls back to its name.
     *
     * @return list<array<string, mixed>>
     */
    private function brands(): array
    {
        return Brand::query()
            ->with('logoMedia.derivatives')
            ->orderByDesc('product_count_cached')
            ->orderBy('name')
            ->get()
            ->map(fn (Brand $brand): array => [
                'name' => $brand->name,
                'slug' => $brand->slug,
                'url' => $brand->url(),
                'product_count' => (int) $brand->product_count_cached,
                'logo' => $this->image($brand->logoMedia),
            ])
            ->all();
    }

    /**
     * A product rail for every department that has live products.
     *
     * One rail per depth-1 category, each carrying the department's square card
     * image and up to RAIL_SIZE active, image-backed products from anywhere in
     * its subtree (newest first). A department with no live product yields no
     * rail rather than an empty one - honest mid-import, and self-correcting as
     * products land. Nine small queries, run once per build (D-009), not per
     * visitor.
     *
     * @return list<array<string, mixed>>
     */
    private function departmentRails(): array
    {
        $departments = Category::query()
            ->where('depth', 1)
            ->orderBy('sort_order')
            ->with(['cardMedia.derivatives', 'heroMedia.derivatives'])
            ->get();

        $rails = [];

        foreach ($departments as $department) {
            // Filter on primary_category_id (a product's own department) rather
            // than category membership through the pivot. The pivot form -
            // whereHas('categories', path LIKE ...) + ORDER BY ... LIMIT - has no
            // supporting index, so at catalogue scale the planner scans a long
            // way to collect a dozen rows; nine of those per homepage summed past
            // the request time limit. This form uses the partial index
            // products_active_idx (primary_category_id, published_at DESC) WHERE
            // status='active', and a product's primary category IS its department.
            $subtreeIds = Category::query()->subtreeOf($department->path)->pluck('id');

            $products = Product::query()
                ->active()
                ->whereIn('primary_category_id', $subtreeIds)
                // A rail of broken images is worse than no rail: only products
                // whose primary image has generated derivatives qualify. Active
                // products almost always have one (publishing requires it), so
                // this EXISTS is cheap here.
                ->whereHas('media', fn (Builder $q) => $q->whereHas('derivatives'))
                ->with(['brand', 'media.derivatives', 'attributeValues.attribute'])
                ->latest('published_at')
                ->limit(self::RAIL_SIZE)
                ->get();

            if ($products->isEmpty()) {
                continue;
            }

            $rails[] = [
                'name' => $department->name,
                'url' => $department->url(),
                'product_count' => (int) $department->product_count_cached,
                'image' => $this->image($department->cardMedia ?? $department->heroMedia),
                'products' => ProductCardResource::collection($products)->resolve(),
            ];
        }

        return $rails;
    }

    /**
     * Active hero slides, each with its own photograph and copy.
     *
     * @return list<array<string, mixed>>
     */
    private function heroSlides(): array
    {
        return HeroSlide::query()
            ->active()
            ->with('media.derivatives')
            ->get()
            ->map(fn (HeroSlide $slide): array => [
                'headline' => $slide->headline,
                'subheadline' => $slide->subheadline,
                'cta_label' => $slide->cta_label,
                'cta_url' => $slide->cta_url,
                'secondary_cta_label' => $slide->secondary_cta_label,
                'secondary_cta_url' => $slide->secondary_cta_url,
                'image' => $this->image($slide->media),
            ])
            ->all();
    }

    /**
     * The "shop by category" tiles.
     *
     * The count comes from the category's cached total; the image is the pick's
     * own override if set, otherwise the category's hero. A pick whose category
     * has been deleted is skipped rather than rendered as a dead tile.
     *
     * @return list<array<string, mixed>>
     */
    private function categoryPicks(): array
    {
        return HomepageCategoryPick::query()
            ->active()
            ->with(['category.heroMedia.derivatives', 'media.derivatives'])
            ->get()
            ->filter(fn (HomepageCategoryPick $pick): bool => $pick->category !== null)
            ->map(fn (HomepageCategoryPick $pick): array => [
                'name' => $pick->label ?? $pick->category->name,
                'url' => $pick->category->url(),
                'product_count' => (int) $pick->category->product_count_cached,
                'image' => $this->image($pick->media ?? $pick->category->heroMedia),
            ])
            ->values()
            ->all();
    }

    /**
     * The curated "best sellers" band.
     *
     * Only active products appear - a pick whose product was archived or
     * deleted silently drops out rather than linking to a dead page.
     *
     * @return array<int, array<string, mixed>>
     */
    private function productPicks(): array
    {
        $products = HomepageProductPick::query()
            ->active()
            ->with([
                'product.brand',
                'product.media.derivatives',
                'product.attributeValues.attribute',
            ])
            ->get()
            ->map(fn (HomepageProductPick $pick) => $pick->product)
            ->filter(fn ($product): bool => $product !== null && $product->status === 'active')
            ->values();

        return ProductCardResource::collection($products)->resolve();
    }

    /** Shapes a Media row like every other image on the site, or null. */
    private function image(?Media $media): ?array
    {
        if ($media === null) {
            return null;
        }

        return (new ImageResource($media))->resolve();
    }
}
