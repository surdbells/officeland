<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Media\DerivativeGenerator;
use App\Media\MediaIngestor;
use App\Models\Category;
use App\Models\Media;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Import images from a directory, with their alt text.
 *
 * ── Why this exists ──────────────────────────────────────────────────────
 * The media pipeline has been complete since Phase 4 — validation, decode
 * probing, deduplication by checksum, EXIF stripping, content-addressed
 * storage, derivative generation — and there was no way to put a file into
 * it. MediaIngestor is a service with no caller outside tests, there is no
 * Filament resource for media, and the media table has been empty since the
 * project began. That is why the entire storefront is typographic: not a
 * design decision, a missing entry point.
 *
 * ── Alt text is required, not optional ───────────────────────────────────
 * §12.6 makes alt text mandatory at attach time, and Media::assertAttachable()
 * enforces it. So this command reads it from a sidecar file next to the image
 * rather than inventing one — "office-chair.jpg" beside "office-chair.txt".
 * An image without its sidecar is skipped and reported, because a made-up alt
 * is a lie to a screen reader and worse than no image.
 *
 * ── Usage ────────────────────────────────────────────────────────────────
 *   php artisan media:import --path=/docs/images
 *   php artisan media:import --path=/docs/images --attach-categories
 *
 * With --attach-categories, a file named after a category path with slashes
 * replaced by dashes — `wpc-doors-interiors-wpc-doors.jpg` — becomes that
 * category's hero image. Naming rather than a mapping file, because a mapping
 * file is one more thing to keep in step with the taxonomy.
 */
final class ImportMedia extends Command
{
    protected $signature = 'media:import
                            {--path= : Directory to read. Defaults to /docs/images.}
                            {--attach-categories : Set each image as the hero of the category its filename names.}
                            {--apply : Write. Without this the command only reports.}';

    protected $description = 'Import images from a directory into the media library';

    private DerivativeGenerator $generator;

    private const EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'avif'];

    public function handle(MediaIngestor $ingestor, DerivativeGenerator $generator): int
    {
        $path = (string) ($this->option('path') ?? '') ?: '/docs/images';

        if (! is_dir($path)) {
            $this->error("No directory at {$path}.");
            $this->line('Put images there with a .txt sidecar each, e.g. chair.jpg and chair.txt.');

            return self::FAILURE;
        }

        $this->generator = $generator;

        $apply = (bool) $this->option("apply");
        $attach = (bool) $this->option('attach-categories');

        $imported = 0;
        $attached = 0;
        $skipped = [];

        foreach ($this->images($path) as $file) {
            $alt = $this->altFor($file);

            if ($alt === null) {
                $skipped[] = basename($file).' — no .txt sidecar with alt text';

                continue;
            }

            if (! $apply) {
                $imported++;

                continue;
            }

            try {
                $media = $ingestor->ingest($file, ['alt_text' => $alt, 'source' => 'manual']);
            } catch (RuntimeException $problem) {
                $skipped[] = basename($file).' — '.$problem->getMessage();

                continue;
            }

            $imported++;

            /*
             * Generate the derivatives, synchronously.
             *
             * Ingestion stores the ORIGINAL and nothing else. Every rendered
             * <picture> is built from rows in media_derivatives — a srcset
             * only ever advertises derivatives that exist (Phase 4) — so an
             * imported original with none is an image the storefront cannot
             * show. The first run of this command imported and attached a
             * category hero that rendered as nothing at all, because the
             * generation is a queued job and the caller never dispatched it.
             *
             * Run here rather than queued: this is a one-shot command, and
             * dispatching to a queue nobody is working leaves the same
             * invisible image with an extra step in between.
             */
            $this->generator->generate($media);

            if ($attach && $this->attachToCategory($file, $media)) {
                $attached++;
            }
        }

        $this->table(['Outcome', 'Files'], [
            [$apply ? 'Imported' : 'Would import', $imported],
            [$apply ? 'Attached as hero' : 'Would attach', $attached],
            ['Skipped', count($skipped)],
        ]);

        foreach (array_slice($skipped, 0, 20) as $problem) {
            $this->warn($problem);
        }

        if (! $apply) {
            $this->comment('Dry run. Add --apply to write, then rebuild the storefront.');
        }

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function images(string $path): array
    {
        $files = [];

        foreach ((array) scandir($path) as $entry) {
            if (! is_string($entry)) {
                continue;
            }

            $file = $path.'/'.$entry;

            if (! is_file($file)) {
                continue;
            }

            if (in_array(strtolower(pathinfo($entry, PATHINFO_EXTENSION)), self::EXTENSIONS, true)) {
                $files[] = $file;
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Alt text from the sidecar, or null when there is none.
     *
     * Trimmed and collapsed, because a sidecar written in an editor picks up
     * a trailing newline and alt text is an attribute value.
     */
    private function altFor(string $file): ?string
    {
        $sidecar = preg_replace('/\.[^.]+$/', '.txt', $file);

        if (! is_string($sidecar) || ! is_file($sidecar)) {
            return null;
        }

        $alt = trim((string) preg_replace('/\s+/', ' ', (string) file_get_contents($sidecar)));

        return $alt === '' ? null : $alt;
    }

    /**
     * Sets the image as the hero of the category its filename names.
     *
     * `office-furniture-seating.jpg` => `/office-furniture/seating/`. Matched
     * against the real path rather than parsed, so a filename that names no
     * category is simply not attached instead of guessing.
     */
    private function attachToCategory(string $file, Media $media): bool
    {
        $stem = pathinfo($file, PATHINFO_FILENAME);

        $category = Category::query()
            ->get(['id', 'path'])
            ->first(fn (Category $candidate): bool => trim(str_replace('/', '-', $candidate->path), '-') === $stem);

        if (! $category instanceof Category) {
            return false;
        }

        $category->forceFill(['hero_media_id' => $media->id])->save();

        return true;
    }
}
