<?php

declare(strict_types=1);

namespace App\Rendering;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Sitemap generation (Phase 8g).
 *
 * ── sitemap ⊆ manifest, always in that direction ─────────────────────────
 * The manifest decides which pages EXIST; this decides which of them are
 * offered to Google. The two filter differently and must not be confused:
 *
 *   rendered  → every reachable page, including noindex ones, because the
 *               site links to them and a link to a missing file is a 404
 *   sitemapped → only indexable pages
 *
 * A URL in the sitemap with no file behind it is a 404 reported straight to
 * Search Console, so this filters strictly more than the manifest does. That
 * is the invariant, and it only holds in one direction.
 *
 * ── lastmod is real or absent ────────────────────────────────────────────
 * Google demotes sitemaps whose lastmod is not trustworthy - a file whose
 * every entry claims today's date is worse than one with no dates at all,
 * because it teaches the crawler to ignore the signal. These come from
 * `updated_at`, which the render pipeline already keys off.
 *
 * ── Paginated pages are excluded ─────────────────────────────────────────
 * `/c/x/page/2/` is indexable and self-canonical, and it is reachable from
 * page 1 by a real anchor. Listing every page of every listing would bloat the
 * sitemap with near-duplicates and spend crawl budget re-checking pages whose
 * content changes only when the products on them move.
 */
final class SitemapGenerator
{
    /** The sitemaps.org cap is 50,000 URLs and 50 MB uncompressed. */
    private const MAX_URLS_PER_FILE = 45000;

    /**
     * Indexable pages that are not rows in a table.
     *
     * The generator was built from the catalogue outwards - categories,
     * brands, products - and so the sitemap did not contain the homepage.
     * Nor, once they were written, the twelve editorial pages, which are the
     * ones a search for "office furniture showroom Victoria Island" or
     * "bulk office supply tender Nigeria" should land on.
     *
     * ── Why a hard-coded list ────────────────────────────────────────────
     * There is nothing to derive it from on this side. These routes live in
     * the Angular router, and the API has no view of it; the render manifest
     * flows the other way, from here to the build. A list that has to be
     * maintained by hand is a poor thing, so it is asserted by
     * SitemapGeneratorTest rather than trusted.
     *
     * No lastmod: these change when somebody edits a template, and inventing
     * a date would be worse than omitting the field. `lastmod` is a claim,
     * and a wrong one teaches a crawler to ignore all of them.
     *
     * @var list<string>
     */
    private const PAGES = [
        '/',
        '/about/',
        '/brands/',
        '/bulk-supply/',
        '/c/',
        '/contact/',
        '/cookies/',
        '/delivery/',
        '/guides/',
        '/locations/',
        '/privacy/',
        '/quote/track/',
        '/returns/',
        '/terms/',
        '/warranty/',
    ];

    public function __construct(private readonly StaticSite $site) {}

    /**
     * Writes every sitemap and the index that points at them.
     *
     * @return array<string, int> file route => URL count
     */
    public function generate(): array
    {
        $written = [];

        $written['/sitemap-pages.xml'] = $this->write('/sitemap-pages.xml', $this->pages());
        $written['/sitemap-categories.xml'] = $this->write('/sitemap-categories.xml', $this->categories());
        $written['/sitemap-brands.xml'] = $this->write('/sitemap-brands.xml', $this->brands());

        foreach ($this->productChunks() as $index => $urls) {
            $route = $index === 0 ? '/sitemap-products.xml' : '/sitemap-products-'.($index + 1).'.xml';
            $written[$route] = $this->write($route, $urls);
        }

        $this->writeIndex(array_keys($written));

        return $written;
    }

    /**
     * @return list<array{loc: string, lastmod: string|null}>
     */
    private function pages(): array
    {
        return array_map(
            static fn (string $path): array => ['loc' => $path, 'lastmod' => null],
            self::PAGES,
        );
    }

