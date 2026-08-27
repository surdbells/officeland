<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a submitted quote, with the product SNAPSHOTTED.
 *
 * ── Why the snapshot columns exist ───────────────────────────────────────
 * A quote records what the buyer asked for at the moment they asked. If it
 * rendered live from `products`, then renaming a product, correcting its
 * specification or deleting it would retroactively change what a customer is
 * on record as having requested - and the email they already hold would no
 * longer match the quote we hold.
 *
 * `product_id` is kept alongside so sales can still reach the live page, and
 * it is ON DELETE SET NULL: losing the link must not lose the line.
 *
 * @property int $id
 * @property int $quote_id
 * @property string $product_name_snapshot
 * @property int $qty
 */
final class QuoteItem extends Model
{
    protected $table = 'quote_items';

    protected $fillable = [
        'quote_id',
        'product_id',
        'variant_id',
        'product_name_snapshot',
        'sku_snapshot',
        'mpn_snapshot',
        'brand_name_snapshot',
        'spec_snapshot',
        'product_url_snapshot',
        'free_text_description',
        'qty',
        'customer_note',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<Quote, $this> */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
