<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full product detail payload.
 *
 * Specifications are returned already grouped. Section 23.3 called the grouped
 * specifications accordion the most important pattern to adopt, and the
 * grouping arrives with the source data rather than being invented here
 * (docs/ICECAT_MAPPING.md finding F-2). The same structure feeds
 * `additionalProperty` in Product JSON-LD (section 14.4).
 *
 * @mixin Product
 */
final class ProductResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $primaryCategory = $this->relationLoaded('primaryCategory') ? $this->primaryCategory : null;

        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'slug' => $this->slug,
            'url' => $this->url(),
            'sku' => $this->sku,
            'mpn' => $this->mpn,
            'model_code' => $this->model_code,
            'ean' => $this->ean,

            'brand' => new BrandResource($this->whenLoaded('brand')),
            'primary_category' => new CategoryResource($this->whenLoaded('primaryCategory')),

            'breadcrumb' => $primaryCategory === null ? null : $this->breadcrumb($primaryCategory),

            'short_description' => $this->short_description,
            'description_html' => $this->description_html,
            'warranty_text' => $this->warranty_text,

            'availability' => $this->availability,
            'lead_time_days' => $this->lead_time_days,
            'pricing_mode' => $this->pricing_mode,
            'min_order_qty' => $this->min_order_qty,
            'qty_step' => $this->qty_step,

            /*
             * The full gallery, primary first. The storefront gives the first
             * image fetchpriority="high" as the LCP element (section 14.9),
             * which relies on Product::media() ordering primary first.
             */
            'images' => ProductImageResource::collection($this->whenLoaded('media')),

            'specifications' => $this->when(
                $this->relationLoaded('attributeValues'),
                fn (): array => $this->groupedSpecifications(),
            ),

            'variants' => $this->whenLoaded('variants', fn (): array => $this->variants
                ->map(fn (ProductVariant $v): array => [
                    'uuid' => $v->uuid,
                    'name' => $v->name,
                    'sku' => $v->sku,
                    'attributes' => $v->attributes,
                    'availability' => $v->availability,
                ])->all()),

            'related' => $this->whenLoaded('relatedProducts', fn (): array => $this->groupedRelations()),

            'seo' => [
                'meta_title' => $this->meta_title,
                'meta_description' => $this->meta_description,
                'canonical_override' => $this->canonical_override,
                'is_indexable' => $this->is_indexable,
            ],
        ];
    }

    /** @return list<array{name: string, url: string}> */
    private function breadcrumb(Category $category): array
    {
        $crumbs = [];

        foreach ($category->ancestors()->push($category) as $node) {
            /** @var Category $node */
            $crumbs[] = ['name' => (string) $node->name, 'url' => $node->url()];
        }

        return $crumbs;
    }

    /**
     * Specifications grouped for the accordion, in the source's own order.
     *
     * @return list<array{group: string, items: list<array<string, string|null>>}>
     */
    private function groupedSpecifications(): array
    {
        $groups = [];

        foreach ($this->attributeValues as $value) {
            /** @var ProductAttributeValue $value */
            $attribute = $value->attribute;

            // An attribute can be deleted while its values linger. Skipping is
            // correct; dereferencing would crash the whole PDP payload.
            if ($attribute === null) {
                continue;
            }

            // An attribute with no group is genuinely ungrouped — group_id is
            // nullable — and filing it under "General" is a display decision,
            // not a data one.
            $group = $attribute->group;
            $name = 'General';
            $order = PHP_INT_MAX;

            if ($group !== null) {
                $name = (string) $group->name;
                $order = (int) $group->display_order;
            }

            $groups[$name]['order'] = $order;
            $groups[$name]['items'][] = [
                'order' => (int) $attribute->display_order,
                'label' => (string) $attribute->name,
                // Presentation for display, raw for compare — Icecat is
                // explicit that these differ (F-1).
                'value' => $value->displayValue(),
                'value_raw' => $value->value_raw,
                'unit' => $attribute->unit_symbol,
            ];
        }

        uasort($groups, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        $result = [];

        foreach ($groups as $name => $group) {
            $items = $group['items'];
            usort($items, static fn (array $a, array $b): int => $b['order'] <=> $a['order']);

            $rendered = [];

            foreach ($items as $item) {
                $rendered[] = [
                    'label' => $item['label'],
                    'value' => $item['value'],
                    'value_raw' => $item['value_raw'],
                    'unit' => $item['unit'],
                ];
            }

            $result[] = ['group' => (string) $name, 'items' => $rendered];
        }

        return $result;
    }

    /**
     * Related products keyed by relation type — compatible, accessory,
     * consumable and so on (docs/ICECAT_MAPPING.md finding F-3).
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function groupedRelations(): array
    {
        $grouped = [];

        foreach ($this->relatedProducts as $related) {
            /** @var Product $related */
            $type = (string) $related->getAttribute('pivot')->relation_type;

            $grouped[$type][] = (new ProductCardResource($related))->toArray(request());
        }

        return $grouped;
    }
}
