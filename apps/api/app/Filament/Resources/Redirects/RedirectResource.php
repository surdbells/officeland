<?php

declare(strict_types=1);

namespace App\Filament\Resources\Redirects;

use App\Filament\Resources\Redirects\Pages\ListRedirects;
use App\Filament\Resources\Redirects\Schemas\RedirectForm;
use App\Filament\Resources\Redirects\Tables\RedirectsTable;
use App\Models\Redirect;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Redirect manager (MASTER BUILD PROMPT section 11, "SEO tools").
 *
 * Survives D-001's removal of migration: slug changes auto-insert here
 * forever, and D-007 restores the legacy mapping of 5,063 product URLs and
 * 161 categories.
 */
final class RedirectResource extends Resource
{
    protected static ?string $model = Redirect::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'SEO';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return RedirectForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RedirectsTable::configure($table);
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        // Redirects are an SEO instrument, so both catalogue and content roles
        // need them — a slug change and a moved guide are the same problem.
        return $user instanceof User
            && ($user->role->managesCatalogue() || $user->role->managesContent());
    }

    /** @return array<string, mixed> */
    public static function getPages(): array
    {
        return ['index' => ListRedirects::route('/')];
    }
}
