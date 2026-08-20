<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLogEntry;
use App\Models\Brand;
use App\Models\Product;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Section 11: authorisation is "policy-enforced, not UI-hidden".
 *
 * These tests call the Gate directly rather than driving the interface. A
 * hidden button proves nothing — the question is what happens when someone
 * types the URL, and that is decided by the policy.
 */
final class AdminAuthorisationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::table('audit_log')->delete();
        DB::table('products')->delete();
        DB::table('brands')->delete();
        DB::table('users')->delete();
    }

    protected function tearDown(): void
    {
        DB::table('audit_log')->delete();
        DB::table('products')->delete();
        DB::table('brands')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    private function userWith(Role $role): User
    {
        return User::factory()->create([
            'role' => $role->value,
            'is_active' => true,
        ]);
    }

    // -----------------------------------------------------------------
    // Catalogue
    // -----------------------------------------------------------------

    /** @return array<string, array{Role, bool}> */
    public static function catalogueCreators(): array
    {
        return [
            'super admin creates' => [Role::SuperAdmin, true],
            'catalogue manager creates' => [Role::CatalogueManager, true],
            'content editor cannot create' => [Role::ContentEditor, false],
            'sales cannot create' => [Role::Sales, false],
            'viewer cannot create' => [Role::Viewer, false],
        ];
    }

    #[DataProvider('catalogueCreators')]
    public function test_only_catalogue_roles_may_create_products(Role $role, bool $allowed): void
    {
        $user = $this->userWith($role);

        $this->assertSame($allowed, $user->can('create', Product::class));
    }

    /**
     * A content editor revises copy and SEO fields on existing records but
     * must not create or delete catalogue structure.
     */
    public function test_a_content_editor_may_update_but_not_create_or_delete(): void
    {
        $user = $this->userWith(Role::ContentEditor);
        $product = Product::factory()->create();

        $this->assertTrue($user->can('update', $product));
        $this->assertFalse($user->can('create', Product::class));
        $this->assertFalse($user->can('delete', $product));
    }

    /** Sales works quote leads and has no reason to reach the catalogue at all. */
    public function test_sales_cannot_reach_the_catalogue(): void
    {
        $user = $this->userWith(Role::Sales);
        $product = Product::factory()->create();

        $this->assertFalse($user->can('viewAny', Product::class));
        $this->assertFalse($user->can('view', $product));
        $this->assertFalse($user->can('update', $product));
    }

    public function test_a_viewer_may_read_but_never_write(): void
    {
        $user = $this->userWith(Role::Viewer);
        $product = Product::factory()->create();

        $this->assertTrue($user->can('viewAny', Product::class));
        $this->assertTrue($user->can('view', $product));
        $this->assertFalse($user->can('create', Product::class));
        $this->assertFalse($user->can('update', $product));
        $this->assertFalse($user->can('delete', $product));
    }

    /**
     * Soft deletion is reversible and safe to delegate. Force deletion
     * destroys the record and its audit context.
     */
    public function test_only_a_super_admin_may_force_delete(): void
    {
        $product = Product::factory()->create();

        $this->assertTrue($this->userWith(Role::SuperAdmin)->can('forceDelete', $product));
        $this->assertFalse($this->userWith(Role::CatalogueManager)->can('forceDelete', $product));
    }

    /** Publishing is what makes a page indexable, so it is narrower than editing. */
    public function test_publishing_is_narrower_than_editing(): void
    {
        $product = Product::factory()->create();
        $editor = $this->userWith(Role::ContentEditor);

        $this->assertTrue($editor->can('update', $product));
        $this->assertFalse($editor->can('publish', $product));
    }

    // -----------------------------------------------------------------
    // Users and the audit log
    // -----------------------------------------------------------------

    public function test_only_a_super_admin_may_administer_users(): void
    {
        foreach ([Role::CatalogueManager, Role::Sales, Role::ContentEditor, Role::Viewer] as $role) {
            $this->assertFalse(
                $this->userWith($role)->can('create', User::class),
                "{$role->value} must not create users",
            );
        }

        $this->assertTrue($this->userWith(Role::SuperAdmin)->can('create', User::class));
    }

    /** Locking the last super admin out is recoverable only from a console. */
    public function test_a_super_admin_cannot_delete_themselves(): void
    {
        $user = $this->userWith(Role::SuperAdmin);
        $other = $this->userWith(Role::SuperAdmin);

        $this->assertFalse($user->can('delete', $user));
        $this->assertTrue($user->can('delete', $other));
    }

    public function test_the_audit_log_is_readable_only_by_super_admins(): void
    {
        $this->assertTrue($this->userWith(Role::SuperAdmin)->can('viewAny', AuditLogEntry::class));

        foreach ([Role::CatalogueManager, Role::Sales, Role::ContentEditor, Role::Viewer] as $role) {
            $this->assertFalse($this->userWith($role)->can('viewAny', AuditLogEntry::class));
        }
    }

    // -----------------------------------------------------------------
    // Audit log behaviour
    // -----------------------------------------------------------------

    public function test_creating_a_record_writes_an_audit_entry(): void
    {
        $brand = Brand::factory()->create(['name' => 'Canon']);

        $entry = AuditLogEntry::query()
            ->where('auditable_type', Brand::class)
            ->where('auditable_id', $brand->id)
            ->where('event', 'created')
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame('Canon', $entry->auditable_label);
    }

    /**
     * Only what changed. Recording whole rows makes the log enormous and
     * buries the one field that moved — which is the field anyone reading an
     * audit log is looking for.
     */
    public function test_an_update_records_only_the_changed_fields(): void
    {
        $brand = Brand::factory()->create(['name' => 'Before']);
        AuditLogEntry::query()->delete();

        $brand->update(['name' => 'After']);

        $entry = AuditLogEntry::query()->where('event', 'updated')->firstOrFail();

        $this->assertSame(['name'], array_keys($entry->after));
        $this->assertSame('Before', $entry->before['name']);
        $this->assertSame('After', $entry->after['name']);
    }

    public function test_secrets_are_never_written_to_the_audit_log(): void
    {
        User::factory()->create(['password' => 'a-real-secret-value']);

        $entry = AuditLogEntry::query()->where('auditable_type', User::class)->firstOrFail();

        $this->assertArrayNotHasKey('password', $entry->after);
        $this->assertArrayNotHasKey('remember_token', $entry->after);
        $this->assertStringNotContainsString('a-real-secret-value', json_encode($entry->after));
    }

    /** An audit log that can be edited is not evidence of anything. */
    public function test_audit_entries_cannot_be_modified(): void
    {
        Brand::factory()->create();
        $entry = AuditLogEntry::query()->firstOrFail();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/append-only/');

        $entry->update(['event' => 'tampered']);
    }

    public function test_audit_entries_cannot_be_deleted(): void
    {
        Brand::factory()->create();
        $entry = AuditLogEntry::query()->firstOrFail();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/append-only/');

        $entry->delete();
    }

    /** The trail must survive the actor being removed. */
    public function test_the_actor_label_survives_user_deletion(): void
    {
        $user = $this->userWith(Role::CatalogueManager);
        $this->actingAs($user);

        Brand::factory()->create(['name' => 'Traced']);

        $entry = AuditLogEntry::query()
            ->where('auditable_type', Brand::class)
            ->where('event', 'created')
            ->firstOrFail();

        $this->assertSame($user->email, $entry->user_label);

        $user->delete();

        $this->assertSame($user->email, $entry->fresh()->user_label);
        $this->assertNull($entry->fresh()->user_id);
    }

    // -----------------------------------------------------------------
    // Panel access
    // -----------------------------------------------------------------

    public function test_a_deactivated_user_cannot_reach_the_panel(): void
    {
        $user = User::factory()->create(['role' => Role::CatalogueManager->value, 'is_active' => false]);

        $panel = Filament::getPanel('admin');

        $this->assertFalse($user->canAccessPanel($panel));
    }

    public function test_an_active_user_can_reach_the_panel(): void
    {
        $user = $this->userWith(Role::Viewer);

        $panel = Filament::getPanel('admin');

        $this->assertTrue($user->canAccessPanel($panel));
    }
}
