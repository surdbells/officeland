<?php

declare(strict_types=1);

namespace App\Media;

use Jcupitt\Vips\Image as VipsImage;
use RuntimeException;

/**
 * Content-addressed media storage.
 *
 *   {root}/{sha[0:2]}/{sha[2:4]}/{sha}/original.{ext}
 *   {root}/{sha[0:2]}/{sha[2:4]}/{sha}/{variant}-{w}.{format}
 *
 * The two-level fan-out yields 65,536 leaf directories. At ~1.1M derivative
 * files that is ~18 files per directory — comfortable for any filesystem, and
 * the reason a flat or date-based layout was rejected (section 12).
 *
 * Because the path contains the hash of the content, a URL can never point at
 * different bytes. That is what makes `max-age=31536000, immutable` safe.
 */
final class MediaStorage
{
    public function root(): string
    {
        return rtrim((string) config('media.root'), '/');
    }

    public function directoryFor(string $checksum): string
    {
        return sprintf('%s/%s/%s', substr($checksum, 0, 2), substr($checksum, 2, 2), $checksum);
    }

    public function originalPath(string $checksum, string $extension): string
    {
        return $this->directoryFor($checksum).'/original.'.$extension;
    }

    public function derivativePath(string $checksum, string $variant, int $width, string $format): string
    {
        return sprintf('%s/%s-%d.%s', $this->directoryFor($checksum), $variant, $width, $format);
    }

    /** Public URL. Served by Caddy directly; PHP is never in the image hot path. */
    public function url(string $path): string
    {
        return rtrim((string) config('media.public_prefix'), '/').'/'.ltrim($path, '/');
    }

    public function absolute(string $path): string
    {
        return $this->root().'/'.ltrim($path, '/');
    }

    public function exists(string $path): bool
    {
        return is_file($this->absolute($path));
    }

    public function size(string $path): int
    {
        $size = @filesize($this->absolute($path));

        return $size === false ? 0 : $size;
    }

    public function delete(string $path): void
    {
        $absolute = $this->absolute($path);

        if (is_file($absolute)) {
            @unlink($absolute);
        }
    }

    /**
     * Copies the original with all metadata removed.
     *
     * EXIF routinely carries GPS coordinates, camera serial numbers and
     * editing history. None of it belongs on a public product image, and it is
     * cheaper to strip once on ingest than to audit later.
     */
    public function writeOriginalStripped(string $sourcePath, string $destinationPath): void
    {
        $absolute = $this->absolute($destinationPath);
        $this->ensureDirectory(dirname($absolute));

        // libvips chooses its encoder from the FILENAME EXTENSION, so the
        // temporary file must carry the destination's extension. A generic
        // `.tmp` name leaves it unable to pick one.
        $extension = pathinfo($absolute, PATHINFO_EXTENSION) ?: 'jpg';
        $temporary = sprintf('%s.tmp-%s.%s', $absolute, bin2hex(random_bytes(6)), $extension);

        try {
            $image = VipsImage::newFromFile($sourcePath, ['access' => 'sequential']);

            // Orientation is applied BEFORE stripping. Strip first and a photo
            // shot in portrait renders sideways forever, because the tag that
            // said so is gone.
            $image = $image->autorot();

            $image->writeToFile($temporary, ['strip' => true]);

            if (! is_file($temporary)) {
                throw new RuntimeException('libvips produced no output.');
            }

            if (! @rename($temporary, $absolute)) {
                throw new RuntimeException('Could not move the original into place.');
            }
        } catch (\Throwable $e) {
            throw new RuntimeException('Could not store the original: '.$e->getMessage(), 0, $e);
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /**
     * Writes via a temporary file and renames into place.
     *
     * rename() is atomic within a filesystem, so a reader never observes a
     * partially written image — which matters because Caddy serves these
     * directly while the queue is still generating them.
     *
     * @param  callable(string): mixed  $write
     */
    public function atomicWrite(string $absolutePath, callable $write): void
    {
        $this->ensureDirectory(dirname($absolutePath));

        $temporary = $absolutePath.'.tmp-'.bin2hex(random_bytes(6));

        try {
            $write($temporary);

            if (! is_file($temporary)) {
                throw new RuntimeException('Writer produced no output.');
            }

            if (! @rename($temporary, $absolutePath)) {
                throw new RuntimeException('Could not move the temporary file into place.');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    public function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (! @mkdir($directory, 0o775, true) && ! is_dir($directory)) {
            throw new RuntimeException("Could not create directory: {$directory}");
        }
    }

    /** @return array{files: int, bytes: int} */
    public function usage(): array
    {
        $files = 0;
        $bytes = 0;
        $root = $this->root();

        if (! is_dir($root)) {
            return ['files' => 0, 'bytes' => 0];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $files++;
                $bytes += $file->getSize();
            }
        }

        return ['files' => $files, 'bytes' => $bytes];
    }
}
