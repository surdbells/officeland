<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Media\DerivativeGenerator;
use App\Media\MediaIngestor;
use App\Models\Media;
use Illuminate\Support\Facades\Storage;

/**
 * Turns a Filament file upload into a stored, derivative-ready Media.
 *
 * Admin image uploads (product gallery, category hero, brand logo) must take
 * the same path as the CSV and Icecat ingests: MediaIngestor content-addresses
 * and deduplicates the original, then derivatives are generated so the
 * storefront's <picture> has a srcset. A raw Filament disk upload would skip
 * all of that and render as nothing. This centralises that one translation.
 */
final class ImageUploads
{
    /**
     * Ingest a file the FileUpload component wrote to the 'local' disk, generate
     * its derivatives, and return the Media. The temp upload is always removed.
     *
     * @param  array{file: string, alt_text: string}  $data
     */
    public static function ingest(array $data): Media
    {
        $relative = (string) $data['file'];
        $path = Storage::disk('local')->path($relative);

        try {
            $media = app(MediaIngestor::class)->ingest($path, [
                'alt_text' => $data['alt_text'],
                'source' => 'manual',
            ]);

            app(DerivativeGenerator::class)->generate($media);

            return $media;
        } finally {
            Storage::disk('local')->delete($relative);
        }
    }

    /**
     * Fold a form's optional image upload into a media_id, inside a create/edit
     * mutate hook.
     *
     * The form carries a temporary upload under $fileKey and its alt text under
     * $altKey. When one was uploaded it is ingested, derivatives generated, and
     * $mediaKey set to the stored Media id; the two virtual fields are always
     * stripped so they never reach the model. A blank upload leaves media_id
     * untouched - so editing a record without re-uploading keeps its image.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function mutate(
        array $data,
        string $altFallback = 'Image',
        string $fileKey = 'image_upload',
        string $altKey = 'image_alt',
        string $mediaKey = 'media_id',
    ): array {
        $file = $data[$fileKey] ?? null;
        $alt = trim((string) ($data[$altKey] ?? ''));
        unset($data[$fileKey], $data[$altKey]);

        if (is_string($file) && $file !== '') {
            $media = self::ingest([
                'file' => $file,
                'alt_text' => $alt !== '' ? $alt : $altFallback,
            ]);
            $data[$mediaKey] = $media->id;
        }

        return $data;
    }
}
