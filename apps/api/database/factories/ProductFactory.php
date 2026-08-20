<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Brand;
use App\Models\Product;
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
        $slug = Str::slug($name).'-'.Str::random(5);

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
}
