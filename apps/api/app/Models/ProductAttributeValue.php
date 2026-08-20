<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Icecat is explicit that these serve different purposes:
 *
 *   value_raw          -> search and compare  (ProductFeature@Value)
 *   value_presentation -> display             (ProductFeature@Presentation_Value)
 *
 * Storing only one would force a choice between a spec table that reads
 * correctly and facets that filter correctly (docs/ICECAT_MAPPING.md F-1).
 */
final class ProductAttributeValue extends Model
{
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'value_numeric' => 'float',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<Attribute, $this> */
    public function attribute(): BelongsTo
    {
        return $this->belongsTo(Attribute::class);
    }

    /** What a buyer should see. Falls back to the raw value. */
    public function displayValue(): string
    {
        return $this->value_presentation ?: $this->value_raw;
    }
}
