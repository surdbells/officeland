<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Rendering\SitemapGenerator;
use App\Rendering\StaticSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sitemaps (Phase 8g).
 *
 * The invariant under test is one-directional and easy to invert by accident:
 * the sitemap must be a SUBSET of what is rendered. A URL in the sitemap with
 * no file behind it is a 404 reported straight to Search Console, and it is
 * invisible from inside the application.
 */
final class SitemapGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    private SitemapGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/sitemap-'.bin2hex(random_bytes(6));
        mkdir($this->root, 0o755, true);

        $this->generator = new SitemapGenerator(new StaticSite($this->root));
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->root);

        parent::tearDown();
    }

    private function contents(string $file): string
    {
        return (string) file_get_contents($this->root.'/'.$file);
    }

    public function test_it_writes_an_index_pointing_at_every_sitemap(): void
    {
        $this->generator->generate();

        $index = $this->contents('sitemap.xml');

        $this->assertStringContainsString('<sitemapindex', $index);
        $this->assertStringContainsString('/sitemap-products.xml', $index);
        $this->assertStringContainsString('/sitemap-categories.xml', $index);
        $this->assertStringContainsString('/sitemap-brands.xml', $index);
    }

    /**
     * The invariant. A non-indexable product still has a rendered page — it is
     * linked from its category — but must not be offered to Google.
     */
    public function test_it_lists_only_indexable_products(): void
    {
        $indexable = Product::factory()->create(['status' => 'active', 'is_indexable' => true]);
        $held = Product::factory()->create(['status' => 'active', 'is_indexable' => false]);
        $draft = Product::factory()->draft()->create(['is_indexable' => true]);

        $this->generator->generate();
        $xml = $this->contents('sitemap-products.xml');

        $this->assertStringContainsString("/p/{$indexable->slug}/", $xml);
        $this->assertStringNotContainsString("/p/{$held->slug}/", $xml);
        $this->assertStringNotContainsString("/p/{$draft->slug}/", $xml);
    }

    public function test_it_lists_only_indexable_categories(): void
    {
        $shown = Category::factory()->create(['slug' => 'shown', 'is_indexable' => true]);
        $hidden = Category::factory()->create(['slug' => 'hidden', 'is_indexable' => false]);

        $this->generator->generate();
        $xml = $this->contents('sitemap-categories.xml');

        $this->assertStringContainsString('/c'.$shown->path, $xml);
        $this->assertStringNotContainsString('/c'.$hidden->path, $xml);
    }

    public function test_it_lists_only_indexable_brands(): void
    {
        $shown = Brand::factory()->create(['slug' => 'shown', 'is_indexable' => true]);
        $hidden = Brand::factory()->create(['slug' => 'hidden', 'is_indexable' => false]);

        $this->generator->generate();
        $xml = $this->contents('sitemap-brands.xml');

        $this->assertStringContainsString('/brands/shown/', $xml);
        $this->assertStringNotContainsString('/brands/hidden/', $xml);
        $this->assertNotSame('', $hidden->slug);
    }

    /**
     * URLs must be absolute against the PUBLIC origin, not APP_URL — which on
     * this stack is an internal container address.
     */
    public function test_urls_are_absolute_against_the_public_origin(): void
    {
        Product::factory()->create(['status' => 'active', 'is_indexable' => true]);

        $this->generator->generate();
        $xml = $this->contents('sitemap-products.xml');

        $this->assertStringContainsString('<loc>https://officelandng.com/p/', $xml);
        $this->assertStringNotContainsString('http://api/', $xml);
        $this->assertStringNotContainsString('localhost', $xml);
    }

    /** Trailing slashes must match the canonicals the pages themselves emit. */
    public function test_urls_keep_their_trailing_slash(): void
    {
        $product = Product::factory()->create(['status' => 'active', 'is_indexable' => true]);

        $this->generator->generate();

        $this->assertStringContainsString("/p/{$product->slug}/</loc>", $this->contents('sitemap-products.xml'));
    }

    public function test_it_records_a_real_lastmod(): void
    {
        Product::factory()->create(['status' => 'active', 'is_indexable' => true]);

        $this->generator->generate();

        $this->assertMatchesRegularExpression(
            '/<lastmod>\d{4}-\d{2}-\d{2}T/',
            $this->contents('sitemap-products.xml'),
        );
    }

    /**
     * Paginated listing pages are reachable by real anchors from page 1 and are
     * deliberately not listed: they are near-duplicates whose content changes
     * only when products move between pages.
     */
    public function test_it_does_not_list_paginated_pages(): void
    {
        $category = Category::factory()->create(['slug' => 'big', 'is_indexable' => true]);
        $products = Product::factory()->count(30)->create(['status' => 'active']);
        $category->products()->attach($products->pluck('id'));

        $this->generator->generate();

        $this->assertStringNotContainsString('/page/2/', $this->contents('sitemap-categories.xml'));
    }

    /**
     * An empty file, not a missing one: the index references it and
     * RouteResolver enqueues it, so a 404 here is a reported error even when
     * there is genuinely nothing to list.
     */
    public function test_an_empty_catalogue_still_produces_a_valid_file(): void
    {
        $this->generator->generate();

        $xml = $this->contents('sitemap-products.xml');

        $this->assertStringContainsString('<urlset', $xml);
        $this->assertStringContainsString('</urlset>', $xml);
        $this->assertNotFalse(simplexml_load_string($xml), 'The empty sitemap is not valid XML.');
    }

    public function test_every_written_sitemap_is_valid_xml(): void
    {
        Product::factory()->count(3)->create(['status' => 'active', 'is_indexable' => true]);
        Brand::factory()->create(['is_indexable' => true]);
        Category::factory()->create(['is_indexable' => true]);

        $written = $this->generator->generate();

        foreach ([...array_keys($written), '/sitemap.xml'] as $route) {
            $xml = $this->contents(ltrim($route, '/'));

            $this->assertNotFalse(
                simplexml_load_string($xml),
                "{$route} is not valid XML.",
            );
        }
    }

    private function deleteTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach ((array) scandir($path) as $entry) {
            if (! is_string($entry) || $entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path.'/'.$entry;

            is_dir($child) ? $this->deleteTree($child) : @unlink($child);
        }

        @rmdir($path);
    }
}
