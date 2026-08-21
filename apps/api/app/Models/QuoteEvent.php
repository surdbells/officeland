<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An append-only record of what happened to a quote.
 *
 * The lead pipeline is the second most important thing this system does after
 * organic search, and "we never received it" is the failure that costs an
 * order. This is the trail that answers it: submitted, emailed, delivered,
 * bounced, opened, assigned, responded.
 *
 * @property int $id
 * @property int $quote_id
 * @property string $type
 * @property array<string, mixed> $payload
 */
final class QuoteEvent extends Model
{
    protected $table = 'quote_events';

    /** Rows are written once and never updated; `created_at` is set by the database. */
    public $timestamps = false;

    protected $fillable = [
        'quote_id',
        'type',
        'actor_user_id',
        'actor_label',
        'payload',
        'trace_id',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    /** @return BelongsTo<Quote, $this> */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }
}
