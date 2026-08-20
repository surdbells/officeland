<?php

declare(strict_types=1);

namespace App\Models;

use App\Search\SynonymExpander;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $term
 * @property string $synonym
 * @property bool $bidirectional
 * @property bool $is_active
 */
final class SearchSynonym extends Model
{
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'bidirectional' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // The expander caches the whole table. Editing a synonym that does not
        // take effect until the cache expires is the kind of thing that gets
        // reported as "search is broken".
        self::saved(fn () => SynonymExpander::forget());
        self::deleted(fn () => SynonymExpander::forget());
    }
}
