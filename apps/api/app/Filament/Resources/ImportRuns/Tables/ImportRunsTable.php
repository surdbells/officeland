<?php

declare(strict_types=1);

namespace App\Filament\Resources\ImportRuns\Tables;

use App\Filament\Resources\ImportRuns\ImportRunResource;
use App\Icecat\ImportLauncher;
use App\Models\ImportRun;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class ImportRunsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Live progress without a refresh: the row updates while the import
            // runs in the background.
            ->poll('5s')
            ->columns([
                TextColumn::make('label')->label('Import')->searchable()->wrap(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'completed' => 'success',
                        'failed' => 'danger',
                        'cancelled' => 'warning',
                        'running' => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('progress')
                    ->label('Progress')
                    ->state(fn (ImportRun $record) => ImportRunResource::progressBar($record))
                    ->html(),
                TextColumn::make('created')->label('Created')->numeric()->color('success'),
                TextColumn::make('skipped')->label('Skipped')->numeric()->toggleable(),
                TextColumn::make('failed')->label('Failed')->numeric()->color('danger')->toggleable(),
                TextColumn::make('startedBy.name')->label('By')->placeholder('—')->toggleable(),
                TextColumn::make('created_at')->label('Started')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'preparing' => 'Preparing',
                    'running' => 'Running',
                    'completed' => 'Completed',
                    'cancelled' => 'Cancelled',
                    'failed' => 'Failed',
                ]),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('cancel')
                    ->label('Cancel')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Stop this import? Products already created are kept; the remaining ones are not imported.')
                    ->visible(fn (ImportRun $record): bool => $record->isActive())
                    ->action(function (ImportRun $record): void {
                        app(ImportLauncher::class)->cancel($record);
                        Notification::make()->title('Import cancelling…')->warning()->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
