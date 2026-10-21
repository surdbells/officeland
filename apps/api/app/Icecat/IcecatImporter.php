<?php

declare(strict_types=1);

namespace App\Icecat;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Str;

/**
 * Creates one draft product from an Icecat index entry + data sheet.
 *
 * The single source of truth for "turn an Icecat product into a draft", shared
 * by the foreground discover command and the queued ImportIcecatProduct job, so
 * both import identically. Every product is a DRAFT (status=draft, noindex): the
 * Icecat prose is kept only as source_* drafts (D-015); publishing is a separate
 * gated step.
 */
final class IcecatImporter
{
    public function __construct(
        private readonly IcecatClient $client,
        private readonly IcecatIngestor $ingestor,
    ) {}

    /**
     * Fetch and import one product by its index entry. Returns the Product, or
     * null when it is already imported (dedup by icecat_id) or has no usable
     * data. Idempotent: safe to call again for the same id.
     *
     * @param  array{product_id: int, mpn: ?string, supplier_id: int, catid: ?int, model_name: ?string, ean: ?string}  $entry
     */
    public function importEntry(
        array $entry,
        string $brandKey,
        ?int $maxImages = null,
        bool $queueDerivatives = true,
    ): ?Product {
        if (Product::query()->where('icecat_id', $entry['product_id'])->exists()) {
            return null;
        }

        $document = $this->client->fetchById($entry['product_id']);

        if ($document === null || $this->isEmpty($document)) {
            return null;
        }

        return $this->createFrom($entry, $document, $brandKey, $maxImages, $queueDerivatives);
    }

    /**
     * Create the draft product shell from an already-fetched document, then let
     * the shared ingestor write images, specs, relations and facts onto it.
     *
     * @param  array{product_id: int, mpn: ?string, supplier_id: int, catid: ?int, model_name: ?string, ean: ?string}  $entry
     */
    public function createFrom(
        array $entry,
        IcecatDocument $document,
        string $brandKey,
        ?int $maxImages = null,
        bool $queueDerivatives = true,
    ): Product {
        $brand = $this->brand($document->brandName() ?? ucfirst($brandKey));
        $name = $document->title() ?? $entry['model_name'] ?? ('Product '.$entry['product_id']);
        $category = $this->category($entry['catid']);

        $product = Product::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => $name,
            'slug' => $this->uniqueSlug($name, $entry['product_id']),
            'brand_id' => $brand->id,
            'brand_name_cached' => $brand->name,
            'mpn' => $document->mpn() ?? $entry['mpn'],
            'ean' => $entry['ean'],
            'icecat_id' => $entry['product_id'],
            'primary_category_id' => $category?->id,
            'status' => 'draft',
            'is_indexable' => false,
            'source' => 'icecat',
        ]);

        // The category pivot drives listing pages; primary_category_id alone only
        // gives a breadcrumb.
        if ($category !== null) {
            $product->categories()->syncWithoutDetaching([$category->id => ['sort_order' => 0]]);
        }

        $this->ingestor->write(
            $product,
            $document,
            forceImages: true,
            maxImages: $maxImages ?? (int) config('icecat.max_images', 3),
            queueDerivatives: $queueDerivatives,
        );

        return $product;
    }

    /** A document with no id, no images and no specs is not worth a product. */
    public function isEmpty(IcecatDocument $document): bool
    {
        return $document->icecatId() === null
            && $document->imageUrls() === []
            && $document->featureGroups() === [];
    }

    private function brand(string $name): Brand
    {
        return Brand::query()->firstOrCreate(
            ['slug' => Str::slug($name)],
            ['uuid' => (string) Str::uuid(), 'name' => $name],
        );
    }

    private function category(?int $catid): ?Category
    {
        if ($catid === null) {
            return null;
        }

        $path = config("icecat.category_map.{$catid}");

        return $path !== null ? Category::query()->where('path', $path)->first() : null;
    }

    /** A slug unique against existing products; the Icecat id disambiguates. */
    private function uniqueSlug(string $name, int $icecatId): string
    {
        $base = rtrim(Str::limit(Str::slug($name) ?: 'product', 180, ''), '-');

        if (! Product::query()->where('slug', $base)->exists()) {
            return $base;
        }

        return $base.'-'.$icecatId;
    }
}
