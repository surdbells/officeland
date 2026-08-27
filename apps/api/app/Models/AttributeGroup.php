<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Icecat CategoryFeatureGroup - OVERVIEW, PERFORMANCE, SCREEN and so on.
 *
 * Section 23.3 identified the grouped specifications accordion as the single
 * highest-leverage fix for unstructured supplier prose. The grouping arrives
 * with the data (docs/ICECAT_MAPPING.md finding F-2) rather than being
 * invented at render time.
 */
final class AttributeGroup extends Model
{
    use HasPublicUuid;

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['display_order' => 'integer'];
    }

    /** @return HasMany<Attribute, $this> */
    public function attributes(): HasMany
    {
        return $this->hasMany(Attribute::class, 'group_id')->orderBy('display_order');
    }
}
