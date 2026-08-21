<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The manifest decides which pages exist (D-009).
 *
 * A mistake here is silent and total: the build succeeds, the site publishes,
 * and whole sections of the catalogue simply have no file behind them. Nothing
 * throws, nothing logs, and the first symptom is a drop in Search Console
 * weeks later. So the invariants are asserted rather than assumed.
 */
final class RenderManifestTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_every_category_regardless_of_indexability(): void
    {
        // Categories seed as is_indexable = false pending intro copy, and are
        // linked from the megamenu the whole time. Filtering them out of the
        // manifest would 404 links the site itself publishes.
        $indexable = Category::factory()->indexable()->create(['slug' => 'shown']);
        $hidden = Category::factory()->create(['slug' => 'hidden', 'is_indexable' => false]);

        $categories = $this->manifest()['data']['categories'];

        $this->assertContains(trim((string) $indexable->path, '/'), $categories);
        $this->assertContains(trim((string) $hidden->path, '/'), $categories);
    }

    public function test_it_omits_products_that_have_no_page(): void
    {
        $live = Product::factory()->create(['status' => 'active']);
        $draft = Product::factory()->draft()->create();

        $products = $this->manifest()['data']['products'];

        $this->assertContains($live->slug, $products);
        $this->assertNotContains($draft->slug, $products);
    }

    /**
     * A live product held back by the §14.7 content floor is still linked from
     * its category, so it still needs a file. Indexability is a meta tag, not
     * an existence question.
     */
    public function test_it_includes_a_live_product_that_is_not_yet_indexable(): void
    {
        $product = Product::factory()->create(['status' => 'active', 'is_indexable' => false]);

        $this->assertContains($product->slug, $this->manifest()['data']['products']);
    }

    /**
     * Pagination is a path segment (D-032), so each additional page needs its
     * own route or the anchors the listing renders point at nothing.
     */
    public function test_it_emits_a_route_for_every_additional_listing_page(): void
    {
        $category = Category::factory()->create(['slug' => 'big']);
        $base = trim((string) $category->path, '/');

        // 24 per page, so 30 products is two pages.
        $products = Product::factory()->count(30)->create(['status' => 'active']);
        $category->products()->attach($products->pluck('id'));

        $categories = $this->manifest()['data']['categories'];

        $this->assertContains($base, $categories);
        $this->assertContains("{$base}/page/2", $categories);
        $this->assertNotContains("{$base}/page/3", $categories, 'One page too many was rendered.');
    }

    public function test_a_single_page_category_gets_no_page_routes(): void
    {
        $category = Category::factory()->create(['slug' => 'small']);
        $base = trim((string) $category->path, '/');

        $products = Product::factory()->count(3)->create(['status' => 'active']);
        $category->products()->attach($products->pluck('id'));

        $categories = $this->manifest()['data']['categories'];

        $this->assertContains($base, $categories);
        $this->assertNotContains("{$base}/page/2", $categories);
    }

    /**
     * A department lists its whole subtree, so its page count must be counted
     * that way too. Counting only directly-attached products would under-render
     * every department in the tree.
     */
    public function test_a_parent_counts_its_whole_subtree(): void
    {
        $parent = Category::factory()->create(['slug' => 'parent']);
        $child = Category::factory()->create([
            'slug' => 'child',
            'parent_id' => $parent->id,
            'path' => $parent->path.'child/',
            'depth' => 2,
        ]);

        $products = Product::factory()->count(30)->create(['status' => 'active']);
        $child->products()->attach($products->pluck('id'));

        $categories = $this->manifest()['data']['categories'];

        $this->assertContains(trim((string) $parent->path, '/').'/page/2', $categories);
    }

    /** @return array<string, mixed> */
    private function manifest(): array
    {
        $response = $this->getJson('/api/v1/render/manifest');
        $response->assertOk();

        /** @var array<string, mixed> $data */
        $data = $response->json();

        return $data;
    }
}
