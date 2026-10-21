<?php

declare(strict_types=1);

namespace App\Icecat;

use App\Media\DerivativeGenerator;
use App\Media\MediaIngestor;
use App\Models\Attribute;
use App\Models\AttributeGroup;
use App\Models\Brand;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Writes Icecat facts onto a product: mirrored images (+derivatives), grouped
 * specifications, typed relations, warranty, identifiers and provenance.
 *
 * Shared by `catalogue:icecat-enrich` (updating a migrated product) and
 * `catalogue:icecat-discover` (a freshly created draft), so both ingest Icecat
 * data identically. Prose is never published - the title/description are kept
 * only as source_* drafts (D-015); only facts become live fields.
 */
final class IcecatIngestor
{
    /** @var array<string, Brand|null> lower(name) => brand */
    private array $brandCache = [];

    public function __construct(
        private readonly MediaIngestor $media,
        private readonly DerivativeGenerator $derivatives,
    ) {}

    /**
     * Apply the document to a PERSISTED product and save it.
     *
     * @return array{images: int, specs: int, relations: int}
     */
    public function write(Product $product, IcecatDocument $document, bool $forceImages = false): array
    {
        $images = $this->attachImages($product, $document, $forceImages);
        $specs = $this->writeSpecifications($product, $document);
        $relations = $this->linkRelations($product, $document);
        $this->applyFacts($product, $document);
        $product->save();

        return ['images' => $images, 'specs' => $specs, 'relations' => $relations];
    }

    /**
     * Product-level facts. Prose stays in source_* (draft only, D-015); only
     * warranty, identifiers and provenance become live fields. Does not save -
     * {@see write()} does, after specs have set search_text_specs.
     */
    private function applyFacts(Product $product, IcecatDocument $document): void
    {
        $product->source_title = $document->title() ?? $product->source_title;
        $product->source_description = $document->longDescription()
            ?? $document->shortDescription()
            ?? $product->source_description;

        if (($product->warranty_text ?? '') === '' && $document->warranty() !== null) {
            $product->warranty_text = $document->warranty();
        }

        if ($product->icecat_id === null && $document->icecatId() !== null) {
            $product->icecat_id = $document->icecatId();
        }

        if (($product->ean ?? '') === '' && $document->eans() !== []) {
            $product->ean = $document->eans()[0];
        }

        $product->source = 'icecat';
        $product->icecat_synced_at = now();
    }

    private function attachImages(Product $product, IcecatDocument $document, bool $force): int
    {
        // Skip when the product already has images and we are not forcing - the
        // derivative generation is the expensive part and need not repeat.
        if (! $force && $product->media()->exists()) {
            return 0;
        }

        $count = 0;
        $sort = 0;

        foreach ($document->imageUrls() as $url) {
            $tmp = null;

            try {
                $response = Http::timeout((int) config('icecat.timeout', 45))
                    ->retry(2, 1500, throw: false)
                    ->get($url);

                if (! $response->successful()) {
                    continue;
                }

                $ext = pathinfo((string) (parse_url($url, PHP_URL_PATH) ?: ''), PATHINFO_EXTENSION) ?: 'jpg';
                $tmp = tempnam(sys_get_temp_dir(), 'icecat').'.'.strtolower($ext);
                file_put_contents($tmp, $response->body());

                $media = $this->media->ingest($tmp, [
                    'alt_text' => $product->name,
                    'source' => 'icecat',
                    'source_url' => $url,
                ]);

                $this->derivatives->generate($media);

                $product->media()->syncWithoutDetaching([
                    $media->id => [
                        // First image is the primary (LCP); the rest are gallery
                        // (Product::media orders primary first).
                        'role' => $sort === 0 ? 'primary' : 'gallery',
                        'sort_order' => $sort,
                    ],
                ]);

                $count++;
                $sort++;
            } catch (Throwable $e) {
                Log::warning('icecat image failed', [
                    'url' => $url,
                    'product' => $product->slug,
                    'error' => $e->getMessage(),
                ]);
            } finally {
                if ($tmp !== null && is_file($tmp)) {
                    @unlink($tmp);
                }
            }
        }

        return $count;
    }

