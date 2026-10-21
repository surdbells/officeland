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
}
