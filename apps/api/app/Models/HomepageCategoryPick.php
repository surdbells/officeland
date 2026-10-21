<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One tile in the homepage "Shop by category" grid.
 *
 * A curated pointer to a category, replacing the hardcoded MAJOR_CATEGORY_SOURCES
 * list. The product count comes from the category itself; the tile image is an
 * optional override, falling back to the category's own hero. When no picks are
 * active the storefront falls back to its built-in list.
 *
 * @property int $id
 * @property string $uuid
 * @property int $category_id
 * @property string|null $label
 * @property int|null $media_id
 * @property bool $is_active
 */
final class HomepageCategoryPick extends Model
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

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
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
