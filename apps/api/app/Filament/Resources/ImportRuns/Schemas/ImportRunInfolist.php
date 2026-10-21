<?php

declare(strict_types=1);

namespace App\Filament\Resources\ImportRuns\Schemas;

use App\Filament\Resources\ImportRuns\ImportRunResource;
use App\Models\ImportRun;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

final class ImportRunInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Progress')->schema([
                TextEntry::make('progress')
                    ->hiddenLabel()
                    ->state(fn (ImportRun $record): HtmlString => ImportRunResource::progressBar($record))
                    ->html()
                    ->columnSpanFull(),

                TextEntry::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'completed' => 'success',
                        'failed' => 'danger',
                        'cancelled' => 'warning',
                        'running' => 'info',
                        default => 'gray',
                    }),
                TextEntry::make('created')->numeric()->color('success'),
                TextEntry::make('skipped')->numeric(),
                TextEntry::make('failed')->numeric()->color('danger'),
                TextEntry::make('total')->numeric(),
            ])->columns(5),

            Section::make('Details')->schema([
                TextEntry::make('label')->label('Import'),
                TextEntry::make('brands')
                    ->badge()
                    ->state(fn (ImportRun $record): array => $record->brands),
                TextEntry::make('since')
                    ->label('Since')
                    ->state(fn (ImportRun $record): string => (string) ($record->options['since'] ?? '') ?: 'All history'),
                TextEntry::make('catids')
                    ->label('Category filter')
                    ->state(fn (ImportRun $record): string => (string) ($record->options['catids'] ?? '') ?: 'All'),
                TextEntry::make('limit')
                    ->state(fn (ImportRun $record): string => ($record->options['limit'] ?? 0) ? (string) $record->options['limit'] : 'No limit'),
                TextEntry::make('max_images')
                    ->label('Images / product')
                    ->state(fn (ImportRun $record): string => (string) ($record->options['max_images'] ?? config('icecat.max_images', 3))),
                TextEntry::make('startedBy.name')->label('Started by')->placeholder('—'),
                TextEntry::make('started_at')->dateTime('d M Y H:i')->placeholder('—'),
                TextEntry::make('finished_at')->dateTime('d M Y H:i')->placeholder('—'),
                TextEntry::make('message')->placeholder('—')->columnSpanFull(),
            ])->columns(3),
        ]);
    }
}
