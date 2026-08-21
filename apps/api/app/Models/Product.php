<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string $slug
 * @property string|null $sku
 * @property string|null $mpn
 * @property int|null $brand_id
 * @property int|null $primary_category_id
 * @property string $status
 * @property bool $is_indexable
 */
final class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    use HasPublicUuid;
    use SoftDeletes;

    /**
     * Minimum original description length before a product may be indexed
     * (MASTER BUILD PROMPT section 14.7). Enforced when publishing, not here.
     */
    public const CONTENT_MINIMUM_WORDS = 120;

    protected $guarded = ['id'];

    /**
     * search_vector is GENERATED ALWAYS — PostgreSQL rejects any attempt to
     * write it, so it must never appear in an insert or update.
     *
     * @var list<string>
     */
    protected $guarded_columns = ['search_vector'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_indexable' => 'boolean',
            'min_order_qty' => 'integer',
            'qty_step' => 'integer',
            'lead_time_days' => 'integer',
            'price_ngn' => 'decimal:2',
            'published_at' => 'datetime',
            'icecat_synced_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * The category that determines the canonical URL and the breadcrumb.
     * A product may belong to many categories; exactly one is primary
     * (SEO_SPEC.md section 2).
     */
    /** @return BelongsTo<Category, $this> */
    public function primaryCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'primary_category_id');
    }

    /** @return BelongsToMany<Category, $this> */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'product_category')
            ->withPivot('sort_order');
    }

    /**
     * Gallery images, primary first.
     *
     * Ordered by role before sort_order, so the `primary` image leads whatever
     * its position in the gallery. The storefront takes the first image as the
     * LCP element and gives it fetchpriority="high" (section 14.9), which is
     * only correct if "first" is deterministic.
     *
     * @return BelongsToMany<Media, $this>
     */
    public function media(): BelongsToMany
    {
        return $this->belongsToMany(Media::class, 'product_media')
            ->withPivot(['role', 'sort_order'])
            ->orderByRaw("case product_media.role when 'primary' then 0 else 1 end")
            ->orderBy('product_media.sort_order');
    }

    /** @return HasMany<ProductVariant, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('sort_order');
    }

    /** @return HasMany<ProductAttributeValue, $this> */
    public function attributeValues(): HasMany
    {
        return $this->hasMany(ProductAttributeValue::class);
    }

    /**
     * Typed relations supplied by Icecat rather than hand-curated
     * (docs/ICECAT_MAPPING.md finding F-3). Drives the PDP accessories rail
     * and printer -> compatible toner.
     */
    /** @return BelongsToMany<self, $this> */
    public function relatedProducts(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'product_relations', 'product_id', 'related_product_id')
            ->withPivot(['relation_type', 'sort_order'])
            ->orderBy('product_relations.sort_order');
    }

    /**
     * Products whose rails display THIS product.
     *
     * The reverse direction is what drives prerender invalidation: changing a
     * product means every page rendering it in a rail is now stale
     * (docs/ARCHITECTURE.md section 4.3).
     */
    /** @return BelongsToMany<self, $this> */
    public function relatedFrom(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'product_relations', 'related_product_id', 'product_id');
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeIndexable(Builder $query): Builder
    {
        return $query->where('is_indexable', true)->where('status', 'active');
    }

    /** Flat product URL — one product, one URL, regardless of category (D-008). */
    public function url(): string
    {
        return '/p/'.$this->slug.'/';
    }

    /**
     * Whether this product meets the content floor for indexing.
     *
     * Deliberately not automatic: raising is_indexable is an editorial act,
     * and this only reports whether it would be permitted.
     */
    public function meetsContentMinimum(): bool
    {
        $words = str_word_count(strip_tags((string) $this->description_html));

        return $words >= self::CONTENT_MINIMUM_WORDS;
    }
}
