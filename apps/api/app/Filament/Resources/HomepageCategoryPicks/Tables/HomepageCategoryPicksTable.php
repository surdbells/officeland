<?php

declare(strict_types=1);

namespace App\Filament\Resources\HomepageCategoryPicks\Tables;

use App\Models\HomepageCategoryPick;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

final class HomepageCategoryPicksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['category', 'media.derivatives', 'category.heroMedia.derivatives']))
            ->columns([
                ImageColumn::make('preview')
                    ->label('')
                    ->getStateUsing(fn (HomepageCategoryPick $record): ?string => ($record->media ?? $record->category?->heroMedia)?->previewUrl())
                    ->height(40),
                TextColumn::make('label')
                    ->label('Tile')
                    ->getStateUsing(fn (HomepageCategoryPick $record): string => $record->label ?? $record->category?->name ?? '—'),
                TextColumn::make('category.path')->label('Category')->searchable()->sortable(),
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
