<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Icecat\IcecatClient;
use App\Icecat\IcecatDocument;
use App\Icecat\IcecatIngestor;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
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

    public function handle(IcecatClient $client, IcecatIngestor $ingestor): int
    {
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
                    $stats = $ingestor->write($product, $document, (bool) $this->option('force'));
                    $this->imagesAttached += $stats['images'];
                    $this->specsWritten += $stats['specs'];
                    $this->relationsLinked += $stats['relations'];
                    $this->line("  enriched: {$product->slug}");
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
