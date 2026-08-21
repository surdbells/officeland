<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Content\ContentMinimums;
use App\Models\Attribute;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * The Phase 12 gate: no indexable page below the content minimums (§7).
 *
 * These assert the ENFORCEMENT, not the reporting. A word counter next to a
 * checkbox is not a gate — the question is what happens when somebody sets the
 * column anyway, from a bulk edit or an importer, which is how 15,000 thin
 * pages would actually enter the sitemap.
 */
final class ContentMinimumsTest extends TestCase
{
    use RefreshDatabase;

    private ContentMinimums $minimums;

    protected function setUp(): void
    {
        parent::setUp();

        $this->minimums = new ContentMinimums;
    }

    private function words(int $count): string
    {
        return '<p>'.implode(' ', array_fill(0, $count, 'chair')).'</p>';
    }

    /** A product one field short of ready, so each test can break one thing. */
    private function nearlyReady(array $attributes = []): Product
    {
        $product = Product::factory()->create(array_merge([
            'status' => 'active',
            'is_indexable' => false,
            'description_html' => $this->words(ContentMinimums::PRODUCT_WORDS + 5),
            'warranty_text' => '12 months.',
            'box_contents' => 'The unit, a cable.',
            'lead_time_days' => 5,
        ], $attributes));

        for ($i = 0; $i < ContentMinimums::PRODUCT_MIN_ATTRIBUTES; $i++) {
            $attribute = Attribute::query()->create([
                'uuid' => (string) Str::uuid(),
                'code' => 'spec_'.Str::lower(Str::random(8)),
                'name' => 'Spec '.$i,
                'data_type' => 'string',
            ]);

            ProductAttributeValue::query()->create([
                'product_id' => $product->id,
                'attribute_id' => $attribute->id,
                'value_raw' => 'v'.$i,
                'value_presentation' => 'V'.$i,
            ]);
        }

        return $product->refresh();
    }

    // -----------------------------------------------------------------
    // Enforcement
    // -----------------------------------------------------------------

