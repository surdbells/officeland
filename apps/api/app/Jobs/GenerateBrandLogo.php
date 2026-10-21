<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Brand\BrandLogoGenerator;
use App\Icecat\IcecatClient;
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
     * Download this brand's Icecat supplier logo to a temp file, or null when
     * the brand has no Icecat product or Icecat carries no logo for it.
     */
    private function downloadIcecatLogo(IcecatClient $client, Brand $brand): ?string
    {
        $product = Product::query()
            ->where('brand_id', $brand->id)
            ->whereNotNull('icecat_id')
            ->latest('id')
            ->first();

        if ($product === null) {
            return null;
        }

        $url = $client->fetchById((int) $product->icecat_id)?->supplierLogoUrl();

        if ($url === null) {
            return null;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'brandlogo');
        $response = Http::timeout((int) config('icecat.timeout', 45))->sink($tmp)->get($url);

        if (! $response->successful() || (filesize($tmp) ?: 0) < 512) {
            @unlink($tmp);

            return null;
        }

        return $tmp;
    }
}
