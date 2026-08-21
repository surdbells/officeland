<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Content\ContentMinimums;
use App\Models\Attribute;
use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Product> */
final class ProductFactory extends Factory
{
    protected $model = Product::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = Str::headline(implode(' ', (array) $this->faker->unique()->words(3)));
        $slug = Str::slug($name).'-'.Str::lower(Str::random(5));

        return [
            'uuid' => (string) Str::uuid(),
            'name' => $name,
            'slug' => $slug,
            'sku' => 'OL-'.Str::upper(Str::random(8)),
            'mpn' => Str::upper(Str::random(7)),
            'status' => 'active',
            'availability' => 'in_stock',
            'pricing_mode' => 'quote_only',
            'is_indexable' => false,
            'min_order_qty' => 1,
            'qty_step' => 1,
            'published_at' => now(),
        ];
    }

    public function forBrand(Brand $brand): self
    {
        return $this->state(fn (): array => [
            'brand_id' => $brand->id,
            'brand_name_cached' => $brand->name,
        ]);
    }

    public function draft(): self
    {
        return $this->state(fn (): array => ['status' => 'draft']);
    }

    /**
     * A product that actually clears the §14.7 content floor.
     *
     * `is_indexable => true` is refused by the model unless the content is
     * there (EnforcesContentMinimums), so a test that wants an indexed product
     * has to build one — which is the point. Anything less would let the suite
     * assert behaviour for a page the real system would never publish.
     *
     * The three attribute values are created in `afterCreating`, because the
     * spec-table minimum counts rows in another table.
     */
    public function indexable(): self
    {
        return $this
            ->state(fn (): array => [
                'status' => 'active',
                'is_indexable' => true,
                'description_html' => '<p>'.$this->paragraph().'</p>',
                'warranty_text' => '12 months return-to-base.',
                'box_contents' => "The unit\nPower cable\nQuick-start guide",
                'lead_time_days' => 5,
            ])
            ->afterCreating(function (Product $product): void {
                $this->attachSpecifications($product);

                // Set last: `saving` refuses the transition while the
                // specification rows are still missing.
                $product->forceFill(['is_indexable' => true])->save();
            })
            // Created as not-indexable so the guard does not fire on insert,
            // then raised once the specifications exist.
            ->state(fn (): array => ['is_indexable' => false]);
    }

    private function attachSpecifications(Product $product): void
    {
        for ($i = 0; $i < ContentMinimums::PRODUCT_MIN_ATTRIBUTES; $i++) {
            $attribute = Attribute::query()->create([
                'uuid' => (string) Str::uuid(),
                'code' => 'spec_'.Str::lower(Str::random(10)),
                'name' => 'Specification '.($i + 1),
                'data_type' => 'string',
                'display_order' => $i,
            ]);

            ProductAttributeValue::query()->create([
                'product_id' => $product->id,
                'attribute_id' => $attribute->id,
                'value_raw' => 'value-'.$i,
                'value_presentation' => 'Value '.($i + 1),
                'sort_order' => $i,
            ]);
        }
    }

    /** Enough words to clear the minimum, without reading like filler. */
    private function paragraph(): string
    {
        return implode(' ', (array) $this->faker->words(ContentMinimums::PRODUCT_WORDS + 20));
    }
}
