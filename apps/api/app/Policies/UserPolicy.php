<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * User administration is super-admin only: creating accounts and assigning
 * roles is the ability to grant every other ability.
 */
final class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function view(User $user, User $model): bool
    {
        return $user->isSuperAdmin() || $user->is($model);
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function update(User $user, User $model): bool
    {
        return $user->isSuperAdmin();
    }

    /**
     * Nobody deletes themselves. Locking the last super admin out of the panel
     * is a recoverable-only-by-console mistake, and deleting an actor damages
     * the audit trail — deactivate instead.
     */
    public function delete(User $user, User $model): bool
    {
        return $user->isSuperAdmin() && ! $user->is($model);
    }
}
