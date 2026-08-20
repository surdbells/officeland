<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AuditLogEntry;
use App\Models\User;

/**
 * The audit log is readable by super admins and nobody else, and writable by
 * no one (MASTER BUILD PROMPT section 18).
 *
 * It records who did what, including actions by people who can see it. An
 * audit log that its subjects can read selectively, edit or delete is not
 * evidence — hence every mutation returns false, backed by the model refusing
 * update and delete outright.
 */
final class AuditLogEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function view(User $user, AuditLogEntry $entry): bool
    {
        return $user->isSuperAdmin();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, AuditLogEntry $entry): bool
    {
        return false;
    }

    public function delete(User $user, AuditLogEntry $entry): bool
    {
        return false;
    }
}
