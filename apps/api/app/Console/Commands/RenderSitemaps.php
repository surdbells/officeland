<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Rendering\SitemapGenerator;
use Illuminate\Console\Command;

/**
 * Regenerates every sitemap (Phase 8g).
 *
 * Invoked directly, and by the full build. RouteResolver also enqueues
 * `/sitemap-*.xml` whenever an indexable record changes, so the incremental
 * renderer keeps them current between builds — this is what makes that
 * possible, because those routes are not pages and the Angular worker cannot
 * produce them.
 */
final class RenderSitemaps extends Command
{
    protected $signature = 'render:sitemaps';

    protected $description = 'Write sitemap.xml and its per-type sitemaps into the static root';

    public function handle(SitemapGenerator $generator): int
    {
        $written = $generator->generate();

        $total = 0;

        foreach ($written as $route => $count) {
            $this->line(sprintf('  %-36s %6d URLs', $route, $count));
            $total += $count;
        }

        $this->info('sitemap.xml indexes '.count($written)." file(s), {$total} URLs");

        return self::SUCCESS;
    }
}
