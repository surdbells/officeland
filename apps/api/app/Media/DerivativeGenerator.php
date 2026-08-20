<?php

declare(strict_types=1);

namespace App\Media;

use App\Models\Media;
use App\Models\MediaDerivative;
use Jcupitt\Vips\Config as VipsConfig;
use Jcupitt\Vips\Image as VipsImage;
use RuntimeException;

/**
 * Generates the derivative set for one original, using libvips.
 *
 * Driver choice, measured on a real 1024x1536 product photograph producing the
 * full 5-variant x 3-format set (see infra/docker/frankenphp/Dockerfile):
 *
 *   Imagick   4977 ms   1,414 KB
 *   GD        1585 ms   1,306 KB
 *   libvips   3371 ms     808 KB
 *
 * libvips is ~40% smaller at equivalent quality. Generation is a one-time
 * build cost; bytes are paid by every visitor on metered mobile data forever,
 * against an 800 KB total PDP budget (section 14.9). GD is fastest and would
 * blow that budget on images alone.
 *
 * Idempotent and resumable by construction: each derivative is checked for
 * existence before work begins, so a job killed halfway resumes where it
 * stopped rather than redoing 1.1M operations.
 */
final class DerivativeGenerator
{
    public function __construct(private readonly MediaStorage $storage) {}

    /**
     * @return array{generated: int, skipped: int, failed: int}
     */
    public function generate(Media $media, bool $force = false): array
    {
        // libvips threads internally. Letting it do so inside a queue worker
        // that is already running several jobs oversubscribes the CPU and makes
        // a bulk run slower, not faster.
        VipsConfig::concurrencySet((int) config('media.vips_concurrency', 1));

        $source = $this->storage->absolute($media->path);

        if (! is_file($source)) {
            throw new RuntimeException("Original is missing on disk: {$media->path}");
        }

        $generated = 0;
        $skipped = 0;
        $failed = 0;

        /** @var array<string, array<string, mixed>> $variants */
        $variants = config('media.variants');
        /** @var array<string, array<string, mixed>> $formats */
        $formats = config('media.formats');

        foreach ($variants as $variantKey => $variant) {
            $width = (int) $variant['width'];

            // Never upscale. Enlarging a 400px supplier image to 1600px
            // produces a larger file that looks worse.
            if ($media->width !== null && $width > $media->width) {
                $width = (int) $media->width;
            }

            $thumbnail = $this->thumbnail($source, $variant, $width);

            foreach ($formats as $format => $settings) {
                $path = $this->storage->derivativePath(
                    $media->checksum_sha256,
                    $variantKey,
                    $width,
                    $format,
                );

                $existing = MediaDerivative::query()
                    ->where('media_id', $media->id)
                    ->where('variant_key', $variantKey)
                    ->where('format', $format)
                    ->first();

                if (! $force && $existing !== null && $this->storage->exists($path)) {
                    $skipped++;

                    continue;
                }

                try {
                    $this->write($thumbnail, $path, $format, (int) $settings['quality']);

                    MediaDerivative::query()->updateOrCreate(
                        ['media_id' => $media->id, 'variant_key' => $variantKey, 'format' => $format],
                        [
                            'width' => $thumbnail->width,
                            'height' => $thumbnail->height,
                            'path' => $path,
                            'bytes' => $this->storage->size($path),
                        ],
                    );

                    $generated++;
                } catch (\Throwable $e) {
                    $failed++;

                    report(new RuntimeException(
                        "Derivative {$variantKey}/{$format} failed for media {$media->uuid}: ".$e->getMessage(),
                        0,
                        $e,
                    ));
                }
            }
        }

        return ['generated' => $generated, 'skipped' => $skipped, 'failed' => $failed];
    }

    /**
     * @param  array<string, mixed>  $variant
     */
    private function thumbnail(string $source, array $variant, int $width): VipsImage
    {
        $options = ['height' => 100_000];

        // The OG card is a fixed 1200x630 frame, so it crops to fill rather
        // than letterboxing. Everything else preserves aspect ratio.
        if (($variant['crop'] ?? false) === true && isset($variant['height'])) {
            $options = [
                'height' => (int) $variant['height'],
                'crop' => 'attention',
            ];
        }

        // copyMemory() is load-bearing, not an optimisation.
        //
        // VipsImage::thumbnail() returns a LAZILY EVALUATED image. Each of the
        // three format writes that follow re-runs the pipeline, and the source
        // is opened for sequential access — readable once. The second and third
        // writes then fail with "error in tile", which libvips reports as a
        // warning on stderr rather than an exception, so derivatives went
        // missing silently and only appeared on a later re-run.
        //
        // Materialising the thumbnail once means all three encodes read from
        // memory, which is also why one decode serves fifteen derivatives.
        return VipsImage::thumbnail($source, $width, $options)->copyMemory();
    }

    /**
     * Writes one derivative atomically.
     *
     * libvips selects its encoder from the FILENAME EXTENSION, and options ride
     * in a `[...]` suffix that is not part of the path. So the temporary file
     * must already carry the correct extension — a generic `.tmp` name would
     * make libvips fail to choose an encoder.
     *
     * Atomicity matters because Caddy serves this directory while generation is
     * still running: without it, a request landing mid-write gets a truncated
     * image, and browsers cache broken images as readily as good ones.
     */
    private function write(VipsImage $image, string $path, string $format, int $quality): void
    {
        $absolute = $this->storage->absolute($path);
        $this->storage->ensureDirectory(dirname($absolute));

        [$extension, $options] = match ($format) {
            'avif' => ['avif', "[Q={$quality}]"],
            'webp' => ['webp', "[Q={$quality}]"],
            'jpeg' => ['jpg', "[Q={$quality},optimize_coding=true,strip=true]"],
            default => throw new RuntimeException("Unsupported derivative format: {$format}"),
        };

        $temporary = sprintf('%s.tmp-%s.%s', $absolute, bin2hex(random_bytes(6)), $extension);

        try {
            $image->writeToFile($temporary.$options);

            if (! is_file($temporary)) {
                throw new RuntimeException('libvips produced no output.');
            }

            if (! @rename($temporary, $absolute)) {
                throw new RuntimeException('Could not move the derivative into place.');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }
}
