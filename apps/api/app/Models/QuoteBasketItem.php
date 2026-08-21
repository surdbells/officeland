<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line in a basket.
 *
 * A line is either a catalogue product or a free-text request — the schema
 * enforces that at least one is present. Free text exists because OfficeLand
 * supplies far more than it lists, and a buyer with a requisition list should
 * not have to drop the items we do not happen to have a page for.
 *
 * @property int $id
 * @property int $basket_id
 * @property int|null $product_id
 * @property int|null $variant_id
 * @property string|null $free_text_description
 * @property int $qty
 * @property string|null $customer_note
 */
final class QuoteBasketItem extends Model
{
    protected $table = 'quote_basket_items';

    protected $fillable = [
        'basket_id',
        'product_id',
        'variant_id',
        'free_text_description',
        'qty',
        'customer_note',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'integer',
        ];
    }

    /** @return BelongsTo<QuoteBasket, $this> */
    public function basket(): BelongsTo
    {
        return $this->belongsTo(QuoteBasket::class, 'basket_id');
    }

    /**
     * Nullable, and deliberately so: the FK is ON DELETE SET NULL. A product
     * removed from the catalogue must not delete a line out of somebody's
     * basket without trace — the line keeps its description and becomes a
     * free-text request.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function isFreeText(): bool
    {
        return $this->product_id === null;
    }
}
