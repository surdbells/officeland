<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Product;
use App\Models\ProductAttributeValue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The listing payload — one shape used by PLP, search, related rails and
 * collections, matching the single shared card component of section 23.5.
 *
 * Deliberately lighter than ProductResource: a 24-item listing must not carry
 * full descriptions and complete spec tables. Section 23.2 also notes the
 * reference site renders each card title three times for responsive variants;
 * we send it once and style responsively.
 *
 * @mixin Product
 */
final class ProductCardResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        // A product may legitimately have no brand — unbranded stationery,
        // own-label furniture — so this is nullable rather than assumed.
        $brand = $this->relationLoaded('brand') ? $this->brand : null;

        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'slug' => $this->slug,
            'url' => $this->url(),
            'sku' => $this->sku,
            'mpn' => $this->mpn,
            'model_code' => $this->model_code,
            'brand' => $brand === null ? null : [
                'name' => $brand->name,
                'slug' => $brand->slug,
                'url' => $brand->url(),
            ],
            'availability' => $this->availability,
            'lead_time_days' => $this->lead_time_days,
            // D-002: no prices. pricing_mode ships anyway so the storefront
            // renders "Request quote" from data rather than a hardcoded rule,
            // and posture C can be switched on without a client change.
            'pricing_mode' => $this->pricing_mode,
            'min_order_qty' => $this->min_order_qty,
            'qty_step' => $this->qty_step,
            'key_specs' => $this->when(
                $this->relationLoaded('attributeValues'),
                fn (): array => $this->keySpecs(),
            ),
        ];
    }

    /**
     * The 4–6 bullets shown above the fold, ordered by Icecat's own
     * buyer-orientation priority (ProductFeature@No).
     *
     * @return list<array{label: string, value: string}>
     */
    private function keySpecs(): array
    {
        $candidates = [];

        foreach ($this->attributeValues as $value) {
            /** @var ProductAttributeValue $value */
            $attribute = $value->attribute;

            // An attribute can be deleted while its values linger. Skipping is
            // correct; dereferencing would crash the whole listing.
            if ($attribute === null || ! $attribute->is_key_spec) {
                continue;
            }

            $candidates[] = [
                'order' => (int) $attribute->display_order,
                'label' => (string) $attribute->name,
                'value' => $value->displayValue(),
            ];
        }

        usort($candidates, static fn (array $a, array $b): int => $b['order'] <=> $a['order']);

        $specs = [];

        foreach (array_slice($candidates, 0, 6) as $candidate) {
            $specs[] = ['label' => $candidate['label'], 'value' => $candidate['value']];
        }

        return $specs;
    }
}
