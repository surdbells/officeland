<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Brand;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Brand
 */
final class BrandResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        // No internal integer IDs (MASTER BUILD PROMPT section 8.3).
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'slug' => $this->slug,
            'url' => $this->url(),
            'description_html' => $this->description_html,
            'is_featured' => $this->is_featured,
            'product_count' => $this->product_count_cached,
            'seo' => [
                'meta_title' => $this->meta_title,
                'meta_description' => $this->meta_description,
                'is_indexable' => $this->is_indexable,
            ],
        ];
    }
}
