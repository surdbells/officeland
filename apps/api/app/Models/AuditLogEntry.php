<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * An entry in the mutation audit log (MASTER BUILD PROMPT section 18).
 *
 * Append-only. An audit log that can be edited is not evidence of anything,
 * so update() and delete() are refused at the model layer rather than left to
 * convention.
 */
/**
 * @property array<string, mixed> $before
 * @property array<string, mixed> $after
 */
final class AuditLogEntry extends Model
{
    protected $table = 'audit_log';

    public $timestamps = false;

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected static function booted(): void
    {
        self::updating(function (): never {
            throw new RuntimeException('The audit log is append-only; entries cannot be modified.');
        });

        self::deleting(function (): never {
            throw new RuntimeException('The audit log is append-only; entries cannot be deleted.');
        });
    }

    /**
     * The fields that actually moved, for display.
     *
     * @return list<string>
     */
    public function changedKeys(): array
    {
        $keys = array_unique([...array_keys($this->before), ...array_keys($this->after)]);
        sort($keys);

        return $keys;
    }
}
