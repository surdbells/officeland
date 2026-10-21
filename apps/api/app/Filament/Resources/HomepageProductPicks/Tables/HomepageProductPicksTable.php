<?php

declare(strict_types=1);

namespace App\Filament\Resources\HomepageProductPicks\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

final class HomepageProductPicksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('product.brand'))
            ->columns([
                TextColumn::make('product.name')->label('Product')->searchable()->limit(60)->wrap(),
                TextColumn::make('product.brand.name')->label('Brand')->placeholder('—')->toggleable(),
                TextColumn::make('product.status')->label('Status')->badge(),
                IconColumn::make('is_active')->label('Active')->boolean(),
                TextColumn::make('sort_order')->label('Position')->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Active'),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ])
            ->defaultSort('sort_order');
    }
}
