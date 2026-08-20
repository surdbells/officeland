<?php

declare(strict_types=1);

namespace App\Filament\Resources\SearchSynonyms\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

final class SearchSynonymsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('term')->label('Buyers type')->searchable()->sortable(),
                TextColumn::make('synonym')->label('Catalogue says')->searchable()->sortable(),
                TextColumn::make('type')->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'model_code' => 'success',
                        'typo' => 'warning',
                        default => 'gray',
                    }),
                IconColumn::make('bidirectional')->label('Both ways')->boolean(),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->filters([
                SelectFilter::make('type')->options([
                    'usage' => 'Everyday wording',
                    'model_code' => 'Model code',
                    'brand' => 'Brand variant',
                    'typo' => 'Misspelling',
                ]),
                TernaryFilter::make('is_active')->label('Active'),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])])
            ->defaultSort('term');
    }
}
