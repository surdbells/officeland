<?php

declare(strict_types=1);

namespace App\Media;

use App\Models\Media;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Jcupitt\Vips\Image as VipsImage;
use RuntimeException;

/**
 * Validates, deduplicates and stores an original image.
 *
 * The order of operations matters and is deliberate:
 *
 *   1. size check          — cheapest rejection first
 *   2. MIME by CONTENT     — never by extension or declared type
 *   3. decode probe        — a file can carry a valid magic number and still
 *                            not be a decodable image
 *   4. SHA-256             — computed on the ORIGINAL bytes
 *   5. dedupe              — identical bytes are stored once, however many
 *                            products reference them
 *   6. strip metadata      — EXIF can carry GPS coordinates and camera serials
 *   7. content-addressed write
 *
 * Deduplication is not a micro-optimisation here. Suppliers ship the same
 * photograph across a whole product family, and Icecat serves one image to
 * every reseller; at 75,000 originals the duplicate rate is material.
 */
final class MediaIngestor
{
    public function __construct(private readonly MediaStorage $storage) {}

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws RuntimeException when the file is not acceptable
     */
    public function ingest(string $sourcePath, array $attributes = []): Media
    {
        if (! is_file($sourcePath)) {
            throw new RuntimeException("Source file not found: {$sourcePath}");
        }

        $bytes = filesize($sourcePath);

        if ($bytes === false || $bytes === 0) {
            throw new RuntimeException('Source file is empty.');
        }

        $max = (int) config('media.max_bytes');

        if ($bytes > $max) {
            throw new RuntimeException(
                sprintf('File is %s bytes; the limit is %s.', number_format($bytes), number_format($max))
            );
        }

        $mime = $this->detectMime($sourcePath);
        $this->assertAcceptable($mime);

        [$width, $height] = $this->probeDimensions($sourcePath);

        $checksum = hash_file('sha256', $sourcePath);

        if ($checksum === false) {
            throw new RuntimeException('Could not hash the source file.');
        }

        // Identical bytes are one media record. Returning the existing row is
        // correct rather than an error: two products legitimately share a photo.
        $existing = Media::query()->where('checksum_sha256', $checksum)->first();

        if ($existing !== null) {
            Log::debug('media deduplicated', ['checksum' => $checksum, 'uuid' => $existing->uuid]);

            return $existing;
        }

        $path = $this->storage->originalPath($checksum, $this->extensionFor($mime));

        // Metadata is stripped on write. EXIF routinely carries GPS
        // coordinates, camera serial numbers and editing history — none of it
        // belongs on a public product image (section 12.1).
        $this->storage->writeOriginalStripped($sourcePath, $path);

        $storedBytes = $this->storage->size($path);

        return DB::transaction(fn (): Media => Media::create([
            'disk' => 'media',
            'path' => $path,
            'original_filename' => $attributes['original_filename'] ?? basename($sourcePath),
            'mime' => $mime,
            'width' => $width,
            'height' => $height,
            'bytes' => $storedBytes,
            'checksum_sha256' => $checksum,
            'alt_text' => $attributes['alt_text'] ?? null,
            'credit' => $attributes['credit'] ?? null,
            'is_watermarked_flag' => $attributes['is_watermarked_flag'] ?? false,
            'source' => $attributes['source'] ?? 'manual',
            'source_url' => $attributes['source_url'] ?? null,
        ]));
    }

    /**
     * MIME from file content.
     *
     * finfo reads magic bytes. The extension and the client-declared type are
     * both attacker-controlled and are never consulted.
     */
    private function detectMime(string $path): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            throw new RuntimeException('fileinfo is unavailable; MIME cannot be verified by content.');
        }

        $mime = finfo_file($finfo, $path);
        finfo_close($finfo);

        if ($mime === false) {
            throw new RuntimeException('Could not determine the file type.');
        }

        return $mime;
    }

    private function assertAcceptable(string $mime): void
    {
        $accepted = (array) config('media.accepted_mimes');

        if (in_array($mime, $accepted, true)) {
            return;
        }

        // Named explicitly because it is the case people expect to work.
        if ($mime === 'image/svg+xml' || $mime === 'text/xml' || $mime === 'application/xml') {
            throw new RuntimeException(
                'SVG is not accepted: it is an XML document that can carry script and external references.'
            );
        }

        throw new RuntimeException("Unsupported file type: {$mime}.");
    }

    /**
     * A file can carry a valid magic number and still fail to decode. Probing
     * here means a corrupt upload is rejected at the boundary rather than
     * failing later inside a queued derivative job, where the user is gone.
     *
     * @return array{int, int}
     */
    private function probeDimensions(string $path): array
    {
        try {
            $image = VipsImage::newFromFile($path, ['access' => 'sequential']);
            $width = $image->width;
            $height = $image->height;
        } catch (\Throwable $e) {
            throw new RuntimeException('File is not a decodable image: '.$e->getMessage(), 0, $e);
        }

        $min = (int) config('media.min_dimension');

        if ($width < $min || $height < $min) {
            throw new RuntimeException(
                "Image is {$width}x{$height}; the minimum is {$min}px on each side."
            );
        }

        return [$width, $height];
    }

    private function extensionFor(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/avif' => 'avif',
            'image/gif' => 'gif',
            default => 'bin',
        };
    }
}
