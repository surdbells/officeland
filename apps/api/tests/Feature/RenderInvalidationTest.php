<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Rendering\RenderQueue;
use App\Rendering\RenderRequest;
use App\Rendering\RouteResolver;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Gap G7 — the prerender invalidation gate.
 *
 * Under D-009 the storefront is static HTML on disk. There is no TTL and no
 * revalidation: a page that is not regenerated serves stale content forever.
 * Correctness rests entirely on resolving the right routes after a change.
 *
 * Resolve too few and the site quietly lies to buyers and crawlers. Resolve
 * too many and a bulk import rebuilds the catalogue several times over. Both
 * failure modes are invisible in development, which is why this is tested
 * rather than assumed.
 */
final class RenderInvalidationTest extends TestCase
{
    /** @var list<string> */
    private const TABLES = [
        'product_relations', 'product_category', 'products', 'categories', 'brands',
    ];

    private RouteResolver $resolver;

    private RenderQueue $queue;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('TRUNCATE '.implode(', ', self::TABLES).' RESTART IDENTITY CASCADE');

        $this->resolver = app(RouteResolver::class);
        $this->queue = app(RenderQueue::class);
        $this->queue->flush();
    }

    protected function tearDown(): void
    {
        $this->queue->flush();

        parent::tearDown();
    }

    /**
     * @param  list<RenderRequest>  $requests
     * @return list<string>
     */
    private function routesOf(array $requests, string $priority): array
    {
        foreach ($requests as $request) {
            if ($request->priority === $priority) {
                return $request->routes;
            }
        }

        return [];
    }

    // -----------------------------------------------------------------
    // Products
    // -----------------------------------------------------------------

    public function test_changing_a_product_invalidates_its_own_page(): void
    {
        $product = Product::factory()->create();

        $direct = $this->routesOf($this->resolver->resolve($product), RenderRequest::PRIORITY_DIRECT);

        $this->assertContains($product->url(), $direct);
    }

    /**
     * A listing page shows products from its whole subtree, so a change to a
     * leaf product is visible on every ancestor listing. Missing this is the
     * subtle version of the bug: the product page updates and the category
     * page it is listed on does not.
     */
    public function test_changing_a_product_invalidates_every_ancestor_listing(): void
    {
        $department = Category::factory()->root('office-furniture')->create();
        $sub = Category::factory()->childOf($department, 'seating')->create();
        $leaf = Category::factory()->childOf($sub, 'executive-chairs')->create();

        $product = Product::factory()->create(['primary_category_id' => $leaf->id]);
        $product->categories()->attach($leaf->id);

        $direct = $this->routesOf($this->resolver->resolve($product->fresh()), RenderRequest::PRIORITY_DIRECT);

        $this->assertContains('/c/office-furniture/seating/executive-chairs/', $direct);
        $this->assertContains('/c/office-furniture/seating/', $direct);
        $this->assertContains('/c/office-furniture/', $direct);
    }

    public function test_changing_a_product_invalidates_its_brand_hub(): void
    {
        $brand = Brand::factory()->create(['slug' => 'hp']);
        $product = Product::factory()->forBrand($brand)->create();

        $direct = $this->routesOf($this->resolver->resolve($product->fresh()), RenderRequest::PRIORITY_DIRECT);

        $this->assertContains('/brands/hp/', $direct);
    }

    public function test_an_indexable_product_invalidates_the_sitemap(): void
    {
        $product = Product::factory()->create(['is_indexable' => true]);

        $direct = $this->routesOf($this->resolver->resolve($product), RenderRequest::PRIORITY_DIRECT);

        $this->assertContains('/sitemap-products.xml', $direct);
    }

    public function test_a_non_indexable_product_does_not_invalidate_the_sitemap(): void
    {
        $product = Product::factory()->create(['is_indexable' => false]);

        $direct = $this->routesOf($this->resolver->resolve($product), RenderRequest::PRIORITY_DIRECT);

        $this->assertNotContains('/sitemap-products.xml', $direct);
    }

    /**
     * Related rails are baked into the HTML because section 14.7 counts them
     * as internal linking. The cost is that changing product A makes every
     * page displaying A in a rail stale.
     */
    public function test_changing_a_product_cascades_to_pages_whose_rails_display_it(): void
    {
        $toner = Product::factory()->create(['slug' => 'hp-128a-toner']);
        $printer = Product::factory()->create(['slug' => 'hp-laserjet-printer']);

        DB::table('product_relations')->insert([
            'product_id' => $printer->id,
            'related_product_id' => $toner->id,
            'relation_type' => 'consumable',
            'source' => 'icecat',
            'sort_order' => 0,
        ]);

        $cascade = $this->routesOf($this->resolver->resolve($toner), RenderRequest::PRIORITY_CASCADE);

        $this->assertContains('/p/hp-laserjet-printer/', $cascade);
    }

    public function test_the_rail_cascade_is_deprioritised_not_direct(): void
    {
        $toner = Product::factory()->create();
        $printer = Product::factory()->create();

        DB::table('product_relations')->insert([
            'product_id' => $printer->id,
            'related_product_id' => $toner->id,
            'relation_type' => 'consumable',
            'source' => 'icecat',
            'sort_order' => 0,
        ]);

        $direct = $this->routesOf($this->resolver->resolve($toner), RenderRequest::PRIORITY_DIRECT);

        // The referring page must not compete with the page a buyer would
        // actually land on after the edit.
        $this->assertNotContains($printer->url(), $direct);
    }

    /**
     * Without a cap, re-categorising a department enqueues thousands of
     * individual routes and the queue becomes the bottleneck.
     */
    public function test_an_oversized_cascade_escalates_to_a_subtree_rebuild(): void
    {
        $popular = Product::factory()->create();

        $referrers = Product::factory()->count(RouteResolver::CASCADE_CAP + 5)->create();

        DB::table('product_relations')->insert(
            $referrers->map(fn (Product $p): array => [
                'product_id' => $p->id,
                'related_product_id' => $popular->id,
                'relation_type' => 'accessory',
                'source' => 'icecat',
                'sort_order' => 0,
            ])->all()
        );

        $cascade = $this->routesOf($this->resolver->resolve($popular), RenderRequest::PRIORITY_CASCADE);

        $this->assertSame(['/p/**'], $cascade, 'An oversized cascade must escalate rather than enumerate.');
    }

    // -----------------------------------------------------------------
    // Categories and brands
    // -----------------------------------------------------------------

    public function test_renaming_a_category_invalidates_its_parent_and_children(): void
    {
        $department = Category::factory()->root('it-products')->create();
        $sub = Category::factory()->childOf($department, 'computers')->create();
        $leaf = Category::factory()->childOf($sub, 'laptops')->create();

        $direct = $this->routesOf($this->resolver->resolve($sub->fresh()), RenderRequest::PRIORITY_DIRECT);

        // The parent renders this category in its child list (D-011).
        $this->assertContains('/c/it-products/', $direct);
        // Children carry this category in their breadcrumb.
        $this->assertContains('/c/it-products/computers/laptops/', $direct);
        $this->assertContains('/c/', $direct);
    }

    public function test_changing_a_brand_invalidates_its_products(): void
    {
        $brand = Brand::factory()->create(['slug' => 'canon']);
        $product = Product::factory()->forBrand($brand)->create();

        $cascade = $this->routesOf($this->resolver->resolve($brand->fresh()), RenderRequest::PRIORITY_CASCADE);

        $this->assertContains($product->url(), $cascade);
    }

    // -----------------------------------------------------------------
    // Deduplication, debouncing and the observer
    // -----------------------------------------------------------------

    public function test_routes_are_deduplicated(): void
    {
        $department = Category::factory()->root('paper')->create();
        $a = Category::factory()->childOf($department, 'a4')->create();
        $b = Category::factory()->childOf($department, 'a3')->create();

        $product = Product::factory()->create(['primary_category_id' => $a->id]);
        $product->categories()->attach([$a->id, $b->id]);

        $direct = $this->routesOf($this->resolver->resolve($product->fresh()), RenderRequest::PRIORITY_DIRECT);

        // Both categories share the same ancestor; it must appear once.
        $this->assertSame(
            1,
            count(array_keys($direct, '/c/paper/', true)),
            'The shared ancestor must be enqueued once, not once per category.',
        );
        $this->assertSame(count($direct), count(array_unique($direct)));
    }

    public function test_saving_a_model_enqueues_routes_automatically(): void
    {
        $before = $this->queue->depth();

        Product::factory()->create();

        $after = $this->queue->depth();

        $this->assertGreaterThan(
            $before['direct'],
            $after['direct'],
            'Saving a model must enqueue its routes without the caller asking.',
        );
    }

    /**
     * A bulk import touching hundreds of products enqueues the same category
     * listing hundreds of times. Stored as a set, it is held once.
     */
    public function test_repeated_enqueues_of_the_same_route_are_collapsed(): void
    {
        $this->queue->flush();

        $request = new RenderRequest(['/c/office-furniture/'], RenderRequest::PRIORITY_DIRECT, 'test');

        for ($i = 0; $i < 25; $i++) {
            $this->queue->push([$request]);
        }

        $this->assertSame(1, $this->queue->depth()['direct']);
    }

    public function test_routes_are_not_claimable_until_the_debounce_window_elapses(): void
    {
        $this->queue->flush();

        $this->queue->push([
            new RenderRequest(['/c/just-edited/'], RenderRequest::PRIORITY_DIRECT, 'test'),
        ]);

        // Still inside the debounce window: an editor saving repeatedly should
        // produce one render, not one per save.
        $this->assertSame([], $this->queue->claim());

        $this->travel(RenderQueue::DEBOUNCE_SECONDS + 1)->seconds();

        $this->assertSame(['/c/just-edited/'], $this->queue->claim());
    }

    public function test_claiming_drains_direct_routes_before_cascade_routes(): void
    {
        $this->queue->flush();

        $this->queue->push([
            new RenderRequest(['/p/cascade-page/'], RenderRequest::PRIORITY_CASCADE, 'test'),
            new RenderRequest(['/p/direct-page/'], RenderRequest::PRIORITY_DIRECT, 'test'),
        ]);

        $this->travel(RenderQueue::DEBOUNCE_SECONDS + 1)->seconds();

        $claimed = $this->queue->claim(1);

        $this->assertSame(['/p/direct-page/'], $claimed);
    }

    public function test_claimed_routes_are_removed_from_the_queue(): void
    {
        $this->queue->flush();

        $this->queue->push([
            new RenderRequest(['/p/one/', '/p/two/'], RenderRequest::PRIORITY_DIRECT, 'test'),
        ]);

        $this->travel(RenderQueue::DEBOUNCE_SECONDS + 1)->seconds();

        $this->queue->claim();

        $this->assertSame(['direct' => 0, 'cascade' => 0], $this->queue->depth());
    }
}
