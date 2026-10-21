<?php

declare(strict_types=1);

namespace App\Filament\Resources\Brands\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class BrandsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Count products LIVE. product_count_cached is a storefront cache
            // that only the legacy importer maintains and that counts active
            // products only - so brands whose catalogue is still draft (e.g. a
            // fresh Icecat import) read as 0 there. The admin wants the true
            // total it is managing, drafts included.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount('products'))
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('slug')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('products_count')->label('Products')->numeric()->sortable(),
                IconColumn::make('is_featured')->label('Featured')->boolean(),
                IconColumn::make('is_indexable')->label('Indexable')->boolean(),
                TextColumn::make('updated_at')->label('Updated')->dateTime('d M Y H:i')->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_featured')->label('Featured'),
                // Surfacing this is the point of section 14.8's indexability
                // audit: every noindex page, and why.
                TernaryFilter::make('is_indexable')->label('Indexable'),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ])
            ->defaultSort('name');
    }
}
