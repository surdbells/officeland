<?php

declare(strict_types=1);

namespace App\Filament\Resources\Redirects\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class RedirectsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('from_path')->label('Old address')->searchable()->limit(60),
                TextColumn::make('to_path')->label('New address')->searchable()->limit(60)
                    ->placeholder('- gone -'),
                TextColumn::make('status_code')->label('Type')->badge()
                    ->color(fn (int $state): string => $state === 410 ? 'danger' : 'success'),
                TextColumn::make('source')->badge()->toggleable(),
                TextColumn::make('hits')->numeric()->sortable()
                    ->description('Times followed'),
                TextColumn::make('last_hit_at')->label('Last used')->dateTime('d M Y')->sortable()
                    ->placeholder('never'),
            ])
            ->filters([
                SelectFilter::make('status_code')->label('Type')->options([
                    301 => '301', 302 => '302', 410 => '410',
                ]),
                SelectFilter::make('source')->options([
                    'migration' => 'Migration',
                    'manual' => 'Manual',
                    'auto_slug_change' => 'Slug change',
                ]),
                /*
                 * Orphan detection (section 11). A redirect nobody has ever
                 * followed is either premature or pointing at the wrong place,
                 * and this list is the cheapest audit of the redirect map
                 * available.
                 */
                Filter::make('never_used')
                    ->label('Never followed')
                    ->query(fn (Builder $query): Builder => $query->where('hits', 0)),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])])
            ->defaultSort('hits', 'desc');
    }
}
