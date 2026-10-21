<?php

declare(strict_types=1);

namespace App\Filament\Resources\AuditLog;

use App\Filament\Resources\AuditLog\Pages\ListAuditLogEntries;
use App\Filament\Resources\AuditLog\Tables\AuditLogTable;
use App\Models\AuditLogEntry;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * The mutation audit log - a read-only window onto who changed what.
 *
 * Append-only and super-admin-only, both enforced below the UI: the model
 * refuses update/delete, and AuditLogEntryPolicy returns false for every
 * mutation and true for viewing only to super admins. This resource therefore
 * has no create, edit or delete - only a list and a per-entry view.
 */
class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLogEntry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Audit log';

    protected static ?string $slug = 'audit-log';

    protected static ?string $recordTitleAttribute = 'auditable_label';

    public static function table(Table $table): Table
    {
        return AuditLogTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->latest('created_at');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAuditLogEntries::route('/'),
        ];
    }
}