    public function test_a_thin_product_cannot_be_marked_indexable(): void
    {
        $product = Product::factory()->create(['status' => 'active', 'is_indexable' => false]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/cannot be indexed yet/');

        $product->forceFill(['is_indexable' => true])->save();
    }

    /**
     * The rule is enforced on the model, so it holds for the importer and any
     * future sync — not only for the admin form.
     */
    public function test_the_guard_survives_a_mass_update(): void
    {
        $product = Product::factory()->create(['status' => 'active', 'is_indexable' => false]);

        $this->expectException(RuntimeException::class);

        $product->update(['is_indexable' => true]);
    }

    public function test_a_complete_product_can_be_marked_indexable(): void
    {
        $product = $this->nearlyReady();

        $product->forceFill(['is_indexable' => true])->save();

        $this->assertTrue($product->refresh()->is_indexable);
    }

    /**
     * Only the transition is guarded. An already-live page being saved for an
     * unrelated reason must not blow up, and must not be silently un-indexed:
     * removing a live page from the sitemap as a side effect of an edit is a
     * bigger surprise than the thin page it would prevent.
     */
    public function test_an_already_indexable_product_can_still_be_saved(): void
    {
        $product = $this->nearlyReady();
        $product->forceFill(['is_indexable' => true])->save();

        $product->forceFill(['description_html' => '<p>Trimmed.</p>'])->save();

        $this->assertTrue($product->refresh()->is_indexable);
        $this->assertFalse($product->meetsContentMinimums());
    }

    public function test_a_category_without_an_intro_cannot_be_indexed(): void
    {
        $category = Category::factory()->root('chairs')->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/intro needs \d+ more words/');

        $category->forceFill(['is_indexable' => true])->save();
    }

    public function test_a_category_with_an_intro_can_be_indexed(): void
    {
        $category = Category::factory()->root('chairs')->create([
            'intro_copy' => $this->words(ContentMinimums::CATEGORY_WORDS_MIN),
        ]);

        $category->forceFill(['is_indexable' => true])->save();

        $this->assertTrue($category->refresh()->is_indexable);
    }

    /**
     * The upper bound of 150–300 is a house-style note, not an SEO rule. A long
     * intro is a reason to tell the editor, never a reason to withhold the page
     * from Google.
     */
    public function test_a_long_category_intro_is_advisory_only(): void
    {
        $category = Category::factory()->root('chairs')->create([
            'intro_copy' => $this->words(ContentMinimums::CATEGORY_WORDS_ADVISORY_MAX + 100),
        ]);

        $category->forceFill(['is_indexable' => true])->save();

        $this->assertTrue($category->refresh()->is_indexable);
        $this->assertTrue($this->minimums->categoryReadsLong($category));
    }

    // -----------------------------------------------------------------
    // The individual §14.7 minimums
    // -----------------------------------------------------------------

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function missingFields(): array
    {
        return [
            'no warranty' => [['warranty_text' => null], 'no warranty stated'],
            'no box contents' => [['box_contents' => null], 'no "in the box" list'],
            // Not in stock and no lead time: the page answers nothing a buyer
            // is deciding on. In-stock is exempt — see the next test.
            'no lead time' => [
                ['lead_time_days' => null, 'availability' => 'lead_time'],
                'no lead time, and not marked in stock',
            ],
            'short description' => [['description_html' => '<p>Too short.</p>'], 'description needs'],
        ];
    }

    /**
     * @param  array<string, mixed>  $missing
     */
    #[DataProvider('missingFields')]
    public function test_each_minimum_is_required(array $missing, string $expected): void
    {
        $product = $this->nearlyReady($missing);

        $readiness = $this->minimums->product($product);

        $this->assertFalse($readiness->isReady());
        $this->assertStringContainsString($expected, $readiness->summary());
    }

    /**
     * "In stock" IS the answer to how soon, so a number is not also demanded.
     * A required field with no meaning gets filled with a lie.
     */
    public function test_an_in_stock_product_needs_no_lead_time(): void
    {
        $product = $this->nearlyReady([
            'lead_time_days' => null,
            'availability' => 'in_stock',
        ]);

        $this->assertTrue($this->minimums->product($product)->isReady());
    }

    public function test_a_product_needs_a_spec_table(): void
    {
        $product = Product::factory()->create([
            'status' => 'active',
            'description_html' => $this->words(ContentMinimums::PRODUCT_WORDS + 5),
            'warranty_text' => '12 months.',
            'box_contents' => 'The unit.',
            'lead_time_days' => 3,
        ]);

        $this->assertStringContainsString(
            'needs 3 more specifications',
            $this->minimums->product($product)->summary(),
        );
    }

    /**
     * D-015: supplier prose is a draft, never the published text. Publishing
     * Icecat's own words is the legacy site's defect at three times the scale.
     */
    public function test_the_supplier_text_verbatim_is_not_original_copy(): void
    {
        $prose = implode(' ', array_fill(0, ContentMinimums::PRODUCT_WORDS + 5, 'chair'));

        $product = $this->nearlyReady([
            'description_html' => '<p>'.$prose.'</p>',
            'source_description' => $prose,
        ]);

        $this->assertStringContainsString(
            'supplier text verbatim',
            $this->minimums->product($product)->summary(),
        );
    }

    /**
     * Reformatting through the editor changes the markup and not one word of
     * the prose, so the comparison is made on the text.
     */
    public function test_reformatting_the_supplier_text_does_not_make_it_original(): void
    {
        $prose = implode(' ', array_fill(0, ContentMinimums::PRODUCT_WORDS + 5, 'chair'));

        $product = $this->nearlyReady([
            'description_html' => '<div><p>  '.strtoupper($prose).'  </p></div>',
            'source_description' => $prose,
        ]);

        $this->assertStringContainsString(
            'supplier text verbatim',
            $this->minimums->product($product)->summary(),
        );
    }

    // -----------------------------------------------------------------
    // Word counting
    // -----------------------------------------------------------------

    /**
     * str_word_count is locale-bound and drops non-ASCII, which would silently
     * undercount exactly the model codes and dimensions that make a
     * specification sentence worth reading.
     */
    public function test_words_are_counted_including_codes_and_symbols(): void
    {
        $this->assertSame(5, $this->minimums->words('<p>HP LaserJet M404dn — 38ppm</p>'));
        $this->assertSame(0, $this->minimums->words('<p>   </p>'));
        $this->assertSame(0, $this->minimums->words(null));
    }
}
