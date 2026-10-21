<?php

declare(strict_types=1);

namespace App\Filament\Resources\HeroSlides\Tables;

use App\Models\HeroSlide;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

final class HeroSlidesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('media.derivatives'))
            ->columns([
                ImageColumn::make('preview')
                    ->label('')
                    ->getStateUsing(fn (HeroSlide $record): ?string => $record->media?->previewUrl())
                    ->height(48),
                TextColumn::make('headline')->searchable()->limit(50)->wrap(),
                IconColumn::make('is_active')->label('Active')->boolean(),
                TextColumn::make('sort_order')->label('Position')->sortable(),
                TextColumn::make('updated_at')->label('Updated')->dateTime('d M Y H:i')->sortable()->toggleable(),
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
