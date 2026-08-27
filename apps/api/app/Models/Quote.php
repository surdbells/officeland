<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A submitted quote request - the lead (D-004).
 *
 * There is no priced-quote cycle here: sales reads this, prices it in their own
 * system, and replies. So this table is the record of what a buyer asked for
 * and how they can be reached, and nothing else.
 *
 * @property int $id
 * @property string $uuid
 * @property string $reference
 * @property string $email
 * @property string $phone
 * @property string|null $message
 * @property string $status
 * @property Carbon|null $submitted_at
 * @property Carbon|null $first_response_at
 * @property Carbon|null $closed_at
 * @property string|null $ip_hash
 */
final class Quote extends Model
{
    use HasPublicUuid;

    protected $table = 'quotes';

    protected $fillable = [
        'uuid',
        'reference',
        'email',
        'phone',
        'message',
        'status',
        'source',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_term',
        'utm_content',
        'referrer',
        'landing_page',
        'ip_hash',
        'user_agent',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'first_response_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /** @return HasMany<QuoteItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(QuoteItem::class)->orderBy('sort_order');
    }

    /** @return HasMany<QuoteEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(QuoteEvent::class)->orderBy('created_at');
    }

    /** @return BelongsTo<User, $this> */
    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeUnanswered(Builder $query): Builder
    {
        return $query->whereNull('first_response_at');
    }
}
