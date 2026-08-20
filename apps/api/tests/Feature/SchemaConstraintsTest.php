<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Proves the database enforces its own rules.
 *
 * A schema that migrates but does not enforce is worthless: the legacy
 * taxonomy drifted to inconsistent depth and duplicate branches precisely
 * because nothing stopped it. Every constraint asserted here exists to make a
 * specific past failure impossible to repeat.
 *
 * Runs against real PostgreSQL through PgBouncer — SQLite can express none of
 * these, and section 19.2 requires the pooler to be in the path.
 */
final class SchemaConstraintsTest extends TestCase
{
    /**
     * Tables this class writes to, truncated before each test.
     *
     * RefreshDatabase is deliberately NOT used here. It wraps every test in a
     * single transaction, and PostgreSQL aborts the whole transaction on a
     * constraint violation — so a test that deliberately triggers one leaves
     * the connection unusable for everything that follows, surfacing as
     * unrelated failures elsewhere in the suite. Savepoints do not fully
     * contain it through a transaction pooler either.
     *
     * Running in autocommit means each violation aborts only its own implicit
     * transaction, which is exactly the isolation these tests need.
     *
     * @var list<string>
     */
    private const TABLES = [
        'product_relations',
        'quote_basket_items',
        'quote_baskets',
        'products',
        'categories',
        'redirects',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('TRUNCATE '.implode(', ', self::TABLES).' RESTART IDENTITY CASCADE');
    }

    /**
     * Asserts the database rejects a write.
     *
     * @param  callable(): mixed  $write
     */
    private function assertDatabaseRejects(callable $write, string $because): void
    {
        try {
            $write();
            $this->fail("Expected the database to reject this write: {$because}");
        } catch (QueryException) {
            // Expected.
        }

        // Proves the connection survived the rejection. Without this the test
        // could pass while leaving the suite broken.
        $this->assertSame(1, DB::select('select 1 as ok')[0]->ok);
    }

