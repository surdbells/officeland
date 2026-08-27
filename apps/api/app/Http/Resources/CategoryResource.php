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
             * Null when a category has no image - which is every category
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
            // crawlable anchors on every category page (D-011) - that is what
            // makes every product reachable within 3 clicks.
            'children' => CategoryResource::collection($this->whenLoaded('children')),

            /*
             * Siblings, and only for a category with no children of its own.
             *
             * A leaf page has nothing to show in the "browse deeper" row that
             * every other level gets, and the useful thing at that point is
             * not "nothing" but "the other kinds of chair". This is what the
             * reference site does and it is genuinely the right call: somebody
             * on Executive Chairs who wanted Task Chairs is one click away
             * instead of two.
             *
             * Empty on a department or subcategory, because there the row
             * shows children and siblings would compete with them.
             */
            'siblings' => $this->when(
                $request->routeIs('api.categories.show'),
                fn (): array => $this->siblingPayload($request),
            ),
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

    /**
     * The other categories alongside this one, when it has no children.
     *
     * Returns [] rather than null for an empty case, so the storefront tests
     * a length instead of distinguishing "no siblings" from "not loaded".
     *
     * @return list<array<string, mixed>>
     */
    private function siblingPayload(Request $request): array
    {
        if (! $this->relationLoaded('children') || $this->children->isNotEmpty()) {
            return [];
        }

        if (! $this->relationLoaded('parent') || $this->parent === null) {
            return [];
        }

        $siblings = $this->parent->children
            ->reject(fn (Category $sibling): bool => $sibling->id === $this->id)
            ->map(fn (Category $sibling): array => (new CategoryResource($sibling))->toArray($request))
            ->all();

        // array_values, not Collection::values(): PHPStan infers a list from
        // the former and merely array<int, T> from the latter. Same reason as
        // SitemapGenerator.
        return array_values($siblings);
    }
}
