<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * group_id is nullable, so the relation genuinely resolves to null for
 * ungrouped attributes. The analyser infers non-null from the relation return
 * type alone, which is why the real nullability is declared here.
 *
 * @property int $id
 * @property string $name
 * @property int $display_order
 * @property string|null $unit_symbol
 * @property bool $is_key_spec
 * @property-read AttributeGroup|null $group
 */
final class Attribute extends Model
{
    use HasPublicUuid;

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_filterable' => 'boolean',
            'is_indexable_facet' => 'boolean',
            'is_key_spec' => 'boolean',
            'display_order' => 'integer',
        ];
    }

    /** @return BelongsTo<AttributeGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(AttributeGroup::class, 'group_id');
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeFilterable(Builder $query): Builder
    {
        return $query->where('is_filterable', true);
    }

    /**
     * Facet indexability is a data attribute, never a hardcoded list
     * (MASTER BUILD PROMPT section 14.5 rule 4).
     */
    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeIndexableFacet(Builder $query): Builder
    {
        return $query->where('is_indexable_facet', true);
    }
}