    /** @param array<string, mixed> $overrides */
    private function insertCategory(array $overrides = []): void
    {
        DB::table('categories')->insert(array_merge([
            'uuid' => (string) Str::uuid(),
            'name' => 'Office Furniture',
            'slug' => 'office-furniture',
            'path' => '/office-furniture/',
            'depth' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function newBasketId(string $seed): int
    {
        return (int) DB::table('quote_baskets')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'session_token_hash' => hash('sha256', $seed),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // -----------------------------------------------------------------
    // Category tree
    // -----------------------------------------------------------------

    public function test_category_depth_is_capped_at_three(): void
    {
        $this->insertCategory(['slug' => 'valid-leaf', 'path' => '/a/b/c/', 'depth' => 3]);

        $this->assertDatabaseRejects(
            fn () => $this->insertCategory(['slug' => 'too-deep', 'path' => '/a/b/c/d/', 'depth' => 4]),
            'depth 4 exceeds the maximum of 3',
        );
    }

    public function test_category_depth_must_be_at_least_one(): void
    {
        $this->assertDatabaseRejects(
            fn () => $this->insertCategory(['depth' => 0]),
            'depth 0 is below the minimum of 1',
        );
    }

    /**
     * A materialized path must be slash-delimited at both ends, or prefix
     * matching produces false positives — '/office' would match
     * '/office-furniture/' and pull an unrelated subtree into the results.
     */
    #[DataProvider('malformedPaths')]
    public function test_category_path_format_is_enforced(string $path): void
    {
        $this->assertDatabaseRejects(
            fn () => $this->insertCategory(['slug' => 'bad-'.md5($path), 'path' => $path]),
            "path '{$path}' is malformed",
        );
    }

    /** @return array<string, array{string}> */
    public static function malformedPaths(): array
    {
        return [
            'no leading slash' => ['office-furniture/'],
            'no trailing slash' => ['/office-furniture'],
            'uppercase' => ['/Office-Furniture/'],
            'underscore' => ['/office_furniture/'],
            'double slash' => ['//office-furniture/'],
            'four levels' => ['/a/b/c/d/'],
            'trailing hyphen' => ['/office-/'],
        ];
    }

    public function test_category_path_is_unique(): void
    {
        $this->insertCategory();

        $this->assertDatabaseRejects(
            fn () => $this->insertCategory(['slug' => 'different-slug']),
            'path must be unique',
        );
    }

    public function test_a_valid_three_level_path_is_accepted(): void
    {
        $this->insertCategory([
            'slug' => 'executive-chairs',
            'path' => '/office-furniture/seating/executive-chairs/',
            'depth' => 3,
        ]);

        $this->assertSame(1, DB::table('categories')->count());
    }

    // -----------------------------------------------------------------
    // Redirects — silent failures in production, so rejected at write time
    // -----------------------------------------------------------------

    public function test_a_301_without_a_destination_is_rejected(): void
    {
        $this->assertDatabaseRejects(
            fn () => DB::table('redirects')->insert([
                'from_path' => '/old-page/', 'to_path' => null, 'status_code' => 301,
                'created_at' => now(), 'updated_at' => now(),
            ]),
            'a 301 with no destination is a broken redirect',
        );
    }

    public function test_a_410_with_a_destination_is_rejected(): void
    {
        $this->assertDatabaseRejects(
            fn () => DB::table('redirects')->insert([
                'from_path' => '/cart/', 'to_path' => '/somewhere/', 'status_code' => 410,
                'created_at' => now(), 'updated_at' => now(),
            ]),
            'a 410 means gone; a destination contradicts it',
        );
    }

    public function test_a_self_redirect_is_rejected(): void
    {
        $this->assertDatabaseRejects(
            fn () => DB::table('redirects')->insert([
                'from_path' => '/loop/', 'to_path' => '/loop/', 'status_code' => 301,
                'created_at' => now(), 'updated_at' => now(),
            ]),
            'a path redirecting to itself is an infinite loop',
        );
    }

    public function test_an_invalid_status_code_is_rejected(): void
    {
        $this->assertDatabaseRejects(
            fn () => DB::table('redirects')->insert([
                'from_path' => '/teapot/', 'to_path' => '/tea/', 'status_code' => 418,
                'created_at' => now(), 'updated_at' => now(),
            ]),
            'only 301, 302 and 410 are permitted',
        );
    }

    public function test_valid_redirects_are_accepted(): void
    {
        DB::table('redirects')->insert([
            [
                'from_path' => '/product-category/hp/printers/',
                'to_path' => '/brands/hp/printers/',
                'status_code' => 301, 'source' => 'migration',
                'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'from_path' => '/cart/', 'to_path' => null,
                'status_code' => 410, 'source' => 'migration',
                'created_at' => now(), 'updated_at' => now(),
            ],
        ]);

        $this->assertSame(2, DB::table('redirects')->count());
    }

    // -----------------------------------------------------------------
    // Quote basket
    // -----------------------------------------------------------------

    public function test_a_basket_line_must_reference_a_product_or_carry_free_text(): void
    {
        $basketId = $this->newBasketId('empty-line');

        $this->assertDatabaseRejects(
            fn () => DB::table('quote_basket_items')->insert([
                'basket_id' => $basketId, 'product_id' => null,
                'free_text_description' => '   ', 'qty' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]),
            'a line with neither a product nor free text is meaningless',
        );
    }

    public function test_a_free_text_basket_line_is_accepted(): void
    {
        $basketId = $this->newBasketId('free-text');

        DB::table('quote_basket_items')->insert([
            'basket_id' => $basketId,
            'free_text_description' => '120x pack A4 paper, brand flexible',
            'qty' => 120,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame(1, DB::table('quote_basket_items')->count());
    }

    public function test_basket_quantity_must_be_positive(): void
    {
        $basketId = $this->newBasketId('zero-qty');

        $this->assertDatabaseRejects(
            fn () => DB::table('quote_basket_items')->insert([
                'basket_id' => $basketId,
                'free_text_description' => 'zero quantity', 'qty' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]),
            'a quantity of zero cannot be quoted',
        );
    }

    // -----------------------------------------------------------------
    // Products
    // -----------------------------------------------------------------

    public function test_product_quantity_rules_must_be_coherent(): void
    {
        $this->assertDatabaseRejects(
            fn () => DB::table('products')->insert([
                'uuid' => (string) Str::uuid(),
                'name' => 'Broken Pack Size', 'slug' => 'broken-pack-size',
                'min_order_qty' => 0, 'qty_step' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]),
            'a minimum order quantity of zero is not a pack size',
        );
    }

    public function test_a_product_cannot_relate_to_itself(): void
    {
        $productId = DB::table('products')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'name' => 'Self Relating Product', 'slug' => 'self-relating-product',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertDatabaseRejects(
            fn () => DB::table('product_relations')->insert([
                'product_id' => $productId,
                'related_product_id' => $productId,
                'relation_type' => 'accessory',
            ]),
            'a product cannot be its own accessory',
        );
    }

    /**
     * The generated search_vector is what every catalogue search depends on.
     * If it silently stopped populating, search would return nothing and no
     * other test would notice.
     */
    public function test_the_search_vector_is_generated_and_weighted(): void
    {
        DB::table('products')->insert([
            'uuid' => (string) Str::uuid(),
            'name' => 'HP 128A Black LaserJet Toner Cartridge',
            'slug' => 'hp-128a-black-laserjet-toner-cartridge',
            'brand_name_cached' => 'HP',
            'mpn' => 'CE320A',
            'search_text_specs' => 'page yield 2000 pages colour black',
            'short_description' => 'Original HP toner for LaserJet printers.',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $vector = DB::table('products')
            ->selectRaw('search_vector::text as vector')
            ->where('slug', 'hp-128a-black-laserjet-toner-cartridge')
            ->value('vector');

        $this->assertNotNull($vector);
        // PostgreSQL renders a tsvector as 'lexeme':position[weight], e.g.
        // 'toner':5A. Weight D is the default and is not printed.
        $this->assertMatchesRegularExpression("/'toner':\d+A/", $vector, 'name terms must carry weight A');
        $this->assertMatchesRegularExpression("/'ce320a':\d+B/", $vector, 'MPN must carry weight B');
        $this->assertMatchesRegularExpression("/'yield':\d+C/", $vector, 'spec text must carry weight C');
    }

    public function test_the_search_vector_updates_when_the_row_changes(): void
    {
        DB::table('products')->insert([
            'uuid' => (string) Str::uuid(),
            'name' => 'Original Name',
            'slug' => 'regenerating-product',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('products')->where('slug', 'regenerating-product')
            ->update(['name' => 'Ergonomic Mesh Task Chair']);

        $vector = DB::table('products')
            ->selectRaw('search_vector::text as vector')
            ->where('slug', 'regenerating-product')
            ->value('vector');

        $this->assertStringContainsString('ergonom', $vector);
        $this->assertStringNotContainsString('origin', $vector);
    }
}
