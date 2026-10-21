<?php

declare(strict_types=1);

namespace App\Filament\Resources\AuditLog\Pages;

use App\Filament\Resources\AuditLog\AuditLogResource;
use Filament\Resources\Pages\ListRecords;

class ListAuditLogEntries extends ListRecords
{
    protected static string $resource = AuditLogResource::class;

    // Read-only: no create action. The audit log is written by the system, not
    // by hand (the model refuses writes, the policy refuses create).
}
