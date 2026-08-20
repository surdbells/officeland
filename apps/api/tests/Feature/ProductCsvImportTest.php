<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Import\ProductCsvImporter;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Section 11: the importer "must be genuinely robust, not a toy".
 *
 * Under D-022 (Open Icecat only) it is also the only route for brands Icecat
 * does not cover — Baseus among them — so it is not a convenience feature.
 */
final class ProductCsvImportTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('audit_log')->delete();
        DB::table('product_category')->delete();
        DB::table('products')->delete();
        DB::table('categories')->delete();
        DB::table('brands')->delete();

        $this->workspace = sys_get_temp_dir().'/csv-'.bin2hex(random_bytes(4));
        mkdir($this->workspace, 0o775, true);
    }

    protected function tearDown(): void
    {
        DB::table('audit_log')->delete();
        DB::table('product_category')->delete();
        DB::table('products')->delete();
        DB::table('categories')->delete();
        DB::table('brands')->delete();

        foreach (glob($this->workspace.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->workspace);

        parent::tearDown();
    }

    private function csv(string $contents, string $name = 'import.csv'): string
    {
        $path = $this->workspace.'/'.$name;
        file_put_contents($path, $contents);

        return $path;
    }

    private function leaf(): Category
    {
        $d = Category::factory()->root('printers-consumables')->create();
        $s = Category::factory()->childOf($d, 'ink-toner')->create();

        return Category::factory()->childOf($s, 'toner-cartridges')->create();
    }

    // -----------------------------------------------------------------
    // Dry run
    // -----------------------------------------------------------------

    public function test_a_preview_writes_nothing(): void
    {
        $this->leaf();

        $path = $this->csv(<<<'CSV'
        name,mpn,brand,category_path
        HP 128A Black Toner,CE320A,HP,/printers-consumables/ink-toner/toner-cartridges/
        CSV);

        $report = app(ProductCsvImporter::class)->preview($path);

        $this->assertSame(1, $report->createCount());
        $this->assertSame(0, Product::query()->count());
        $this->assertStringContainsString('Would import', $report->summary());
    }

    public function test_applying_creates_the_product(): void
    {
        $this->leaf();

        $path = $this->csv(<<<'CSV'
        name,mpn,brand,category_path,availability,status
        HP 128A Black Toner,CE320A,HP,/printers-consumables/ink-toner/toner-cartridges/,in_stock,active
        CSV);

        $report = app(ProductCsvImporter::class)->apply($path);

        $this->assertSame(1, $report->createCount());

        $product = Product::query()->where('mpn', 'CE320A')->firstOrFail();
        $this->assertSame('HP 128A Black Toner', $product->name);
        $this->assertSame('active', $product->status);
        $this->assertSame('csv', $product->source);
        $this->assertNotNull($product->brand_id);
    }

    // -----------------------------------------------------------------
    // Idempotency — the property that makes re-import safe
    // -----------------------------------------------------------------

    public function test_re_running_the_same_file_changes_nothing(): void
    {
        $this->leaf();

        $path = $this->csv(<<<'CSV'
        name,mpn,brand,category_path,availability
        HP 128A Black Toner,CE320A,HP,/printers-consumables/ink-toner/toner-cartridges/,in_stock
        CSV);

        $importer = app(ProductCsvImporter::class);
        $importer->apply($path);

        $second = $importer->apply($path);

        $this->assertSame(0, $second->createCount());
        $this->assertSame(0, $second->updateCount());
        $this->assertSame(1, $second->unchangedCount());
        $this->assertSame(1, Product::query()->count());
    }

    /**
     * Matching is on MPN + brand, never on name. Editors rewrite names
     * constantly, and matching on them would create a duplicate on every title
     * correction — which is precisely the legacy site's defect.
     */
    public function test_a_renamed_product_is_updated_not_duplicated(): void
    {
        $this->leaf();
        $importer = app(ProductCsvImporter::class);

        $importer->apply($this->csv(<<<'CSV'
        name,mpn,brand,category_path
        Old supplier title,CE320A,HP,/printers-consumables/ink-toner/toner-cartridges/
        CSV, 'first.csv'));

        $report = $importer->apply($this->csv(<<<'CSV'
        name,mpn,brand,category_path
        HP 128A Black LaserJet Toner Cartridge,CE320A,HP,/printers-consumables/ink-toner/toner-cartridges/
        CSV, 'second.csv'));

        $this->assertSame(1, $report->updateCount());
        $this->assertSame(1, Product::query()->count());
        $this->assertSame('HP 128A Black LaserJet Toner Cartridge', Product::query()->firstOrFail()->name);
    }

    /**
     * An empty cell means "leave this alone", not "blank it out". Editors
     * routinely submit partial files to correct a single column.
     */
    public function test_an_empty_cell_does_not_erase_an_existing_value(): void
    {
        $this->leaf();
        $importer = app(ProductCsvImporter::class);

        $importer->apply($this->csv(<<<'CSV'
        name,mpn,brand,category_path,short_description
        HP 128A,CE320A,HP,/printers-consumables/ink-toner/toner-cartridges/,Original summary
        CSV, 'full.csv'));

        $importer->apply($this->csv(<<<'CSV'
        name,mpn,brand,short_description
        HP 128A Black Toner,CE320A,HP,
        CSV, 'partial.csv'));

        $product = Product::query()->firstOrFail();

        $this->assertSame('HP 128A Black Toner', $product->name);
        $this->assertSame('Original summary', $product->short_description);
    }

    // -----------------------------------------------------------------
    // Rejection — reported, never silent
    // -----------------------------------------------------------------

    public function test_a_missing_required_column_is_rejected(): void
    {
        $report = app(ProductCsvImporter::class)->preview($this->csv("mpn,brand\nCE320A,HP\n"));

        $this->assertTrue($report->hasErrors());
        $this->assertStringContainsString('name', $report->errors()[0]['message']);
    }

    public function test_an_unknown_column_warns_but_does_not_stop_the_import(): void
    {
        $this->leaf();

        $report = app(ProductCsvImporter::class)->preview($this->csv(<<<'CSV'
        name,mpn,favourite_colour
        HP 128A,CE320A,blue
        CSV));

        $this->assertFalse($report->hasErrors());
        $this->assertStringContainsString('favourite_colour', $report->warnings()[0]['message']);
        $this->assertSame(1, $report->createCount());
    }

    public function test_an_invalid_enum_value_is_rejected_with_the_allowed_values(): void
    {
        $report = app(ProductCsvImporter::class)->preview($this->csv(<<<'CSV'
        name,availability
        Broken product,maybe_someday
        CSV));

        $this->assertTrue($report->hasErrors());
        $this->assertStringContainsString('in_stock', $report->errors()[0]['message']);
    }

    /**
     * The tree is a designed artefact with a maximum depth and hand-written
     * copy. An import must not be able to grow it by typo.
     */
    public function test_an_unknown_category_is_rejected_and_never_created(): void
    {
        $report = app(ProductCsvImporter::class)->apply($this->csv(<<<'CSV'
        name,category_path
        Orphan product,/does/not/exist/
        CSV));

        $this->assertTrue($report->hasErrors());
        $this->assertSame(0, Category::query()->count());
        $this->assertSame(0, Product::query()->count());
    }

    /** One bad row must not abandon the other 4,999. */
    public function test_a_bad_row_does_not_stop_the_good_ones(): void
    {
        $this->leaf();

        $report = app(ProductCsvImporter::class)->apply($this->csv(<<<'CSV'
        name,mpn,brand,category_path,availability
        Good one,AAA111,HP,/printers-consumables/ink-toner/toner-cartridges/,in_stock
        ,BBB222,HP,/printers-consumables/ink-toner/toner-cartridges/,in_stock
        Good two,CCC333,HP,/printers-consumables/ink-toner/toner-cartridges/,in_stock
        CSV));

        $this->assertSame(2, $report->createCount());
        $this->assertSame(1, $report->errorCount());
        // Row numbers are the ones the editor sees in their spreadsheet.
        $this->assertSame(3, $report->errors()[0]['line']);
        $this->assertSame(2, Product::query()->count());
    }

    public function test_a_row_with_the_wrong_column_count_is_reported(): void
    {
        $report = app(ProductCsvImporter::class)->preview($this->csv(<<<'CSV'
        name,mpn,brand
        Too few columns,CE320A
        CSV));

        $this->assertTrue($report->hasErrors());
        $this->assertStringContainsString('columns', $report->errors()[0]['message']);
    }

    /** Excel writes a BOM, which otherwise makes the first column unmatchable. */
    public function test_a_utf8_bom_from_excel_does_not_break_the_header(): void
    {
        $this->leaf();

        $report = app(ProductCsvImporter::class)->preview(
            $this->csv("\xEF\xBB\xBFname,mpn\nHP 128A,CE320A\n")
        );

        $this->assertFalse($report->hasErrors());
        $this->assertSame(1, $report->createCount());
    }

    public function test_an_empty_file_is_reported_clearly(): void
    {
        $report = app(ProductCsvImporter::class)->preview($this->csv(''));

        $this->assertTrue($report->hasErrors());
        $this->assertStringContainsString('empty', $report->errors()[0]['message']);
    }

    // -----------------------------------------------------------------
    // Brands
    // -----------------------------------------------------------------

    public function test_an_unknown_brand_is_created_but_reported(): void
    {
        $report = app(ProductCsvImporter::class)->apply($this->csv(<<<'CSV'
        name,mpn,brand
        Baseus 65W Charger,BS65W,Baseus
        CSV));

        // Silently creating brands would let a typo become a permanent brand
        // hub page with its own URL.
        $this->assertNotEmpty($report->warnings());
        $this->assertStringContainsString('Baseus', $report->warnings()[0]['message']);
        $this->assertNotNull(Brand::query()->where('name', 'Baseus')->first());
    }

    public function test_an_existing_brand_is_reused_not_duplicated(): void
    {
        Brand::factory()->create(['name' => 'HP']);

        app(ProductCsvImporter::class)->apply($this->csv(<<<'CSV'
        name,mpn,brand
        HP 128A,CE320A,HP
        CSV));

        $this->assertSame(1, Brand::query()->where('name', 'HP')->count());
    }

    public function test_slugs_are_generated_and_kept_unique(): void
    {
        app(ProductCsvImporter::class)->apply($this->csv(<<<'CSV'
        name,mpn,brand
        Identical Name,AAA111,HP
        Identical Name,BBB222,HP
        CSV));

        $slugs = Product::query()->pluck('slug')->all();

        $this->assertCount(2, $slugs);
        $this->assertCount(2, array_unique($slugs));
    }
}
