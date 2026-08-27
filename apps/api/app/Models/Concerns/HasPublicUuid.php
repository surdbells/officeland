<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/**
 * Assigns a UUID on create and routes by it.
 *
 * Internal integer IDs are never exposed in public payloads
 * (MASTER BUILD PROMPT section 8.3) - they leak catalogue size and growth rate,
 * and they make every URL a guessable enumeration.
 */
trait HasPublicUuid
{
    protected static function bootHasPublicUuid(): void
    {
        static::creating(function (self $model): void {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
