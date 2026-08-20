<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Resources\Categories\Pages\CreateCategory;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Phase 5 gate: "a non-technical user completes a product create/edit
 * unaided."
 *
 * These drive the actual Filament forms rather than the models, because the
 * gate is about the form. A form that saves only when you already know the
 * schema does not pass — which is why the generated scaffold, which asked the
 * editor for a UUID and a media id, was rewritten.
 */
final class AdminProductWorkflowTest extends TestCase
{
    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('audit_log')->delete();
        DB::table('product_category')->delete();
        DB::table('products')->delete();
        DB::table('categories')->delete();
        DB::table('brands')->delete();
        DB::table('users')->delete();

        $this->editor = User::factory()->create([
            'role' => Role::CatalogueManager->value,
            'is_active' => true,
        ]);

        $this->actingAs($this->editor);
    }

    protected function tearDown(): void
    {
        DB::table('audit_log')->delete();
        DB::table('product_category')->delete();
        DB::table('products')->delete();
        DB::table('categories')->delete();
        DB::table('brands')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    private function leafCategory(): Category
    {
        $department = Category::factory()->root('office-furniture')->create();
        $sub = Category::factory()->childOf($department, 'seating')->create();

        return Category::factory()->childOf($sub, 'executive-chairs')->create();
    }

    // -----------------------------------------------------------------
    // The gate
    // -----------------------------------------------------------------

    /**
     * The whole gate in one test: fill in what a merchandiser would know, and
     * nothing they would not, and get a saved product.
     */
    public function test_an_editor_creates_a_product_with_only_business_knowledge(): void
    {
        $category = $this->leafCategory();
        $brand = Brand::factory()->create(['name' => 'HP']);

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'name' => 'HP 128A Black LaserJet Toner Cartridge (CE320A)',
                'slug' => 'hp-128a-black-laserjet-toner-cartridge-ce320a',
                'brand_id' => $brand->id,
                'primary_category_id' => $category->id,
                'mpn' => 'CE320A',
                'availability' => 'in_stock',
                'pricing_mode' => 'quote_only',
                'min_order_qty' => 1,
                'qty_step' => 1,
                'status' => 'active',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $product = Product::query()->where('mpn', 'CE320A')->first();

        $this->assertNotNull($product);
        $this->assertSame('active', $product->status);
        $this->assertSame($brand->id, $product->brand_id);
        $this->assertSame($category->id, $product->primary_category_id);
    }

    /**
     * Nothing internal or derived may be required. If any of these were, an
     * editor could not complete the form without a developer.
     */
    public function test_no_internal_field_is_required_of_the_editor(): void
    {
        $category = $this->leafCategory();

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'name' => 'Mesh-Back Ergonomic Task Chair',
                'slug' => 'mesh-back-ergonomic-task-chair',
                'primary_category_id' => $category->id,
                'availability' => 'lead_time',
                'pricing_mode' => 'quote_only',
                'min_order_qty' => 1,
                'qty_step' => 1,
                'status' => 'draft',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $product = Product::query()->where('slug', 'mesh-back-ergonomic-task-chair')->firstOrFail();

        // Generated, not typed.
        $this->assertNotEmpty($product->uuid);
    }

    public function test_the_slug_is_generated_from_the_name(): void
    {
        Livewire::test(CreateProduct::class)
            ->fillForm(['name' => 'Executive Leather Office Chair'])
            ->assertFormSet(['slug' => 'executive-leather-office-chair']);
    }

    public function test_an_editor_edits_an_existing_product(): void
    {
        $product = Product::factory()->create([
            'name' => 'Original name',
            'primary_category_id' => $this->leafCategory()->id,
        ]);

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->fillForm(['name' => 'Corrected name'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Corrected name', $product->fresh()->name);
    }

    // -----------------------------------------------------------------
    // Validation the editor should actually hit
    // -----------------------------------------------------------------

    public function test_a_duplicate_slug_is_rejected_with_a_form_error(): void
    {
        Product::factory()->create(['slug' => 'already-taken']);
        $category = $this->leafCategory();

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'name' => 'Another product',
                'slug' => 'already-taken',
                'primary_category_id' => $category->id,
                'availability' => 'in_stock',
                'pricing_mode' => 'quote_only',
                'min_order_qty' => 1,
                'qty_step' => 1,
                'status' => 'draft',
            ])
            ->call('create')
            ->assertHasFormErrors(['slug']);
    }

    public function test_an_invalid_slug_shape_is_rejected(): void
    {
        Livewire::test(CreateProduct::class)
            ->fillForm(['name' => 'Test', 'slug' => 'Not A Valid Slug!'])
            ->call('create')
            ->assertHasFormErrors(['slug']);
    }

    public function test_a_product_requires_a_main_category(): void
    {
        Livewire::test(CreateProduct::class)
            ->fillForm(['name' => 'Uncategorised', 'slug' => 'uncategorised'])
            ->call('create')
            ->assertHasFormErrors(['primary_category_id']);
    }

    // -----------------------------------------------------------------
    // Category tree — path and depth are derived, never typed
    // -----------------------------------------------------------------

    public function test_a_top_level_category_derives_its_own_path(): void
    {
        Livewire::test(CreateCategory::class)
            ->fillForm(['name' => 'Office Furniture'])
            ->assertFormSet(['slug' => 'office-furniture', 'path' => '/office-furniture/', 'depth' => 1]);
    }

    public function test_a_child_category_derives_its_path_from_its_parent(): void
    {
        $parent = Category::factory()->root('office-furniture')->create();

        Livewire::test(CreateCategory::class)
            ->fillForm(['name' => 'Seating', 'parent_id' => $parent->id])
            ->assertFormSet(['path' => '/office-furniture/seating/', 'depth' => 2]);
    }

    public function test_a_category_saves_with_its_derived_path(): void
    {
        $parent = Category::factory()->root('it-products')->create();

        Livewire::test(CreateCategory::class)
            ->fillForm(['name' => 'Computers', 'parent_id' => $parent->id])
            ->call('create')
            ->assertHasNoFormErrors();

        $created = Category::query()->where('slug', 'computers')->firstOrFail();

        $this->assertSame('/it-products/computers/', $created->path);
        $this->assertSame(2, $created->depth);
    }

    // -----------------------------------------------------------------
    // Authorisation holds through the panel, not just in the policy
    // -----------------------------------------------------------------

    public function test_a_viewer_cannot_open_the_product_create_page(): void
    {
        $this->actingAs(User::factory()->create([
            'role' => Role::Viewer->value,
            'is_active' => true,
        ]));

        Livewire::test(CreateProduct::class)->assertForbidden();
    }

    public function test_editing_a_product_is_written_to_the_audit_log(): void
    {
        $product = Product::factory()->create([
            'name' => 'Before edit',
            'primary_category_id' => $this->leafCategory()->id,
        ]);
        DB::table('audit_log')->delete();

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->fillForm(['name' => 'After edit'])
            ->call('save')
            ->assertHasNoFormErrors();

        $entry = DB::table('audit_log')
            ->where('auditable_type', Product::class)
            ->where('event', 'updated')
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame($this->editor->email, $entry->user_label);
    }
}
