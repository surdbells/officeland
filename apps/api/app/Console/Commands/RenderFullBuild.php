<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Rendering\RenderQueue;
use App\Rendering\RouteManifest;
use App\Rendering\RouteRenderer;
use App\Rendering\SitemapGenerator;
use App\Rendering\StaticSite;
use Illuminate\Console\Command;

/**
 * Regenerates every page from the manifest (Phase 8f).
 *
 * ── When this is required, and why it cannot be inferred ─────────────────
 * The incremental renderer regenerates the routes a DATA change affects. It
 * cannot see a change to a TEMPLATE, a global SEO rule, the header, the footer
 * or a design token — nothing in the database moved, so no event fires, and
 * every page silently keeps the old markup.
 *
 * So a full build is part of the deploy routine rather than an emergency
 * measure, and its wall-clock time belongs in RUNBOOK.md because it is the
 * lower bound on how long a template fix takes to reach production.
 *
 * ── Not the same as `ng build` ───────────────────────────────────────────
 * `ng build` compiles the bundles and prerenders in one pass, which is what
 * CI does. This renders from the ALREADY BUILT bundle through the running
 * worker, one route at a time, which is what a production host does when the
 * bundle has not changed but the content has — a bulk import, a re-categorisa-
 * tion, or a restore.
 */
final class RenderFullBuild extends Command
{
    protected $signature = 'render:full
        {--chunk=50 : Routes per pass}
        {--skip-sitemaps : Render pages only}
        {--dry-run : List what would be rendered and stop}';

    protected $description = 'Render every route in the manifest into the static root';

    public function handle(
        RouteRenderer $renderer,
        SitemapGenerator $sitemaps,
        RenderQueue $queue,
        StaticSite $site,
        RouteManifest $manifest,
    ): int {
        // The same service the /render/manifest endpoint exposes. Asking
        // this application for a list it can compute in-process would add a
        // network hop and a second place for the two to disagree.
        $routes = $manifest->routes();

        if ($routes === []) {
            $this->error('The manifest returned no routes. Refusing to publish an empty site.');

            return self::FAILURE;
        }

        $this->info(count($routes).' route(s) to render');

        if ($this->option('dry-run')) {
            foreach ($routes as $route) {
                $this->line('  '.$route);
            }

            return self::SUCCESS;
        }

        $started = microtime(true);
        $rendered = 0;
        $failures = [];

        $bar = $this->output->createProgressBar(count($routes));
        $bar->start();

        foreach (array_chunk($routes, max(1, (int) $this->option('chunk'))) as $chunk) {
            $result = $renderer->render($chunk);

            $rendered += $result->rendered;
            $failures += $result->failures;

            $bar->advance(count($chunk));
        }

        $bar->finish();
        $this->newLine(2);

        if (! $this->option('skip-sitemaps')) {
            $written = $sitemaps->generate();
            $this->info('sitemaps: '.count($written).' file(s)');
        }

        /*
         * The queue is dropped, not drained. Everything in it names a route
         * this build has just regenerated from current data, so draining it
         * would render the same pages a second time for no reason.
         */
        $queue->flush();

        $seconds = round(microtime(true) - $started, 1);
        $perRoute = $rendered > 0 ? round(($seconds * 1000) / $rendered) : 0;

        $this->newLine();
        $this->info("rendered {$rendered} of ".count($routes)." route(s) in {$seconds}s ({$perRoute}ms each)");

        if ($failures !== []) {
            $this->newLine();
            $this->error(count($failures).' route(s) failed:');

            foreach ($failures as $route => $reason) {
                $this->error("  {$route} — {$reason}");
            }

            /*
             * A failed full build leaves the site in a MIXED state: some pages
             * new, some old. That is better than an empty site, and worse than
             * a complete one, so it exits non-zero and says so rather than
             * letting a deploy script continue.
             */
            return self::FAILURE;
        }

        $this->info('static root now holds '.count($site->routesUnder('/')).' page(s)');

        return self::SUCCESS;
    }
}
