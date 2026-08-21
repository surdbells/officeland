<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Content\ContentMinimums;
use App\Content\DescriptionDrafter;
use App\Content\SimilarityGate;
use App\Models\Attribute;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Drafting, the similarity gate, and the approval that stands between them and
 * the sitemap (§12b, SEO_SPEC §7.2–7.3).
 *
 * The property under test throughout: **generated copy cannot reach Google
 * without a person's name against it.** D-010 puts 15,000 products in the index
 * on day one, and the only thing separating that from 15,000 pages of
 * boilerplate is the review step being mandatory rather than encouraged.
 */
final class DescriptionPipelineTest extends TestCase
{
    use RefreshDatabase;

    private DescriptionDrafter $drafter;

    private SimilarityGate $gate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->drafter = new DescriptionDrafter(new ContentMinimums);
        $this->gate = new SimilarityGate;
    }

    private function attribute(string $name, int $order = 0): Attribute
    {
        return Attribute::query()->create([
            'uuid' => (string) Str::uuid(),
            'code' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'name' => $name,
            'data_type' => 'string',
            'display_order' => $order,
        ]);
    }

    /**
     * @param  array<string, string>  $specifications
     */
    private function product(string $name, array $specifications, ?Brand $brand = null, ?Category $category = null): Product
    {
        $product = Product::factory()->create([
            'name' => $name,
            'status' => 'active',
            'brand_id' => $brand?->id,
            'brand_name_cached' => $brand?->name,
            'primary_category_id' => $category?->id,
            'warranty_text' => '12 months return-to-base.',
            'box_contents' => 'The unit and a power cable.',
            'lead_time_days' => 5,
        ]);

        $order = 0;

        foreach ($specifications as $label => $value) {
            ProductAttributeValue::query()->create([
                'product_id' => $product->id,
                'attribute_id' => $this->attribute($label, $order++)->id,
                'value_raw' => $value,
                'value_presentation' => $value,
            ]);
        }

        return $product->refresh();
    }

    // -----------------------------------------------------------------
    // Drafting
    // -----------------------------------------------------------------

    public function test_a_draft_is_built_from_the_products_own_specifications(): void
    {
        $product = $this->product('HP LaserJet Pro M404dn', [
            'Print speed' => '38 pages per minute',
            'Duplex printing' => 'Automatic',
            'Connectivity' => 'Gigabit Ethernet and USB',
            'Monthly duty cycle' => '80,000 pages',
        ]);

        $draft = $this->drafter->draft($product);

        $this->assertNotNull($draft);
        $this->assertStringContainsString('38 pages per minute', $draft);
        $this->assertStringContainsString('Gigabit Ethernet', $draft);
    }

    /**
     * The whole point of the review step. A draft is stamped, and a stamped
     * description cannot be indexed until somebody approves it — however many
     * words it has.
     */
    public function test_a_generated_description_cannot_be_indexed_unapproved(): void
    {
        $product = $this->product('HP LaserJet Pro M404dn', [
            'Print speed' => '38 pages per minute',
            'Duplex printing' => 'Automatic two-sided printing as standard',
            'Connectivity' => 'Gigabit Ethernet, USB 2.0 and optional wireless',
            'Monthly duty cycle' => '80,000 pages',
        ]);

        $this->drafter->draft($product);
        $product->refresh();

        $this->assertNotNull($product->description_generated_at);
        $this->assertStringContainsString(
            'not yet approved by a person',
            app(ContentMinimums::class)->product($product)->summary(),
        );

        $this->expectException(RuntimeException::class);
        $product->forceFill(['is_indexable' => true])->save();
    }

    public function test_approval_releases_a_generated_description(): void
    {
        $product = $this->product('HP LaserJet Pro M404dn', [
            'Print speed' => '38 pages per minute',
            'Duplex printing' => 'Automatic two-sided printing as standard',
            'Connectivity' => 'Gigabit Ethernet, USB 2.0 and optional wireless',
            'Monthly duty cycle' => '80,000 pages',
        ]);

        $this->drafter->draft($product);

        $product->refresh()->forceFill(['description_approved_at' => now()->addSecond()])->save();

        $product->refresh()->forceFill(['is_indexable' => true])->save();

        $this->assertTrue($product->refresh()->is_indexable);
    }

    /**
     * Re-running the drafter across the catalogue must not discard a week of
     * editing. An approved description is left exactly where it is.
     */
    public function test_the_drafter_never_overwrites_approved_copy(): void
    {
        $product = $this->product('HP LaserJet Pro M404dn', [
            'Print speed' => '38 pages per minute',
            'Duplex printing' => 'Automatic',
            'Connectivity' => 'Gigabit Ethernet',
        ]);

        $product->forceFill([
            'description_html' => '<p>Written by a person.</p>',
            'description_approved_at' => now(),
        ])->save();

        $this->assertNull($this->drafter->draft($product));
        $this->assertSame('<p>Written by a person.</p>', $product->refresh()->description_html);
    }

    /**
     * A product with nothing to say about it does not get a description built
     * out of nothing. Padding to reach 120 words IS the boilerplate §14.3
     * forbids — the honest output is no draft and a place in the queue.
     */
    public function test_a_product_without_specifications_gets_no_draft(): void
    {
        $product = $this->product('Mystery Item', []);

        $this->assertNull($this->drafter->compose($product));
    }

    /**
     * SEO_SPEC §7.2: the draft has to convey what makes THIS product
     * different. An attribute every sibling shares says nothing about it.
     */
    public function test_attributes_shared_by_the_whole_family_are_not_distinguishing(): void
    {
        $brand = Brand::factory()->create(['name' => 'HP']);
        $category = Category::factory()->root('printers')->create();

        $shared = $this->attribute('Warranty period');

        $products = [];

        foreach (['M404dn' => '38 ppm', 'M428fdw' => '40 ppm'] as $name => $speed) {
            $product = $this->product('HP LaserJet '.$name, ['Print speed' => $speed], $brand, $category);

            ProductAttributeValue::query()->create([
                'product_id' => $product->id,
                'attribute_id' => $shared->id,
                'value_raw' => '1 year',
                'value_presentation' => '1 year',
            ]);

            $products[] = $product->refresh();
        }

        $distinguishing = $this->drafter->distinguishingAttributes($products[0]->fresh(['attributeValues.attribute']));

        $names = $distinguishing->map(fn ($v): string => (string) $v->attribute?->name)->all();

        $this->assertContains('Print speed', $names);
        $this->assertNotContains('Warranty period', $names);
    }

    // -----------------------------------------------------------------
    // The similarity gate
    // -----------------------------------------------------------------

    /**
     * The §14.3 defect, exactly: one string with the name swapped in.
     */
    public function test_boilerplate_with_the_name_swapped_in_is_flagged(): void
    {
        $brand = Brand::factory()->create(['name' => 'HP']);
        $category = Category::factory()->root('toner')->create();

        $boilerplate = 'is a genuine cartridge engineered for consistent results page after page. '.
            'It installs in seconds and is covered by the manufacturer warranty. '.
            'Order through our quote desk for volume pricing on larger requirements.';

        $first = $this->product('HP 128A Black', ['Colour' => 'Black'], $brand, $category);
        $first->forceFill(['description_html' => '<p>HP 128A Black '.$boilerplate.'</p>'])->save();

        $second = $this->product('HP 128A Cyan', ['Colour' => 'Cyan'], $brand, $category);
        $second->forceFill(['description_html' => '<p>HP 128A Cyan '.$boilerplate.'</p>'])->save();

        $match = $this->gate->check($second->refresh());

        $this->assertNotNull($match);
        $this->assertSame($first->id, $match->id);
        $this->assertNotNull($second->refresh()->description_flagged_at);
    }

    /**
     * The flag blocks indexing until somebody looks. Genuine variants exist —
     * so this is a review step, not a rejection.
     */
    public function test_a_flagged_description_cannot_be_indexed_until_approved(): void
    {
        $product = $this->product('HP 128A Cyan', ['Colour' => 'Cyan']);

        $product->forceFill([
            'description_html' => '<p>'.implode(' ', array_fill(0, 130, 'cartridge')).'</p>',
            'description_flagged_at' => now(),
            'description_similarity' => 0.99,
        ])->save();

        $this->assertStringContainsString(
            'reads 99% the same as another product',
            app(ContentMinimums::class)->product($product->refresh())->summary(),
        );

        $product->refresh()->forceFill(['description_approved_at' => now()->addSecond()])->save();

        // The similarity objection is gone. Other minimums may still be
        // outstanding — this test is about the flag, not about the whole floor.
        $this->assertStringNotContainsString(
            'the same as another product',
            app(ContentMinimums::class)->product($product->refresh())->summary(),
        );
    }

    /**
     * SEO_SPEC §7.3(b): the comparison is scoped WITHIN a family. Two office
     * chairs reading alike is a problem; a chair reading like a cartridge is
     * not a thing that happens, and comparing globally produces noise that
     * grows with the catalogue.
     */
    public function test_products_in_different_families_are_not_compared(): void
    {
        $prose = implode(' ', array_fill(0, 60, 'identical'));

        $chairs = Category::factory()->root('chairs')->create();
        $toner = Category::factory()->root('toner')->create();
        $brand = Brand::factory()->create(['name' => 'Generic']);

        $chair = $this->product('A Chair', ['Material' => 'Mesh'], $brand, $chairs);
        $chair->forceFill(['description_html' => '<p>'.$prose.'</p>'])->save();

        $cartridge = $this->product('A Cartridge', ['Colour' => 'Black'], $brand, $toner);
        $cartridge->forceFill(['description_html' => '<p>'.$prose.'</p>'])->save();

        $this->assertNull($this->gate->check($cartridge->refresh()));
        $this->assertNull($cartridge->refresh()->description_flagged_at);
    }

    /**
     * The identifiers are stripped before comparing. Left in, they are the one
     * part guaranteed to differ, so they drag the score DOWN — the gate would
     * read lowest exactly where the defect is worst.
     */
    public function test_identifiers_are_removed_before_comparing(): void
    {
        $product = $this->product('HP LaserJet Pro M404dn', ['Print speed' => '38 ppm']);
        $product->forceFill([
            'sku' => 'OL-M404DN',
            'description_html' => '<p>HP LaserJet Pro M404dn OL-M404DN is a printer.</p>',
        ])->save();

        $prose = $this->gate->prose($product->refresh());

        $this->assertStringNotContainsString('m404dn', $prose);
        $this->assertStringNotContainsString('ol-m404dn', $prose);
        $this->assertStringContainsString('is a printer', $prose);
    }

    /**
     * Rewriting a flagged description clears the flag without anybody having
     * to remember to unflag it.
     */
    public function test_rewriting_clears_the_flag(): void
    {
        $brand = Brand::factory()->create(['name' => 'HP']);
        $category = Category::factory()->root('toner')->create();

        $boilerplate = implode(' ', array_fill(0, 60, 'cartridge'));

        $first = $this->product('HP 128A Black', ['Colour' => 'Black'], $brand, $category);
        $first->forceFill(['description_html' => '<p>'.$boilerplate.'</p>'])->save();

        $second = $this->product('HP 128A Cyan', ['Colour' => 'Cyan'], $brand, $category);
        $second->forceFill(['description_html' => '<p>'.$boilerplate.'</p>'])->save();

        $this->assertNotNull($this->gate->check($second->refresh()));

        $second->refresh()->forceFill([
            'description_html' => '<p>The cyan cartridge yields 1,600 pages and suits low-volume colour work in a shared office.</p>',
        ])->save();

        $this->assertNull($this->gate->check($second->refresh()));
        $this->assertNull($second->refresh()->description_flagged_at);
    }

    /**
     * Re-running the gate must not invalidate an approval somebody already
     * gave — otherwise a nightly batch would silently un-index every variant
     * a reviewer had already accepted.
     */
    public function test_rechecking_does_not_reset_an_existing_flag(): void
    {
        $brand = Brand::factory()->create(['name' => 'HP']);
        $category = Category::factory()->root('toner')->create();

        $boilerplate = implode(' ', array_fill(0, 60, 'cartridge'));

        $first = $this->product('HP 128A Black', ['Colour' => 'Black'], $brand, $category);
        $first->forceFill(['description_html' => '<p>'.$boilerplate.'</p>'])->save();

        $second = $this->product('HP 128A Cyan', ['Colour' => 'Cyan'], $brand, $category);
        $second->forceFill(['description_html' => '<p>'.$boilerplate.'</p>'])->save();

        $this->gate->check($second->refresh());
        $flaggedAt = $second->refresh()->description_flagged_at;

        $this->gate->check($second->refresh());

        $this->assertEquals($flaggedAt, $second->refresh()->description_flagged_at);
    }
}
