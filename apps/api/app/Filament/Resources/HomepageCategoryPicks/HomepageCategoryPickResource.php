<?php

declare(strict_types=1);

namespace App\Filament\Resources\HomepageCategoryPicks;

use App\Filament\Resources\HomepageCategoryPicks\Pages\CreateHomepageCategoryPick;
use App\Filament\Resources\HomepageCategoryPicks\Pages\EditHomepageCategoryPick;
use App\Filament\Resources\HomepageCategoryPicks\Pages\ListHomepageCategoryPicks;
use App\Filament\Resources\HomepageCategoryPicks\Schemas\HomepageCategoryPickForm;
use App\Filament\Resources\HomepageCategoryPicks\Tables\HomepageCategoryPicksTable;
use App\Models\HomepageCategoryPick;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

/**
 * The homepage "Shop by category" grid.
 *
 * A curated list of category tiles, replacing a list that used to be hardcoded
 * in the storefront. When no picks are active the storefront falls back to that
 * built-in list, so an empty grid here is a safe state.
 */
class HomepageCategoryPickResource extends Resource
{
    protected static ?string $model = HomepageCategoryPick::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    protected static ?int $navigationSort = 30;

    protected static ?string $navigationLabel = 'Shop-by-category tiles';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->role->managesContent();
    }

    public static function form(Schema $schema): Schema
    {
        return HomepageCategoryPickForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return HomepageCategoryPicksTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListHomepageCategoryPicks::route('/'),
            'create' => CreateHomepageCategoryPick::route('/create'),
            'edit' => EditHomepageCategoryPick::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
