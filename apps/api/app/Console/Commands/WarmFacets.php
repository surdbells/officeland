<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\Category;
use App\Search\PostgresSearchEngine;
use App\Search\SearchQuery;
use Illuminate\Console\Command;

/**
 * Pre-compute the facet sidebar for every broad listing into the cache.
 *
 * Facet counts over the whole active catalogue are too expensive to compute on
 * the request path (they repeatedly 500'd the PLP), so the storefront reads them
 * from cache and never computes them itself. This fills that cache: the shop
 * page (no scope), every category with products, and every brand with products,
 * each computed on the direct connection with the web timeout lifted. A filtered
 * listing is still computed live - its scope is small.
 *
 * Runs after catalogue:curate-facets (which chooses the attributes and bumps the
 * cache generation) and on a schedule so the counts stay fresh and the cache
 * never lapses. Idempotent.
 *
 *   php artisan catalogue:warm-facets
 */
final class WarmFacets extends Command
{
    protected $signature = 'catalogue:warm-facets
        {--limit=0 : Cap the number of broad listings warmed (0 = all). For testing.}';

    protected $description = 'Pre-compute the facet sidebar for every broad listing into the cache';

    public function handle(PostgresSearchEngine $engine): int
    {
        @ini_set('memory_limit', '512M');

        $limit = (int) $this->option('limit');

        /** @var list<array{label: string, query: SearchQuery}> $targets */
        $targets = [['label' => 'home / shop', 'query' => new SearchQuery]];

        Category::query()
            ->where('product_count_cached', '>', 0)
            ->orderBy('path')
            ->get(['id', 'path'])
            ->each(function (Category $category) use (&$targets): void {
                $targets[] = ['label' => '/c'.$category->path, 'query' => new SearchQuery(categoryId: $category->id)];
            });

        Brand::query()
            ->where('product_count_cached', '>', 0)
            ->orderBy('slug')
            ->get(['id', 'slug'])
            ->each(function (Brand $brand) use (&$targets): void {
                $targets[] = ['label' => 'brand:'.$brand->slug, 'query' => new SearchQuery(brandId: $brand->id)];
            });

        if ($limit > 0) {
            $targets = array_slice($targets, 0, $limit);
        }

        $this->info('Warming '.count($targets).' broad listing(s)…');
        $bar = $this->output->createProgressBar(count($targets));
        $bar->start();

        $warmed = 0;
        $values = 0;
        $failed = 0;

        foreach ($targets as $target) {
            try {
                $values += $engine->warmFacets($target['query']);
                $warmed++;
            } catch (\Throwable $e) {
                $failed++;
                $this->warn("  failed [{$target['label']}]: {$e->getMessage()}");
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Warmed {$warmed} of ".count($targets)." listing(s); {$values} facet values cached".($failed > 0 ? ", {$failed} failed" : '').'.');

        return self::SUCCESS;
    }
}
