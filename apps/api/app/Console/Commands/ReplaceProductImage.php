<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\GenerateMediaDerivatives;
use App\Media\MediaIngestor;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Swap a product's primary image for a clean replacement.
 *
 * Made for the Snimay watermark clean-up: the badge is erased off the original
 * photo (AI retouch), and the cleaned file has to take the original's place on
 * the product. The replacement goes through the normal media pipeline (checks,
 * EXIF strip, content-addressed store, queued derivatives), keeps the old
 * image's alt text, and becomes the primary at the same position. The old
 * image is detached, not deleted - media:reap removes the file once nothing
 * uses it, so a bad swap is undone by attaching the old media id again.
 *
 *   php artisan catalogue:replace-image rio-town --url=https://… # dry run
 *   php artisan catalogue:replace-image rio-town --file=/tmp/clean.png --apply
 */
final class ReplaceProductImage extends Command
{
    protected $signature = 'catalogue:replace-image
        {product : Product slug or id.}
        {--file= : Local path of the clean image.}
        {--url= : Or a URL to download it from.}
        {--apply : Write. Without this it only reports what it would do.}';

    protected $description = "Replace a product's primary image with a clean file (e.g. a de-watermarked one)";

    public function handle(MediaIngestor $ingestor): int
    {
        $key = (string) $this->argument('product');
        $product = Product::query()
            ->where('slug', trim($key, '/'))
            ->when(ctype_digit($key), fn ($q) => $q->orWhere('id', (int) $key))
            ->first();

        if ($product === null) {
            $this->error("No product matches '{$key}'.");

            return self::INVALID;
        }

        $file = (string) $this->option('file');
        $url = (string) $this->option('url');
        if (($file === '') === ($url === '')) {
            $this->error('Give exactly one of --file or --url.');

            return self::INVALID;
        }

        $old = $product->media()->orderByDesc('product_media.role')->orderBy('product_media.sort_order')->first();
        $alt = $old?->alt_text ?: $product->name;

        $this->line("Product: {$product->name}  (/p/{$product->slug}/)");
        $this->line('Current primary image: '.($old ? "media #{$old->id} {$old->path}" : 'none'));

        if (! $this->option('apply')) {
            $this->comment('Dry run. Add --apply to swap the image.');

            return self::SUCCESS;
        }

        $tmp = null;
        try {
            if ($url !== '') {
                $tmp = tempnam(sys_get_temp_dir(), 'clean').'.'.(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'png');
                $response = Http::timeout(60)->retry(2, 1500, throw: false)->sink($tmp)->get($url);
                if (! $response->successful()) {
                    $this->error("Download failed ({$response->status()}). Magnific links expire - fetch a fresh one.");

                    return self::FAILURE;
                }
                $file = $tmp;
            }

            $media = $ingestor->ingest($file, [
                'alt_text' => $alt,
                'source' => 'retouch',
                'source_url' => $url !== '' ? strtok($url, '?') : null,
            ]);

            DB::transaction(function () use ($product, $old, $media): void {
                if ($old !== null) {
                    $product->media()->detach($old->id);
                }
                $product->media()->syncWithoutDetaching([$media->id => ['role' => 'primary', 'sort_order' => 0]]);
            });

            GenerateMediaDerivatives::dispatch($media->id);

            // A pivot change does not fire the model's saved event, so touch the
            // product to re-render its page and the listings that show it.
            $product->touch();
        } catch (Throwable $e) {
            $this->error('Swap failed: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            if ($tmp !== null) {
                @unlink($tmp);
            }
        }

        $this->info("Swapped: media #{$media->id} is now the primary image.".($old ? " Old media #{$old->id} detached (undo by re-attaching it)." : ''));
        $this->line('Derivatives are queued on the media worker; the page shows the original size until they land.');

        return self::SUCCESS;
    }
}