    private function writeSpecifications(Product $product, IcecatDocument $document): int
    {
        $groups = $document->featureGroups();

        if ($groups === []) {
            return 0;
        }

        $written = 0;

        DB::transaction(function () use ($product, $groups, &$written): void {
            // Replace wholesale so the spec table always matches Icecat's current
            // data rather than accreting stale rows across runs.
            $product->attributeValues()->delete();

            $searchText = [];

            foreach ($groups as $group) {
                $attributeGroup = $this->resolveGroup($group);

                foreach ($group['features'] as $feature) {
                    $attribute = $this->resolveAttribute($feature, $attributeGroup);

                    $product->attributeValues()->create([
                        'attribute_id' => $attribute->id,
                        'value_raw' => $feature['value_raw'],
                        'value_presentation' => $feature['value_presentation'],
                        'value_numeric' => $this->numericOf($feature['value_raw']),
                        'sort_order' => $feature['order'],
                    ]);

                    $written++;
                    $searchText[] = $feature['name'].' '.($feature['value_presentation'] ?? $feature['value_raw']);
                }
            }

            // search_text_specs feeds the weight-C term of the generated search
            // vector; it is not maintained automatically, so rebuild it here.
            $product->search_text_specs = Str::limit(implode(' ', $searchText), 8000, '');
        });

        return $written;
    }

    /** @param array{group_id: int|null, group_name: string|null, group_order: int, features: mixed} $group */
    private function resolveGroup(array $group): ?AttributeGroup
    {
        if ($group['group_id'] === null) {
            return null;
        }

        return AttributeGroup::query()->firstOrCreate(
            ['icecat_group_id' => $group['group_id']],
            [
                'uuid' => (string) Str::uuid(),
                'code' => 'icecat-grp-'.$group['group_id'],
                'name' => $group['group_name'] ?? ('Group '.$group['group_id']),
                'display_order' => $group['group_order'],
            ],
        );
    }

    /**
     * @param  array{feature_id: int|null, name: string, unit_symbol: ?string, order: int, searchable: bool, value_raw: string, value_presentation: ?string}  $feature
     */
    private function resolveAttribute(array $feature, ?AttributeGroup $group): Attribute
    {
        $isNumeric = $this->numericOf($feature['value_raw']) !== null;

        $defaults = [
            'uuid' => (string) Str::uuid(),
            'name' => $feature['name'],
            'group_id' => $group?->id,
            'data_type' => $isNumeric ? 'numeric' : 'string',
            'unit_symbol' => $feature['unit_symbol'],
            'is_filterable' => $feature['searchable'],
            'display_order' => $feature['order'],
        ];

        // Prefer Icecat's stable feature id as the key; fall back to a slug of
        // the name when a product carries an unidentified feature.
        if ($feature['feature_id'] !== null) {
            $attribute = Attribute::query()->firstOrCreate(
                ['icecat_feature_id' => $feature['feature_id']],
                ['code' => 'icecat-feat-'.$feature['feature_id']] + $defaults,
            );
        } else {
            $code = 'spec-'.Str::slug($feature['name']);
            $attribute = Attribute::query()->firstOrCreate(['code' => $code], $defaults);
        }

        if ($group !== null && $attribute->group_id === null) {
            $attribute->group_id = $group->id;
            $attribute->save();
        }

        return $attribute;
    }

    private function linkRelations(Product $product, IcecatDocument $document): int
    {
        $linked = 0;
        $sort = 0;

        foreach ($document->relations() as $relation) {
            if ($relation['type'] === null || $relation['mpn'] === null) {
                continue;
            }

            $brand = $relation['brand'] !== null ? $this->brand($relation['brand']) : null;

            $related = Product::query()
                ->where('mpn', $relation['mpn'])
                ->when($brand !== null, fn (Builder $q) => $q->where('brand_id', $brand->id))
                ->first();

            // Only link to products we actually stock; the FK requires the
            // related product to exist, and a rail to one we do not sell is dead.
            if ($related === null || $related->id === $product->id) {
                continue;
            }

            $product->relatedProducts()->syncWithoutDetaching([
                $related->id => [
                    'relation_type' => $relation['type'],
                    'source' => 'icecat',
                    'sort_order' => $sort++,
                ],
            ]);

            $linked++;
        }

        return $linked;
    }

    private function brand(string $name): ?Brand
    {
        $key = Str::lower(trim($name));

        if ($key === '') {
            return null;
        }

        return $this->brandCache[$key] ??= Brand::query()->whereRaw('lower(name) = ?', [$key])->first();
    }

    /** Leading numeric value, for range facets. "20 ppm" → 20.0, "A4" → null. */
    private function numericOf(string $raw): ?float
    {
        if (preg_match('/-?\d+(?:[.,]\d+)?/', $raw, $m) !== 1) {
            return null;
        }

        $value = (float) str_replace(',', '.', $m[0]);

        // value_numeric is DECIMAL(16,4) - a larger number is an EAN/code, not a
        // range facet, and would overflow the column; drop it (value_raw keeps it).
        if (abs($value) >= 1e12) {
            return null;
        }

        return $value;
    }
}
