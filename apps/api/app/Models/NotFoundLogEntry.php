<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Every 404 with its referrer (MASTER BUILD PROMPT section 14.8).
 *
 * This is redirect discovery, not error reporting: the paths that keep being
 * requested are the ones someone still links to, and each one is a redirect
 * worth creating.
 */
final class NotFoundLogEntry extends Model
{
    protected $table = 'not_found_log';

    public $timestamps = false;

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'hits' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }
}
