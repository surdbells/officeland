<?php

declare(strict_types=1);

namespace App\Filament\Resources\Media\Tables;

use App\Models\Media;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class MediaTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Eager-load derivatives so the thumbnail uses the smallest one
            // rather than the multi-megabyte original.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('derivatives'))
            ->columns([
                ImageColumn::make('preview')
                    ->label('')
                    ->getStateUsing(fn (Media $record): string => $record->previewUrl())
                    ->height(48),
                TextColumn::make('original_filename')->label('File')->searchable()->limit(40),
                TextColumn::make('alt_text')->label('Alt text')->limit(40)->toggleable()
                    ->placeholder('— none —'),
                TextColumn::make('dimensions')
                    ->getStateUsing(fn (Media $record): string => $record->width.'×'.$record->height),
                TextColumn::make('source')->badge()->sortable(),
                TextColumn::make('bytes')->label('Size')
                    ->formatStateUsing(fn ($state): string => number_format((int) $state / 1024).' KB')
                    ->sortable(),
                TextColumn::make('created_at')->label('Added')->dateTime('d M Y')->sortable(),
            ])
            ->filters([
                SelectFilter::make('source')->options([
                    'icecat' => 'Icecat',
                    'manual' => 'Manual',
                    'csv' => 'CSV',
                ]),
            ])
            ->recordActions([EditAction::make()])
            ->defaultSort('created_at', 'desc');
    }
}
