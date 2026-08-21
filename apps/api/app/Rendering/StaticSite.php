<?php

declare(strict_types=1);

namespace App\Rendering;

use RuntimeException;

/**
 * The published static site on disk.
 *
 * Everything that touches the directory Caddy serves goes through here, so the
 * atomic-write rule is enforced in one place rather than remembered at each
 * call site.
 */
final class StaticSite
{
    public function __construct(private readonly string $root) {}

    /**
     * Writes a route's HTML, atomically.
     *
     * ── Why the temporary file is a sibling ──────────────────────────────
     * rename() is atomic only WITHIN a filesystem. A temp file in /tmp is
     * routinely on a different one, where rename() degrades to copy-then-
     * unlink and reintroduces exactly the torn read this exists to prevent.
     * So the temporary file is created in the destination directory.
     *
     * ── Why not write in place ───────────────────────────────────────────
     * Caddy has no application in front of it. A half-written index.html is
     * served at full speed to whoever asks, including a crawler, and then
     * cached. The window is small and the consequence is not.
     */
    public function write(string $route, string $html): void
    {
        $path = $this->pathFor($route);
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0o755, true) && ! is_dir($directory)) {
            throw new RuntimeException("Could not create {$directory}");
        }

        $temporary = $directory.'/.'.basename($path).'.'.bin2hex(random_bytes(6)).'.tmp';

        try {
            if (file_put_contents($temporary, $html) === false) {
                throw new RuntimeException("Could not write {$temporary}");
            }

            // Readable by the web server, which does not run as this user.
            chmod($temporary, 0o644);

            if (! rename($temporary, $path)) {
                throw new RuntimeException("Could not move {$temporary} into place");
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    public function exists(string $route): bool
    {
        return is_file($this->pathFor($route));
    }

    /**
     * Existing `page/N` files for a listing, plus the listing itself.
     *
     * Read from disk rather than recomputed: the page COUNT may have changed,
     * and the routes that currently exist are exactly the ones that would
     * otherwise be left stale. New pages beyond the current count are created
     * by the next full build or by the manifest-driven render.
     *
     * @return list<string>
     */
    public function pagesUnder(string $base): array
    {
        $routes = [$base];

        $directory = $this->pathFor($base);
        $directory = dirname($directory).'/page';

        if (! is_dir($directory)) {
            return $routes;
        }

        foreach ((array) scandir($directory) as $entry) {
            if (! is_string($entry) || ! ctype_digit($entry)) {
                continue;
            }

            $routes[] = $base.'page/'.$entry.'/';
        }

        return $routes;
    }

    /**
     * Every rendered route beneath a prefix.
     *
     * Used to expand a subtree marker, which the resolver emits when a change
     * exceeds its cascade cap.
     *
     * @return list<string>
     */
    public function routesUnder(string $prefix): array
    {
        $directory = rtrim($this->root.'/'.ltrim($prefix, '/'), '/');

        if (! is_dir($directory)) {
            return [];
        }

        $routes = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo || $file->getFilename() !== 'index.html') {
                continue;
            }

            $relative = substr($file->getPath(), strlen($this->root));
            $routes[] = rtrim($relative, '/').'/';
        }

        sort($routes);

        return $routes;
    }

    /**
     * `/c/office-furniture/` becomes `<root>/c/office-furniture/index.html`.
     *
     * The route is validated rather than trusted. It arrives from a queue
     * that the application fills, but a path traversal here would let a
     * catalogue edit write anywhere the process can reach.
     */
    private function pathFor(string $route): string
    {
        if (! str_starts_with($route, '/') || str_contains($route, '..')) {
            throw new RuntimeException("Refusing to write suspicious route: {$route}");
        }

        $relative = trim($route, '/');

        // A route ending in a file extension is written as that file; anything
        // else is a directory with an index.html, which is what makes the URL
        // work without a trailing-slash redirect (D-028).
        if ($relative !== '' && str_contains(basename($relative), '.')) {
            return $this->root.'/'.$relative;
        }

        return rtrim($this->root.'/'.$relative, '/').'/index.html';
    }
}
