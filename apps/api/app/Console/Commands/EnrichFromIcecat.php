<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Icecat\IcecatClient;
use App\Icecat\IcecatDocument;
use App\Media\DerivativeGenerator;
use App\Media\MediaIngestor;
use App\Models\Attribute;
use App\Models\AttributeGroup;
use App\Models\Brand;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Enrich catalogue products from Icecat: images, grouped specifications and
 * typed relations (Phase 5b, docs/ICECAT_MAPPING.md).
 *
 *   php artisan catalogue:icecat-enrich                 # dry run - reports only
 *   php artisan catalogue:icecat-enrich --apply         # write
 *   php artisan catalogue:icecat-enrich --dump --limit=1  # inspect one parse
 *
 * ── What it writes, and what it does not ─────────────────────────────────
 * FACTS are published: images (mirrored into the content-addressed store with
 * derivatives), grouped specifications, typed relations to products we already
 * stock, warranty text, EAN and the Icecat id. PROSE is not: the title and
 * descriptions are stored only as source_title / source_description drafts and
 * never become the product's public name or copy (D-015). Every Icecat
 * subscriber receives identical prose, and publishing it verbatim recreates the
 * legacy duplicate-content defect at scale.
 *
 * Idempotent: a product is matched on Icecat id → MPN + brand → EAN, specs are
 * replaced wholesale on each run, images are deduplicated by checksum, and
 * relations use syncWithoutDetaching. Re-running is safe and only --force
 * re-visits products already synced.
 *
 * Runs only where Icecat is reachable (the client's whitelisted IP), which is
 * the production server, not a developer machine.
 */
final class EnrichFromIcecat extends Command
{
    protected $signature = 'catalogue:icecat-enrich
        {--apply : Write changes. Without this the command only reports what it would do.}
        {--limit=0 : Maximum products to process (0 = no limit).}
        {--brand= : Restrict to one brand name (ilike).}
        {--skus= : Comma-separated SKUs to process, ignoring the usual selection.}
        {--force : Re-visit products already synced (icecat_synced_at set).}
        {--missing-images : Only products that currently have no image.}
        {--dump : Print the parsed Icecat document for each match and write nothing.}
        {--sleep=250 : Milliseconds to pause between products, to stay polite to Icecat.}';

    protected $description = 'Enrich products from Icecat (images, specifications, relations)';

    private int $matched = 0;
    private int $missed = 0;
    private int $imagesAttached = 0;
    private int $specsWritten = 0;
    private int $relationsLinked = 0;

    /** @var array<string, Brand> name(lower) => brand */
    private array $brandCache = [];

    public function handle(
        IcecatClient $client,
        MediaIngestor $ingestor,
        DerivativeGenerator $generator,
    ): int {
        if ((string) config('icecat.username') === '') {
            $this->warn('ICECAT_USERNAME is not set; relying on IP whitelisting alone. '.
                'If every lookup misses, that is the likely cause.');
        }

        $apply = (bool) $this->option('apply');
        $dump = (bool) $this->option('dump');
        $sleepMs = (int) $this->option('sleep');

        $products = $this->candidates()->cursor();
        $processed = 0;

        foreach ($products as $product) {
            $processed++;

            try {
                $document = $this->fetch($client, $product);
            } catch (Throwable $e) {
                // A connectivity or credentials error on one product must not
                // abort a long batch; surface it and move on.
                $this->warn("  error: {$product->slug} - {$e->getMessage()}");
                $this->missed++;
                $this->pause($sleepMs);

                continue;
            }

            if ($document === null) {
                $this->missed++;
                $this->line("  miss: {$product->slug} ({$this->matchKeyLabel($product)})");
                $this->pause($sleepMs);

                continue;
            }

            // Icecat can answer with a product shell that carries no id, image
            // or specification - the data-sheet is absent at this tier, or the
            // identifier matched only a placeholder. There is nothing to ingest,
            // so it is a miss, not a match, and must not overwrite the product.
            if ($this->isEmpty($document)) {
                $this->missed++;
                $this->line("  miss (no Icecat data): {$product->slug} ({$this->matchKeyLabel($product)})");
                $this->pause($sleepMs);

                continue;
            }

            $this->matched++;

            if ($dump) {
                $this->dump($product, $document);
                $this->pause($sleepMs);

                continue;
            }

            if ($apply) {
                try {
                    $this->ingest($product, $document, $ingestor, $generator);
                } catch (Throwable $e) {
                    $this->warn("  failed: {$product->slug} - {$e->getMessage()}");
                }
            } else {
                $this->line(sprintf(
                    '  would enrich: %s (%d images, %d specs, %d relations)',
                    $product->slug,
                    count($document->imageUrls()),
                    $this->countFeatures($document),
                    count($document->relations()),
                ));
            }

            $this->pause($sleepMs);
        }

        $this->report($processed, $apply, $dump, $client);

        return self::SUCCESS;
    }

    /** @return Builder<Product> */
    private function candidates(): Builder
    {
        $skus = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('skus')))));

        return Product::query()
            ->with('brand')
            ->when($skus !== [], fn (Builder $q) => $q->whereIn('sku', $skus))
            ->when($skus === [], function (Builder $q): void {
                // A usable match key must exist: Icecat id, or MPN with a brand,
                // or an EAN. Everything else cannot be looked up.
                $q->where(function (Builder $inner): void {
                    $inner->whereNotNull('icecat_id')
                        ->orWhere(fn (Builder $m) => $m->whereNotNull('mpn')->whereNotNull('brand_id'))
                        ->orWhereNotNull('ean');
                });

                if (! $this->option('force')) {
                    $q->whereNull('icecat_synced_at');
                }

                if ($this->option('missing-images')) {
                    $q->whereDoesntHave('media');
                }

                $brand = (string) $this->option('brand');

                if ($brand !== '') {
                    $q->whereHas('brand', fn (Builder $b) => $b->where('name', 'ilike', $brand));
                }
            })
            ->orderBy('id')
            ->when((int) $this->option('limit') > 0, fn (Builder $q) => $q->limit((int) $this->option('limit')));
    }

    private function fetch(IcecatClient $client, Product $product): ?IcecatDocument
    {
        if ($product->icecat_id !== null) {
            $doc = $client->fetchById((int) $product->icecat_id);

            if ($doc !== null) {
                return $doc;
            }
        }

        $brand = $product->brand?->name;

        if ($product->mpn !== null && $brand !== null && $brand !== '') {
            $doc = $client->fetchByMpn($product->mpn, $brand);

            if ($doc !== null) {
                return $doc;
            }
        }

        if ($product->ean !== null && $product->ean !== '') {
            return $client->fetchByEan($product->ean);
        }

        return null;
    }

    private function ingest(
        Product $product,
        IcecatDocument $document,
        MediaIngestor $ingestor,
        DerivativeGenerator $generator,
    ): void {
        $this->attachImages($product, $document, $ingestor, $generator);
        $this->writeSpecifications($product, $document);
        $this->linkRelations($product, $document);

        // Product-level facts and drafts. Prose stays in source_* (draft only);
        // only warranty, identifiers and provenance become published facts.
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
        $product->save();

        $this->line("  enriched: {$product->slug}");
    }

    private function attachImages(
        Product $product,
        IcecatDocument $document,
        MediaIngestor $ingestor,
        DerivativeGenerator $generator,
    ): void {
        // Skip when the product already has images and we are not forcing - the
        // derivative generation is the expensive part and need not repeat.
        if (! $this->option('force') && $product->media()->exists()) {
            return;
        }

        $sort = 0;

        foreach ($document->imageUrls() as $url) {
            $tmp = null;

            try {
                $response = Http::timeout((int) config('icecat.timeout', 45))->retry(2, 1500, throw: false)->get($url);

                if (! $response->successful()) {
                    continue;
                }

                $ext = pathinfo((string) (parse_url($url, PHP_URL_PATH) ?: ''), PATHINFO_EXTENSION) ?: 'jpg';
                $tmp = tempnam(sys_get_temp_dir(), 'icecat').'.'.strtolower($ext);
                file_put_contents($tmp, $response->body());

                $media = $ingestor->ingest($tmp, [
                    'alt_text' => $product->name,
                    'source' => 'icecat',
                    'source_url' => $url,
                ]);

                $generator->generate($media);

                $product->media()->syncWithoutDetaching([
                    $media->id => [
                        // The first image is the primary (the LCP element); the
                        // rest are the gallery (Product::media orders primary first).
                        'role' => $sort === 0 ? 'primary' : 'gallery',
                        'sort_order' => $sort,
                    ],
                ]);

                $this->imagesAttached++;
                $sort++;
            } catch (Throwable $e) {
                $this->warn("    image failed ({$url}): {$e->getMessage()}");
            } finally {
                if ($tmp !== null && is_file($tmp)) {
                    @unlink($tmp);
                }
            }
        }
    }

    private function writeSpecifications(Product $product, IcecatDocument $document): void
    {
        $groups = $document->featureGroups();

        if ($groups === []) {
            return;
        }

        DB::transaction(function () use ($product, $groups): void {
            // Replace wholesale so the spec table always matches Icecat's
            // current data rather than accreting stale rows across runs.
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

                    $this->specsWritten++;
                    $searchText[] = $feature['name'].' '.($feature['value_presentation'] ?? $feature['value_raw']);
                }
            }

            // search_text_specs feeds the weight-C term of the generated search
            // vector; it is not maintained automatically, so rebuild it here.
            $product->search_text_specs = Str::limit(implode(' ', $searchText), 8000, '');
        });
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

        // Backfill the group on an attribute first seen without one.
        if ($group !== null && $attribute->group_id === null) {
            $attribute->group_id = $group->id;
            $attribute->save();
        }

        return $attribute;
    }

    private function linkRelations(Product $product, IcecatDocument $document): void
    {
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
            // related product to exist, and a rail to a product we do not sell
            // is a dead end.
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

            $this->relationsLinked++;
        }
    }

    private function brand(string $name): ?Brand
    {
        $key = Str::lower(trim($name));

        if ($key === '') {
            return null;
        }

        if (array_key_exists($key, $this->brandCache)) {
            return $this->brandCache[$key];
        }

        return $this->brandCache[$key] = Brand::query()->whereRaw('lower(name) = ?', [$key])->first();
    }

    /** Leading numeric value, for range facets. "20 ppm" → 20.0, "A4" → null. */
    private function numericOf(string $raw): ?float
    {
        if (preg_match('/-?\d+(?:[.,]\d+)?/', $raw, $m) !== 1) {
            return null;
        }

        $value = (float) str_replace(',', '.', $m[0]);

        // value_numeric is DECIMAL(16,4) - twelve integer digits at most. A
        // larger number is never a real range facet (it is an EAN or other code
        // Icecat carries as a spec value) and would overflow the column, failing
        // the whole product's spec write. Drop it; value_raw still holds it.
        if (abs($value) >= 1e12) {
            return null;
        }

        return $value;
    }

    private function countFeatures(IcecatDocument $document): int
    {
        return array_sum(array_map(static fn ($g) => count($g['features']), $document->featureGroups()));
    }

    /** A response with nothing worth ingesting: no id, no image, no spec. */
    private function isEmpty(IcecatDocument $document): bool
    {
        return $document->icecatId() === null
            && $document->imageUrls() === []
            && $document->featureGroups() === [];
    }

    private function matchKeyLabel(Product $product): string
    {
        if ($product->icecat_id !== null) {
            return 'id '.$product->icecat_id;
        }

        if ($product->mpn !== null && $product->brand !== null) {
            return $product->brand->name.' '.$product->mpn;
        }

        return 'ean '.($product->ean ?? '?');
    }

    private function dump(Product $product, IcecatDocument $document): void
    {
        $this->info("── {$product->slug}");
        $this->line('  icecat_id: '.($document->icecatId() ?? '-'));
        $this->line('  brand/mpn: '.($document->brandName() ?? '-').' / '.($document->mpn() ?? '-'));
        $this->line('  title:     '.Str::limit((string) $document->title(), 80));
        $this->line('  images:    '.count($document->imageUrls()));
        $this->line('  specs:     '.$this->countFeatures($document).' in '.count($document->featureGroups()).' groups');
        $this->line('  relations: '.count($document->relations()));
    }

    private function pause(int $ms): void
    {
        if ($ms > 0) {
            usleep($ms * 1000);
        }
    }

    private function report(int $processed, bool $apply, bool $dump, IcecatClient $client): void
    {
        $this->newLine();
        $this->table(['Metric', 'Count'], [
            ['Processed', $processed],
            ['Matched on Icecat', $this->matched],
            ['Missed', $this->missed],
            [$apply ? 'Images attached' : 'Images (would)', $this->imagesAttached],
            [$apply ? 'Spec values written' : 'Spec values (would)', $this->specsWritten],
            [$apply ? 'Relations linked' : 'Relations (would)', $this->relationsLinked],
        ]);

        if ($processed > 0 && $this->matched === 0 && $client->sawOnlyNotFound()) {
            $this->warn(
                'No product matched Icecat. When EVERY lookup fails like this the cause is almost '.
                'always access, not an absent catalogue: a 401 means the credentials are wrong or '.
                'missing, and a 404 means this server\'s IP is not whitelisted (Icecat returns 404, '.
                'not 403, for that). Confirm ICECAT_USERNAME/ICECAT_PASSWORD in .env and that this '.
                "server's IP is whitelisted in the Icecat account, then re-run."
            );
        }

        if (! $apply && ! $dump) {
            $this->newLine();
            $this->info('Dry run. Re-run with --apply to write.');
        }
    }
}
