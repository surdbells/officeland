<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
