<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * @property int $id
 * @property string $uuid
 * @property string $path
 * @property string $checksum_sha256
 * @property int|null $width
 * @property int|null $height
 * @property string|null $alt_text
 * @property bool $is_watermarked_flag
 */
final class Media extends Model
{
    use HasPublicUuid;

    protected $table = 'media';

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'width' => 'integer',
            'height' => 'integer',
            'bytes' => 'integer',
            'is_watermarked_flag' => 'boolean',
            'focal_x' => 'float',
            'focal_y' => 'float',
        ];
    }

    /** @return HasMany<MediaDerivative, $this> */
    public function derivatives(): HasMany
    {
        return $this->hasMany(MediaDerivative::class);
    }

    /**
     * Alt text is a REQUIRED field on any image attached to a product or
     * article (MASTER BUILD PROMPT section 12.6), enforced at the model layer
     * rather than only in a form — the CSV importer and Icecat ingestion write
     * media too, and a form validator would not see them.
     *
     * It is nullable in the database because media exists briefly between
     * upload and description; this is the gate that stops it being ATTACHED
     * without one.
     */
    public function assertAttachable(): void
    {
        if (trim((string) $this->alt_text) === '') {
            throw new RuntimeException(
                "Media {$this->uuid} has no alt text and cannot be attached to a product or article."
            );
        }
    }

    public function hasAltText(): bool
    {
        return trim((string) $this->alt_text) !== '';
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeMissingAltText(Builder $query): Builder
    {
        return $query->where(function (Builder $q): void {
            $q->whereNull('alt_text')->orWhereRaw("trim(alt_text) = ''");
        });
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeWatermarked(Builder $query): Builder
    {
        return $query->where('is_watermarked_flag', true);
    }
}
