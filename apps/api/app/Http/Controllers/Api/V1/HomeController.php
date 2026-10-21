<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ImageResource;
use App\Http\Resources\ProductCardResource;
use App\Models\HeroSlide;
use App\Models\HomepageCategoryPick;
use App\Models\HomepageProductPick;
use App\Models\Media;
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
        ]]);
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