    /**
     * @return list<array{loc: string, lastmod: string|null}>
     */
    private function categories(): array
    {
        $urls = Category::query()
            ->indexable()
            ->orderBy('path')
            ->get(['path', 'updated_at'])
            ->map(fn (Category $category): array => [
                'loc' => '/c'.$category->path,
                'lastmod' => $this->timestamp($category->updated_at),
            ])
            ->all();

        // array_values, not Collection::values(): PHPStan infers a list from
        // the former and merely array<int, T> from the latter.
        return array_values($urls);
    }

    /**
     * @return list<array{loc: string, lastmod: string|null}>
     */
    private function brands(): array
    {
        $urls = Brand::query()
            ->where('is_indexable', true)
            ->orderBy('slug')
            ->get(['slug', 'updated_at'])
            ->map(fn (Brand $brand): array => [
                'loc' => '/brands/'.$brand->slug.'/',
                'lastmod' => $this->timestamp($brand->updated_at),
            ])
            ->all();

        return array_values($urls);
    }

    /**
     * Products, chunked.
     *
     * Chunked by the sitemaps.org cap rather than by category, so the split
     * stays stable as the catalogue grows: a category-based split would
     * reshuffle every file whenever a product was re-categorised, and Google
     * re-reads a sitemap whose contents changed.
     *
     * @return list<list<array{loc: string, lastmod: string|null}>>
     */
    private function productChunks(): array
    {
        // cursor(), not get(): the catalogue runs to tens of thousands of
        // products, and hydrating every one as a full model at once exhausts the
        // CLI memory limit (render:sitemaps OOM'd on 2026-10-28). Streaming one
        // lightweight model at a time keeps only the small URL array in memory.
        $urls = [];

        foreach (
            Product::query()
                ->indexable()
                ->orderBy('slug')
                ->select(['slug', 'updated_at'])
                ->cursor() as $product
        ) {
            $urls[] = [
                'loc' => '/p/'.$product->slug.'/',
                'lastmod' => $this->timestamp($product->updated_at),
            ];
        }

        // One empty file rather than none: /sitemap-products.xml is referenced
        // by the index and by RouteResolver, and a 404 there is a reported
        // error even when there is genuinely nothing to list yet.
        if ($urls === []) {
            return [[]];
        }

        return array_chunk($urls, self::MAX_URLS_PER_FILE);
    }

    /**
     * @param  list<array{loc: string, lastmod: string|null}>  $urls
     */
    private function write(string $route, array $urls): int
    {
        $origin = rtrim((string) config('app.public_origin', 'https://officelandng.com'), '/');

        $body = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $body .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";

        foreach ($urls as $url) {
            $body .= '  <url>'."\n";
            $body .= '    <loc>'.htmlspecialchars($origin.$url['loc'], ENT_XML1).'</loc>'."\n";

            if ($url['lastmod'] !== null) {
                $body .= '    <lastmod>'.$url['lastmod'].'</lastmod>'."\n";
            }

            $body .= '  </url>'."\n";
        }

        $body .= '</urlset>'."\n";

        $this->site->write($route, $body);

        return count($urls);
    }

    /**
     * @param  list<string>  $routes
     */
    private function writeIndex(array $routes): void
    {
        $origin = rtrim((string) config('app.public_origin', 'https://officelandng.com'), '/');
        $now = Carbon::now()->toAtomString();

        $body = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $body .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";

        foreach ($routes as $route) {
            $body .= '  <sitemap>'."\n";
            $body .= '    <loc>'.htmlspecialchars($origin.$route, ENT_XML1).'</loc>'."\n";
            $body .= '    <lastmod>'.$now.'</lastmod>'."\n";
            $body .= '  </sitemap>'."\n";
        }

        $body .= '</sitemapindex>'."\n";

        $this->site->write('/sitemap.xml', $body);
    }

    private function timestamp(?CarbonInterface $at): ?string
    {
        return $at?->toAtomString();
    }
}
