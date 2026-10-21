<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A site-wide banner shown above the header.
 *
 * The storefront shows at most one at a time: the first that is live (active
 * and inside its optional date window), lowest sort_order first. It rides on
 * the navigation payload, which every page already carries, so it needs no
 * endpoint of its own.
 *
 * @property int $id
 * @property string $uuid
 * @property string $message
 * @property string|null $url
 * @property string|null $link_label
 * @property string $variant
 * @property bool $is_active
 */
final class Announcement extends Model
{
    use HasPublicUuid;
    use SoftDeletes;

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Active, and inside its date window if it has one. Ordered so the first
     * row is the one the storefront shows.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeLive(Builder $query): Builder
    {
        $now = now();

        return $query
            ->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
            ->orderBy('sort_order')
            ->orderByDesc('id');
    }
}
