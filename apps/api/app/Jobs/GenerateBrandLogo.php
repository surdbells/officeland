<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Brand\BrandLogoGenerator;
use App\Icecat\IcecatClient;
use App\Icecat\IcecatSuppliers;
use App\Media\DerivativeGenerator;
use App\Media\MediaIngestor;
use App\Models\Brand;
use App\Models\Product;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Give one brand a logo: a real Icecat supplier logo where we can get it, a
 * generated monogram otherwise - so no brand is ever left without one.
 *
 * Queued and per-brand so a "logo every brand" sweep runs on the worker, off
 * the request path, and so each brand's image work (ingest + derivatives) is
 * isolated. Idempotent: a brand that already has a logo is skipped unless forced.
 */
final class GenerateBrandLogo implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    public int $uniqueFor = 1800;

    public function __construct(
        public readonly int $brandId,
        public readonly bool $force = false,
    ) {}

    public function uniqueId(): string
    {
        return 'brand-logo:'.$this->brandId;
    }

    public function handle(IcecatClient $client, MediaIngestor $ingestor, DerivativeGenerator $derivatives, BrandLogoGenerator $generator): void
    {
        @ini_set('memory_limit', '512M');

        $brand = Brand::find($this->brandId);

        if ($brand === null || ($brand->logo_media_id !== null && ! $this->force)) {
            return;
        }

        $path = null;
        $realLogo = false;

        try {
            $path = $this->downloadIcecatLogo($client, $brand);
            $realLogo = $path !== null;
        } catch (Throwable $e) {
            Log::info('brand logo: icecat source failed, using monogram', ['brand' => $brand->slug, 'error' => $e->getMessage()]);
        }

        if ($path === null) {
            $path = $generator->monogram($brand->name);
        }

        try {
            $media = $ingestor->ingest($path, [
                'alt_text' => $brand->name.' logo',
                'source' => 'manual',
            ]);

            $derivatives->generate($media);

            $brand->update(['logo_media_id' => $media->id]);

            // The megamenu caches brand logos for an hour; drop it so a newly
            // logo'd brand shows at the next build without waiting out the TTL.
            Cache::forget('api:navigation:v1');

            Log::info('brand logo set', ['brand' => $brand->slug, 'source' => $realLogo ? 'icecat' : 'monogram']);
        } finally {
            @unlink($path);
        }
    }

    /**
     * Download this brand's real Icecat logo to a temp file, or null when none
     * can be found. The suppliers reference is tried first (it carries logos at
     * every tier); the per-product <Supplier> element is the last resort because
     * the open tier usually omits its logo attributes.
     */
    private function downloadIcecatLogo(IcecatClient $client, Brand $brand): ?string
    {
        $url = $this->resolveLogoUrl($client, $brand);

        if ($url === null) {
            return null;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'brandlogo');
        $response = Http::withBasicAuth((string) config('icecat.username'), (string) config('icecat.password'))
            ->timeout((int) config('icecat.timeout', 45))
            ->sink($tmp)
            ->get($url);

        if (! $response->successful() || (filesize($tmp) ?: 0) < 512) {
            @unlink($tmp);

            return null;
        }

        return $tmp;
    }

    /**
     * The best logo URL for the brand: the suppliers reference by Icecat supplier
     * id, then by brand name, then the brand's newest Icecat data-sheet.
     */
    private function resolveLogoUrl(IcecatClient $client, Brand $brand): ?string
    {
        $supplierId = $this->icecatSupplierId($brand);
        if ($supplierId !== null && ($url = IcecatSuppliers::logoForId($supplierId)) !== null) {
            return $url;
        }

        foreach ([$brand->icecat_supplier_name, $brand->name] as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate !== '' && ($url = IcecatSuppliers::logoForName($candidate)) !== null) {
                return $url;
            }
        }

        $product = Product::query()
            ->where('brand_id', $brand->id)
            ->whereNotNull('icecat_id')
            ->latest('id')
            ->first();

        return $product !== null
            ? $client->fetchById((int) $product->icecat_id)?->supplierLogoUrl()
            : null;
    }

    /**
     * The Icecat supplier id configured for this brand, matched on the brand's
     * Icecat supplier name, display name or slug against config('icecat.suppliers').
     */
    private function icecatSupplierId(Brand $brand): ?int
    {
        /** @var array<string, int> $suppliers */
        $suppliers = (array) config('icecat.suppliers');

        $normalised = [];
        foreach ($suppliers as $key => $id) {
            $normalised[$this->normalise((string) $key)] = (int) $id;
        }

        foreach ([$brand->icecat_supplier_name, $brand->name, $brand->slug] as $candidate) {
            $key = $this->normalise((string) $candidate);
            if ($key !== '' && isset($normalised[$key])) {
                return $normalised[$key];
            }
        }

        return null;
    }

    private function normalise(string $value): string
    {
        return preg_replace('/[^a-z0-9]+/', '', strtolower(trim($value))) ?? '';
    }
}
