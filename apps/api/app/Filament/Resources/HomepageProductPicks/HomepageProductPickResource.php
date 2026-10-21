<?php

declare(strict_types=1);

namespace App\Filament\Resources\HomepageProductPicks;

use App\Filament\Resources\HomepageProductPicks\Pages\CreateHomepageProductPick;
use App\Filament\Resources\HomepageProductPicks\Pages\EditHomepageProductPick;
use App\Filament\Resources\HomepageProductPicks\Pages\ListHomepageProductPicks;
use App\Filament\Resources\HomepageProductPicks\Schemas\HomepageProductPickForm;
use App\Filament\Resources\HomepageProductPicks\Tables\HomepageProductPicksTable;
use App\Models\HomepageProductPick;
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
 * The homepage "best sellers" band.
 *
 * A curated set of products, replacing a stand-in that was simply the first
 * page of the catalogue. When no picks are active the storefront falls back to
 * that stand-in, so the band is never empty.
 */
class HomepageProductPickResource extends Resource
{
    protected static ?string $model = HomepageProductPick::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedStar;

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    protected static ?int $navigationSort = 40;

    protected static ?string $navigationLabel = 'Best-sellers band';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->role->managesContent();
    }

    public static function form(Schema $schema): Schema
    {
        return HomepageProductPickForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return HomepageProductPicksTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListHomepageProductPicks::route('/'),
            'create' => CreateHomepageProductPick::route('/create'),
            'edit' => EditHomepageProductPick::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
