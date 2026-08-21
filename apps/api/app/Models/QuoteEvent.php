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

    /**
     * One line of plain English for the timeline (§11b).
     *
     * The payload is a jsonb bag whose shape differs per type, and a timeline
     * that renders it raw is one a salesperson skips. It lives on the model
     * rather than in the Filament page because the same wording belongs in any
     * future export or digest — and because a type added elsewhere should show
     * up here without an admin change.
     *
     * The fallthrough is deliberate: an unrecognised type still renders as
     * something, because a silent gap in an audit trail is worse than an ugly
     * line in one.
     */
    public function describe(): string
    {
        $payload = $this->payload;

        $lines = (int) ($payload['lines'] ?? 0);
        $units = (int) ($payload['units'] ?? 0);

        return match ($this->type) {
            'submitted' => sprintf(
                '%d line%s, %d unit%s',
                $lines,
                $lines === 1 ? '' : 's',
                $units,
                $units === 1 ? '' : 's',
            ),
            'email_queued' => sprintf('%s queued to %s', $payload['template'] ?? 'email', $payload['to'] ?? 'unknown'),
            'email_queue_failed' => sprintf('%s could NOT be queued: %s', $payload['template'] ?? 'email', $payload['error'] ?? 'unknown error'),
            'confirmation_suppressed' => sprintf('No receipt sent — %s is suppressed', $payload['email'] ?? 'the address'),
            'assigned' => sprintf('Assigned to %s', $payload['to'] ?? 'nobody'),
            'first_response' => 'Marked as replied to',
            'note' => (string) ($payload['note'] ?? ''),
            'status_changed' => trim(sprintf(
                '%s → %s%s',
                $payload['from'] ?? '?',
                $payload['to'] ?? '?',
                isset($payload['note']) ? ' — '.$payload['note'] : '',
            )),
            default => $payload === []
                ? ''
                : (json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''),
        };
    }
}
