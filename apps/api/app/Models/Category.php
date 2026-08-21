<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\EnforcesContentMinimums;
use App\Models\Concerns\HasPublicUuid;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

/**
 * @property int $id
 * @property string $uuid
 * @property int|null $parent_id
 * @property string $name
 * @property string $slug
 * @property string $path
 * @property int $depth
 * @property bool $is_indexable
 */
final class Category extends Model
{
    use EnforcesContentMinimums;

    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    use HasPublicUuid;
    use SoftDeletes;

    /** Enforced by a CHECK constraint; duplicated here for validation messages. */
    public const MAX_DEPTH = 3;

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'depth' => 'integer',
            'sort_order' => 'integer',
            'product_count_cached' => 'integer',
            'is_indexable' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'path';
    }

    /** @return BelongsTo<self, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<self, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order');
    }

    /** @return BelongsToMany<Product, $this> */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_category')
            ->withPivot('sort_order');
    }

    /**
     * Ancestors of this category, root first.
     *
     * Drives the breadcrumb and the BreadcrumbList JSON-LD
     * (MASTER BUILD PROMPT section 14.4). One query, no recursion: the
     * materialized path already contains every ancestor.
     *
     * @return Collection<int, self>
     */
    public function ancestors()
    {
        $paths = [];
        $segments = array_values(array_filter(explode('/', $this->path)));

        $accumulated = '';
        foreach ($segments as $segment) {
            $accumulated .= '/'.$segment;
            $paths[] = $accumulated.'/';
        }

        // Drops the final entry — that is this category, not an ancestor.
        array_pop($paths);

        if ($paths === []) {
            return collect();
        }

        return self::query()
            ->whereIn('path', $paths)
            ->orderBy('depth')
            ->get();
    }

    /**
     * The entire subtree beneath this category.
     *
     * A single index-backed prefix scan — the reason the tree uses a
     * materialized path (docs/ARCHITECTURE.md section 3).
     */
    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeSubtreeOf(Builder $query, string $path): Builder
    {
        return $query->where('path', 'like', $path.'%');
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeIndexable(Builder $query): Builder
    {
        return $query->where('is_indexable', true);
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeDepth(Builder $query, int $depth): Builder
    {
        return $query->where('depth', $depth);
    }

    /** Public URL. Categories keep the /c/ prefix (D-008). */
    public function url(): string
    {
        return '/c'.$this->path;
    }
}
