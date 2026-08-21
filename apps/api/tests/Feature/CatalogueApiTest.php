<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Attribute;
use App\Models\AttributeGroup;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CatalogueApiTest extends TestCase
{
    /** @var list<string> */
    private const TABLES = [
        'product_relations', 'product_category', 'products', 'categories', 'brands',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('TRUNCATE '.implode(', ', self::TABLES).' RESTART IDENTITY CASCADE');
        Cache::flush();
    }

    // -----------------------------------------------------------------
    // Payload discipline
    // -----------------------------------------------------------------

    /**
     * Section 8.3: never expose internal integer IDs. They leak catalogue size
     * and growth rate, and turn every resource into a guessable enumeration.
     */
    public function test_payloads_never_expose_internal_integer_ids(): void
    {
        $brand = Brand::factory()->create();
        $category = Category::factory()->root('office-furniture')->create();
        $product = Product::factory()->forBrand($brand)->create([
            'primary_category_id' => $category->id,
        ]);

        foreach ([
            '/api/v1/navigation',
            '/api/v1/brands',
            '/api/v1/brands/'.$brand->slug,
            '/api/v1/categories',
            '/api/v1/categories/office-furniture',
            '/api/v1/products/'.$product->slug,
        ] as $endpoint) {
            $body = $this->getJson($endpoint)->getContent();

            $this->assertStringNotContainsString('"id":', $body, "{$endpoint} leaked an internal id");
            $this->assertStringNotContainsString('"brand_id"', $body, "{$endpoint} leaked a foreign key");
            $this->assertStringNotContainsString('"primary_category_id"', $body, "{$endpoint} leaked a foreign key");
        }
    }

    // -----------------------------------------------------------------
    // Navigation
    // -----------------------------------------------------------------

    public function test_navigation_returns_the_tree_nested(): void
    {
        $department = Category::factory()->root('it-products')->create();
        Category::factory()->childOf($department, 'computers')->create();

        $response = $this->getJson('/api/v1/navigation');

        $response->assertOk()
            ->assertJsonPath('data.departments.0.slug', 'it-products')
            ->assertJsonPath('data.departments.0.children.0.slug', 'computers')
            ->assertJsonPath('data.departments.0.url', '/c/it-products/');
    }

    public function test_navigation_lists_featured_brands(): void
    {
        Brand::factory()->featured()->create(['name' => 'HP', 'slug' => 'hp']);
        Brand::factory()->create(['name' => 'Unfeatured', 'slug' => 'unfeatured']);

        $response = $this->getJson('/api/v1/navigation');

        $response->assertOk()
            ->assertJsonPath('data.brands.0.slug', 'hp')
            ->assertJsonCount(1, 'data.brands');
    }

    // -----------------------------------------------------------------
    // Categories
    // -----------------------------------------------------------------

    public function test_a_category_resolves_by_its_full_path(): void
    {
        $department = Category::factory()->root('office-furniture')->create();
        $sub = Category::factory()->childOf($department, 'seating')->create();
        Category::factory()->childOf($sub, 'executive-chairs')->create();

        $response = $this->getJson('/api/v1/categories/office-furniture/seating');

        $response->assertOk()
            ->assertJsonPath('data.slug', 'seating')
            ->assertJsonPath('data.depth', 2)
            ->assertJsonPath('data.url', '/c/office-furniture/seating/');
    }

    /**
     * D-011: children ship as data so the storefront can render them as real
     * crawlable anchors on every category page. That is what makes every
     * product reachable within 3 clicks of the homepage.
     */
    public function test_a_category_returns_its_children(): void
    {
        $department = Category::factory()->root('office-furniture')->create();
        Category::factory()->childOf($department, 'seating')->create();
        Category::factory()->childOf($department, 'desks-tables')->create();

        $this->getJson('/api/v1/categories/office-furniture')
            ->assertOk()
            ->assertJsonCount(2, 'data.children');
    }

    public function test_a_category_returns_its_breadcrumb(): void
    {
        $department = Category::factory()->root('office-furniture')->create();
        $sub = Category::factory()->childOf($department, 'seating')->create();
        Category::factory()->childOf($sub, 'executive-chairs')->create();

        $this->getJson('/api/v1/categories/office-furniture/seating/executive-chairs')
            ->assertOk()
            ->assertJsonPath('data.breadcrumb.0.url', '/c/office-furniture/')
            ->assertJsonPath('data.breadcrumb.1.url', '/c/office-furniture/seating/')
            ->assertJsonCount(2, 'data.breadcrumb');
    }

    public function test_an_unknown_category_returns_the_error_envelope(): void
    {
        $this->getJson('/api/v1/categories/does/not/exist')
            ->assertNotFound()
            ->assertJsonStructure(['error' => ['code', 'message', 'details', 'trace_id']]);
    }

    /**
     * A department listing must include products filed against its leaves,
     * or /c/office-furniture/ appears empty while every chair sits three
     * levels below it.
     */
    public function test_a_category_listing_includes_the_whole_subtree(): void
    {
        $department = Category::factory()->root('office-furniture')->create();
        $sub = Category::factory()->childOf($department, 'seating')->create();
        $leaf = Category::factory()->childOf($sub, 'executive-chairs')->create();

        $product = Product::factory()->create(['primary_category_id' => $leaf->id]);
        $product->categories()->attach($leaf->id);

        $this->getJson('/api/v1/categories/office-furniture/products')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', $product->slug);
    }

    public function test_a_category_listing_excludes_draft_products(): void
    {
        $category = Category::factory()->root('office-furniture')->create();

        $active = Product::factory()->create();
        $draft = Product::factory()->draft()->create();
        $active->categories()->attach($category->id);
        $draft->categories()->attach($category->id);

        $this->getJson('/api/v1/categories/office-furniture/products')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', $active->slug);
    }

    /**
     * Listings page by NUMBER, not by cursor.
     *
     * This test previously asserted the opposite, citing §8.3's requirement of
     * cursor pagination on any list that can exceed 1,000 rows. That
     * requirement is right for an API consumer walking a large collection and
     * wrong for a listing, for a reason §8.3 predates: under D-009 every page
     * of every listing is a prerendered FILE reached by a crawlable anchor,
     * and a cursor cannot produce one — it is opaque, so page 3 is unreachable
     * without first fetching page 2, and no `page/3` route can be rendered at
     * build time.
     *
     * Both survive by serving different consumers: listings are offset-paged
     * and bounded, and the render worker walks the whole catalogue through
     * /api/v1/render/manifest, which is not paginated at all. See D-031.
     */
    public function test_category_listings_page_by_number(): void
    {
        $category = Category::factory()->root('office-furniture')->create();

        foreach (Product::factory()->count(3)->create() as $product) {
            $product->categories()->attach($category->id);
        }

        $response = $this->getJson('/api/v1/categories/office-furniture/products?per_page=2');

        $response->assertOk()->assertJsonCount(2, 'data');

        $response->assertJsonPath('meta.total', 3);
        $response->assertJsonPath('meta.page', 1);
        $response->assertJsonPath('meta.per_page', 2);
        $response->assertJsonPath('meta.last_page', 2);
        $response->assertJsonPath('meta.has_more', true);
    }

    public function test_a_later_listing_page_returns_the_remainder(): void
    {
        $category = Category::factory()->root('office-furniture')->create();

        foreach (Product::factory()->count(3)->create() as $product) {
            $product->categories()->attach($category->id);
        }

        $response = $this->getJson('/api/v1/categories/office-furniture/products?per_page=2&page=2');

        $response->assertOk()->assertJsonCount(1, 'data');
        $response->assertJsonPath('meta.page', 2);
        $response->assertJsonPath('meta.has_more', false);
    }

    /**
     * Pages must not overlap. An off-by-one in the offset silently duplicates
     * a product across two prerendered pages and drops another entirely.
     */
    public function test_listing_pages_do_not_overlap(): void
    {
        $category = Category::factory()->root('office-furniture')->create();

        foreach (Product::factory()->count(5)->create() as $product) {
            $product->categories()->attach($category->id);
        }

        $first = $this->getJson('/api/v1/categories/office-furniture/products?per_page=2&page=1')
            ->json('data.*.uuid');
        $second = $this->getJson('/api/v1/categories/office-furniture/products?per_page=2&page=2')
            ->json('data.*.uuid');
        $third = $this->getJson('/api/v1/categories/office-furniture/products?per_page=2&page=3')
            ->json('data.*.uuid');

        $seen = array_merge($first, $second, $third);

        $this->assertCount(5, $seen);
        $this->assertCount(5, array_unique($seen), 'A product appeared on more than one page.');
    }

    /**
     * §23.2: facet counts are mandatory, and §16 requires that facets never
     * offer a zero-result combination — which is only enforceable if the
     * counts are live against the current filter set.
     */
    public function test_a_category_listing_returns_facets_with_counts(): void
    {
        $category = Category::factory()->root('office-furniture')->create();

        // updateOrCreate, not create: setUp truncates products and categories
        // but not the attribute tables, so these survive between tests in this
        // class and a plain insert collides on the unique code.
        $group = AttributeGroup::query()->updateOrCreate(
            ['code' => 'general'],
            ['uuid' => (string) Str::uuid(), 'name' => 'General'],
        );

        $attribute = Attribute::query()->updateOrCreate(
            ['code' => 'colour'],
            [
                'uuid' => (string) Str::uuid(),
                'group_id' => $group->id,
                'name' => 'Colour',
                'data_type' => 'string',
                'is_filterable' => true,
            ],
        );

        foreach (['Black', 'Black', 'Grey'] as $colour) {
            $product = Product::factory()->create();
            $product->categories()->attach($category->id);
            $product->attributeValues()->create([
                'attribute_id' => $attribute->id,
                'value_raw' => $colour,
                'value_presentation' => $colour,
            ]);
        }

        $facets = $this->getJson('/api/v1/categories/office-furniture/products')->json('facets');

        $colourFacet = collect($facets)->firstWhere('code', 'colour');

        $this->assertNotNull($colourFacet, 'The colour facet was not returned.');

        $counts = collect($colourFacet['values'])->pluck('count', 'value');

        $this->assertSame(2, $counts['Black']);
        $this->assertSame(1, $counts['Grey']);
    }

    /** Selecting a facet must narrow the result set, not merely annotate it. */
    public function test_a_facet_filter_narrows_the_listing(): void
    {
        $category = Category::factory()->root('office-furniture')->create();

        // updateOrCreate, not create: setUp truncates products and categories
        // but not the attribute tables, so these survive between tests in this
        // class and a plain insert collides on the unique code.
        $group = AttributeGroup::query()->updateOrCreate(
            ['code' => 'general'],
            ['uuid' => (string) Str::uuid(), 'name' => 'General'],
        );

        $attribute = Attribute::query()->updateOrCreate(
            ['code' => 'colour'],
            [
                'uuid' => (string) Str::uuid(),
                'group_id' => $group->id,
                'name' => 'Colour',
                'data_type' => 'string',
                'is_filterable' => true,
            ],
        );

        foreach (['Black', 'Black', 'Grey'] as $colour) {
            $product = Product::factory()->create();
            $product->categories()->attach($category->id);
            $product->attributeValues()->create([
                'attribute_id' => $attribute->id,
                'value_raw' => $colour,
                'value_presentation' => $colour,
            ]);
        }

        $this->getJson('/api/v1/categories/office-furniture/products?filters[colour]=Grey')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    // -----------------------------------------------------------------
    // Products
    // -----------------------------------------------------------------

    public function test_a_product_resolves_by_slug(): void
    {
        $brand = Brand::factory()->create(['name' => 'HP', 'slug' => 'hp']);
        $product = Product::factory()->forBrand($brand)->create(['slug' => 'hp-128a-toner']);

        $this->getJson('/api/v1/products/hp-128a-toner')
            ->assertOk()
            ->assertJsonPath('data.slug', 'hp-128a-toner')
            ->assertJsonPath('data.url', '/p/hp-128a-toner/')
            ->assertJsonPath('data.brand.slug', 'hp');
    }

    /** D-008: flat product URLs — one product, one URL, whatever its categories. */
    public function test_a_product_url_is_flat_regardless_of_category(): void
    {
        $department = Category::factory()->root('office-furniture')->create();
        $sub = Category::factory()->childOf($department, 'seating')->create();

        $product = Product::factory()->create([
            'slug' => 'ergonomic-chair',
            'primary_category_id' => $sub->id,
        ]);
        $product->categories()->attach($sub->id);

        $this->getJson('/api/v1/products/ergonomic-chair')
            ->assertOk()
            ->assertJsonPath('data.url', '/p/ergonomic-chair/');
    }

    public function test_a_draft_product_is_not_reachable(): void
    {
        $product = Product::factory()->draft()->create();

        $this->getJson('/api/v1/products/'.$product->slug)->assertNotFound();
    }

    /** D-002: no prices anywhere, but pricing_mode drives the CTA from data. */
    public function test_a_product_carries_pricing_mode_and_no_price(): void
    {
        $product = Product::factory()->create();

        $response = $this->getJson('/api/v1/products/'.$product->slug);

        $response->assertOk()->assertJsonPath('data.pricing_mode', 'quote_only');
        $this->assertStringNotContainsString('price_ngn', $response->getContent());
    }

    public function test_a_product_returns_its_breadcrumb_from_the_primary_category(): void
    {
        $department = Category::factory()->root('it-products')->create();
        $sub = Category::factory()->childOf($department, 'computers')->create();

        $product = Product::factory()->create(['primary_category_id' => $sub->id]);

        $this->getJson('/api/v1/products/'.$product->slug)
            ->assertOk()
            ->assertJsonPath('data.breadcrumb.0.url', '/c/it-products/')
            ->assertJsonPath('data.breadcrumb.1.url', '/c/it-products/computers/');
    }

    public function test_b2b_quantity_rules_are_exposed(): void
    {
        $product = Product::factory()->create(['min_order_qty' => 10, 'qty_step' => 5]);

        $this->getJson('/api/v1/products/'.$product->slug)
            ->assertOk()
            ->assertJsonPath('data.min_order_qty', 10)
            ->assertJsonPath('data.qty_step', 5);
    }

    // -----------------------------------------------------------------
    // Brands
    // -----------------------------------------------------------------

    public function test_a_brand_hub_lists_only_its_own_active_products(): void
    {
        $hp = Brand::factory()->create(['slug' => 'hp']);
        $canon = Brand::factory()->create(['slug' => 'canon']);

        $hpProduct = Product::factory()->forBrand($hp)->create();
        Product::factory()->forBrand($canon)->create();
        Product::factory()->forBrand($hp)->draft()->create();

        $this->getJson('/api/v1/brands/hp/products')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', $hpProduct->slug);
    }

    // -----------------------------------------------------------------
    // Caching
    // -----------------------------------------------------------------

    public function test_catalogue_reads_return_a_strong_etag_and_honour_it(): void
    {
        Category::factory()->root('office-furniture')->create();

        $first = $this->getJson('/api/v1/navigation')->assertOk();
        $etag = $first->headers->get('ETag');

        $this->assertNotNull($etag);
        $this->assertStringStartsNotWith('W/', $etag, 'ETags must be strong, not weak.');

        $this->withHeaders(['If-None-Match' => $etag])
            ->getJson('/api/v1/navigation')
            ->assertStatus(304);
    }

    public function test_a_stale_etag_returns_a_fresh_body(): void
    {
        Category::factory()->root('office-furniture')->create();

        $this->withHeaders(['If-None-Match' => '"stale"'])
            ->getJson('/api/v1/navigation')
            ->assertOk();
    }

    public function test_catalogue_reads_are_publicly_cacheable(): void
    {
        $response = $this->getJson('/api/v1/brands')->assertOk();

        $cacheControl = $response->headers->get('Cache-Control');

        $this->assertStringContainsString('public', $cacheControl);
        $this->assertStringContainsString('max-age=60', $cacheControl);
        $this->assertStringContainsString('stale-while-revalidate=600', $cacheControl);
    }
}
