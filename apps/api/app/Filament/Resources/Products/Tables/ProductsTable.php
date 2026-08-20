<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Tables;

use App\Models\Product;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->limit(60)
                    ->description(fn (Product $record): ?string => $record->mpn),

                TextColumn::make('brand.name')->label('Brand')->searchable()->sortable()->toggleable(),

                TextColumn::make('primaryCategory.path')
                    ->label('Category')
                    ->toggleable()
                    ->limit(40),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'draft' => 'gray',
                        'archived' => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('availability')->badge()->toggleable()->sortable(),

                IconColumn::make('is_indexable')->label('Indexed')->boolean()->sortable(),

                TextColumn::make('updated_at')->label('Updated')->dateTime('d M Y H:i')->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'draft' => 'Draft', 'active' => 'Active', 'archived' => 'Archived',
                ]),
                SelectFilter::make('brand')->relationship('brand', 'name')->searchable()->preload(),
                TernaryFilter::make('is_indexable')->label('Indexable'),

                /*
                 * The thin-content queue (MASTER BUILD PROMPT section 14.7).
                 *
                 * This is the working view for the 15,000-description problem:
                 * everything live but too thin to index, so the highest-value
                 * ones can be found and fixed first.
                 */
                Filter::make('thin_content')
                    ->label('Thin content — needs a description')
                    ->query(fn (Builder $query): Builder => $query->whereRaw(
                        "array_length(regexp_split_to_array(trim(coalesce(regexp_replace(description_html, '<[^>]+>', ' ', 'g'), '')), '\\s+'), 1) < ?",
                        [Product::CONTENT_MINIMUM_WORDS]
                    )),

                /*
                 * Published but not indexable — usually an oversight, and
                 * every one of these is a page earning nothing.
                 */
                Filter::make('live_but_hidden')
                    ->label('Live but not indexable')
                    ->query(fn (Builder $query): Builder => $query
                        ->where('status', 'active')
                        ->where('is_indexable', false)),

                Filter::make('missing_mpn')
                    ->label('No manufacturer part number')
                    ->query(fn (Builder $query): Builder => $query->whereNull('mpn')),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ])
            ->defaultSort('updated_at', 'desc');
    }
}
