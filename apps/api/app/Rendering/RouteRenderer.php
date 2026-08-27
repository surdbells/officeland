<?php

declare(strict_types=1);

namespace App\Rendering;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Turns a route into a file on disk (Phase 8c/8e).
 *
 * ── Why this shells out to Node rather than rendering in PHP ─────────────
 * The page is an Angular application; only the Angular server bundle can
 * produce its HTML. This process owns the QUEUE and the FILESYSTEM, and defers
 * the rendering itself to the render worker over HTTP.
 *
 * ── The atomic write is the whole point ──────────────────────────────────
 * Caddy serves these files directly, with no application in front of them. A
 * partially written index.html is not a slow page, it is a broken page served
 * at full speed to whoever asks - including a crawler. Writing to a temporary
 * file in the same directory and rename()ing over the target makes the swap
 * atomic within the filesystem, so a reader sees either the old page or the
 * new one and never half of either.
 *
 * The temporary file MUST be in the same directory: rename() is only atomic
 * within a filesystem, and /tmp is frequently a different one.
 */
final class RouteRenderer
{
    /**
     * Markers the resolver emits when it cannot enumerate a set cheaply
     * (D-032). Expanded here, against the manifest, which is the one place
     * that already knows how many pages a listing has.
     */
    private const PAGE_MARKER = 'page/*';

    private const SUBTREE_MARKER = '/**';

    public function __construct(private readonly StaticSite $site) {}

    /**
     * @param  list<string>  $routes
     */
    public function render(array $routes): RenderResult
    {
        $started = microtime(true);

        $expanded = $this->expand($routes);

        $rendered = 0;
        $failures = [];

        foreach ($expanded as $route) {
            try {
                $html = $this->fetch($route);

                $this->site->write($route, $html);
                $rendered++;
            } catch (Throwable $e) {
                $failures[$route] = $e->getMessage();
            }
        }

        return new RenderResult(
            rendered: $rendered,
            failures: $failures,
            durationMs: (int) round((microtime(true) - $started) * 1000),
        );
    }

    /**
     * Asks the render worker for one route's HTML.
     *
     * A non-200, or a body that is not a document, is a failure rather than
     * something to write: publishing an error page over a good one is the
     * worst outcome available here, and it would be cached at the edge.
     */
    private function fetch(string $route): string
    {
        $origin = rtrim((string) config('rendering.worker_origin'), '/');

        $response = Http::timeout((int) config('rendering.timeout', 30))->get($origin.$route);

        if (! $response->successful()) {
            throw new RuntimeException("worker returned {$response->status()}");
        }

        $html = $response->body();

        if (! str_contains($html, '<html') || strlen($html) < 512) {
            throw new RuntimeException('worker returned something that is not a page');
        }

        return $html;
    }

    /**
     * Expands markers into concrete routes.
     *
     * @param  list<string>  $routes
     * @return list<string>
     */
    private function expand(array $routes): array
    {
        $expanded = [];

        foreach ($routes as $route) {
            if (str_ends_with($route, self::PAGE_MARKER)) {
                $base = substr($route, 0, -strlen(self::PAGE_MARKER));

                foreach ($this->site->pagesUnder($base) as $page) {
                    $expanded[] = $page;
                }

                continue;
            }

            if (str_ends_with($route, self::SUBTREE_MARKER)) {
                /*
                 * A subtree marker means the resolver hit its cascade cap and
                 * gave up enumerating. Rebuilding the whole subtree from the
                 * manifest is the honest response; it is slow, which is why
                 * the cap exists and why these arrive at cascade priority.
                 */
                $prefix = substr($route, 0, -strlen(self::SUBTREE_MARKER));

                foreach ($this->site->routesUnder($prefix) as $child) {
                    $expanded[] = $child;
                }

                continue;
            }

            $expanded[] = $route;
        }

        return array_values(array_unique($expanded));
    }
}
