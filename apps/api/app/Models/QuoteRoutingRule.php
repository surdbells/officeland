<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "Leads for this category go to this address" (§11d).
 *
 * @property int $id
 * @property int $category_id
 * @property string $recipient
 * @property bool $is_active
 * @property string|null $note
 */
final class QuoteRoutingRule extends Model
{
    protected $table = 'quote_routing_rules';

    protected $fillable = [
        'category_id',
        'recipient',
        'is_active',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
