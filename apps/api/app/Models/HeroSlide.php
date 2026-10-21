<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One slide of the homepage hero carousel.
 *
 * Each slide carries its own photograph and its own copy, so the hero is no
 * longer one hardcoded headline laid over rotating department photos. When no
 * slides are active the storefront falls back to the department-derived hero
 * it has always shown.
 *
 * @property int $id
 * @property string $uuid
 * @property string $headline
 * @property string|null $subheadline
 * @property string|null $cta_label
 * @property string|null $cta_url
 * @property int|null $media_id
 * @property bool $is_active
 */
final class HeroSlide extends Model
{
    use HasPublicUuid;
    use SoftDeletes;

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<Media, $this> */
    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'media_id');
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort_order')->orderBy('id');
    }
}
