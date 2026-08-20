<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Authorisation for catalogue records — products, categories, brands,
 * attributes and media.
 *
 * Section 11: "Policy-enforced, not UI-hidden." A hidden button is a UI
 * preference that anyone can defeat by typing a URL; a policy is
 * authorisation. Filament consults these to decide what to render, so the two
 * agree — but the policy is what actually holds.
 *
 * The five roles map to job functions:
 *   super_admin        everything
 *   catalogue_manager  the catalogue
 *   content_editor     copy and SEO fields, but not catalogue structure
 *   sales              quote leads only; no catalogue access at all
 *   viewer             read-only
 */
class CataloguePolicy
{
    public function viewAny(User $user): bool
    {
        // Sales has no reason to browse the catalogue admin, and the
        // principle of least privilege says it should not.
        return ! $user->hasRole(Role::Sales);
    }

    public function view(User $user, Model $model): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->role->managesCatalogue();
    }

    public function update(User $user, Model $model): bool
    {
        // A content editor may revise copy and SEO fields on an existing
        // record but may not create or delete catalogue structure. Field-level
        // restriction is applied in the resource form.
        return $user->role->managesCatalogue() || $user->role->managesContent();
    }

    public function delete(User $user, Model $model): bool
    {
        return $user->role->managesCatalogue();
    }

    public function restore(User $user, Model $model): bool
    {
        return $user->role->managesCatalogue();
    }

    /**
     * Permanent deletion is super-admin only.
     *
     * Soft deletion is reversible and therefore safe to delegate. Force
     * deletion destroys the record, its audit trail context and any redirect
     * that pointed at it.
     */
    public function forceDelete(User $user, Model $model): bool
    {
        return $user->isSuperAdmin();
    }

    /**
     * Publishing is what makes a page indexable, so it is a narrower
     * permission than editing (section 14.7 — a product below the content
     * minimum must not reach the index by accident).
     */
    public function publish(User $user, Model $model): bool
    {
        return $user->role->managesCatalogue();
    }
}
