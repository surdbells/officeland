<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Category
 */
final class CategoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'slug' => $this->slug,
            'path' => $this->path,
            'url' => $this->url(),
            'depth' => $this->depth,
            'intro_copy' => $this->intro_copy,
            'description_html' => $this->description_html,

            /*
             * The hero image, when the caller loaded it.
             *
             * `whenLoaded` rather than an unconditional read: the megamenu
             * asks for the whole tree, and a lazy load per node there would
             * be 170 queries to render a menu.
             *
             * Null when a category has no image — which is every category
             * today. The storefront renders its typographic treatment in
             * that case rather than a broken frame, so uploading an image is
             * a pure improvement and never a prerequisite.
             */
            'hero' => $this->whenLoaded(
                'heroMedia',
                fn (): ?ImageResource => $this->heroMedia === null
                    ? null
                    : new ImageResource($this->heroMedia),
            ),
            'product_count' => $this->product_count_cached,
            // Children ship as real data so the storefront can render them as
            // crawlable anchors on every category page (D-011) — that is what
            // makes every product reachable within 3 clicks.
            'children' => CategoryResource::collection($this->whenLoaded('children')),
            'breadcrumb' => $this->when(
                $request->routeIs('api.categories.show'),
                fn (): array => $this->ancestors()
                    ->map(fn (Category $a): array => ['name' => $a->name, 'url' => $a->url()])
                    ->values()
                    ->all(),
            ),
            'seo' => [
                'meta_title' => $this->meta_title,
                'meta_description' => $this->meta_description,
                'canonical_override' => $this->canonical_override,
                'is_indexable' => $this->is_indexable,
            ],
        ];
    }
}
