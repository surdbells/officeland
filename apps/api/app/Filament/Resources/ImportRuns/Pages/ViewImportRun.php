<?php

declare(strict_types=1);

namespace App\Filament\Resources\ImportRuns\Pages;

use App\Filament\Resources\ImportRuns\ImportRunResource;
use App\Icecat\ImportLauncher;
use App\Models\ImportRun;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewImportRun extends ViewRecord
{
    protected static string $resource = ImportRunResource::class;

    /** Live-refresh while the import is still running. */
    protected function getPollingInterval(): ?string
    {
        /** @var ImportRun $record */
        $record = $this->getRecord();

        return $record->isActive() ? '5s' : null;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('cancel')
                ->label('Cancel import')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription('Stop this import? Products already created are kept; the remaining ones are not imported.')
                ->visible(fn (ImportRun $record): bool => $record->isActive())
                ->action(function (ImportRun $record): void {
                    app(ImportLauncher::class)->cancel($record);
                    Notification::make()->title('Import cancelling…')->warning()->send();
                }),
        ];
    }
}
