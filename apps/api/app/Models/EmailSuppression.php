<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An address we must not write to again (§10d).
 *
 * @property int $id
 * @property string $email
 * @property string $reason
 * @property Carbon|null $released_at
 */
final class EmailSuppression extends Model
{
    protected $table = 'email_suppressions';

    protected $fillable = [
        'email',
        'reason',
        'detail',
        'provider_event_id',
        'provider_request_id',
        'suppressed_at',
        'released_at',
        'released_by',
    ];

    /** A hard bounce: the mailbox does not exist. Permanent. */
    public const REASON_HARD_BOUNCE = 'hard_bounce';

    /** The recipient marked it as spam. Never write to them again unasked. */
    public const REASON_COMPLAINT = 'complaint';

    /** Added by hand in the admin panel. */
    public const REASON_MANUAL = 'manual';

    protected function casts(): array
    {
        return [
            'suppressed_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    /**
     * Suppression is a property of the mailbox, and every provider that
     * matters treats the local part case-insensitively in practice.
     */
    public static function normalise(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('released_at');
    }

    public function isActive(): bool
    {
        return $this->released_at === null;
    }
}
