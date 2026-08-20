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
