<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Models\Category;
use App\Models\Media;
use App\Models\Product;
use App\Models\User;
use App\Support\PlaceholderMedia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The products table's "Set status" bulk action, driven through the real
 * Filament page - the path an admin uses to publish drafts in bulk.
 */
final class ProductBulkStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['role' => Role::CatalogueManager->value, 'is_active' => true]));
    }

    public function test_set_status_active_publishes_selected_drafts(): void
    {
        $drafts = Product::factory()->count(3)->create(['status' => 'draft', 'published_at' => null]);

        Livewire::test(ListProducts::class)
            ->callTableBulkAction('setStatus', $drafts, data: ['status' => 'active'])
            ->assertHasNoTableBulkActionErrors();

        foreach ($drafts as $draft) {
            $draft->refresh();
            $this->assertSame('active', $draft->status);
            $this->assertNotNull($draft->published_at);
        }
    }

    public function test_no_category_filter(): void
    {
        $category = Category::factory()->create();
        $placed = Product::factory()->create(['primary_category_id' => $category->id]);
        $orphan = Product::factory()->create(['primary_category_id' => null]);

        Livewire::test(ListProducts::class)
            ->filterTable('no_category')
            ->assertCanSeeTableRecords([$orphan])
            ->assertCanNotSeeTableRecords([$placed]);
    }

    public function test_logo_as_image_filter(): void
    {
        $logo = $this->media('logo');
        $photo = $this->media('photo');

        $withLogo = Product::factory()->count(20)->create();
        foreach ($withLogo as $product) {
            $product->media()->attach($logo->id, ['role' => 'primary', 'sort_order' => 0]);
        }
        $withPhoto = Product::factory()->create();
        $withPhoto->media()->attach($photo->id, ['role' => 'primary', 'sort_order' => 0]);

        Cache::forget(PlaceholderMedia::CACHE_KEY);

        Livewire::test(ListProducts::class)
            ->filterTable('logo_as_image')
            ->assertCanSeeTableRecords($withLogo->take(5))
            ->assertCanNotSeeTableRecords([$withPhoto]);
    }

    private function media(string $seed): Media
    {
        return Media::create([
            'uuid' => (string) Str::uuid(),
            'path' => "test/{$seed}.jpg",
            'mime' => 'image/jpeg',
            'bytes' => 1000,
            'checksum_sha256' => hash('sha256', $seed),
        ]);
    }
}
