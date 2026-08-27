<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Every search, with its result count (section 14.10).
 *
 * Zero-result queries are "a merchandising and content goldmine" - they are
 * literally a list of things buyers asked for and did not find, ranked by how
 * many asked. Each one is either a product to stock, a synonym to add, or a
 * category page to write.
 *
 * @property string $query_normalised
 * @property int $result_count
 * @property int $occurrences
 */
final class SearchQueryLog extends Model
{
    protected $table = 'search_queries_log';

    public $timestamps = false;

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'result_count' => 'integer',
            'occurrences' => 'integer',
            'clickthroughs' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeZeroResult(Builder $query): Builder
    {
        return $query->where('result_count', 0);
    }
}
