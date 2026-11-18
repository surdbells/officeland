<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Pages\ContentReadiness;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The content-readiness queue must render with the products bulk publishing
 * leaves behind: active, not indexable, possibly no category, image or specs.
 */
final class ContentReadinessPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_renders_with_bulk_published_thin_products(): void
    {
        $this->actingAs(User::factory()->create(['role' => Role::CatalogueManager->value, 'is_active' => true]));

        Product::factory()->create([
            'status' => 'active',
            'is_indexable' => false,
            'primary_category_id' => null,
            'description_html' => null,
            'short_description' => null,
        ]);

        Livewire::test(ContentReadiness::class)->assertOk();
    }
}
