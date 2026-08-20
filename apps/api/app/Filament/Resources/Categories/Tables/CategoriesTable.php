<?php

declare(strict_types=1);

namespace App\Filament\Resources\Categories\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

final class CategoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                // Sorted and indented by path, which renders the tree in the
                // table without needing a separate tree widget: a materialized
                // path sorts into exactly the right order.
                TextColumn::make('path')
                    ->label('Category')
                    ->searchable()
                    ->formatStateUsing(function (string $state, $record): string {
                        $indent = str_repeat('　　', max(0, (int) $record->depth - 1));

                        return $indent.($record->depth > 1 ? '└ ' : '').$record->name;
                    })
                    ->description(fn ($record): string => $record->path),

                TextColumn::make('depth')->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('product_count_cached')->label('Products')->numeric()->sortable(),
                IconColumn::make('is_indexable')->label('Indexable')->boolean(),
                TextColumn::make('sort_order')->label('Position')->numeric()->sortable()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('depth')->options([1 => 'Departments', 2 => 'Subcategories', 3 => 'Leaf categories']),
                TernaryFilter::make('is_indexable')->label('Indexable'),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])])
            // Drag-and-drop reorder within a level (section 11).
            ->reorderable('sort_order')
            ->defaultSort('path')
            ->paginated([25, 50, 100, 'all']);
    }
}
