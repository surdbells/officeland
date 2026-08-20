<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Import\ImportReport;
use App\Import\ProductCsvImporter;
use App\Models\Product;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * CSV import with a dry-run preview (MASTER BUILD PROMPT section 11).
 *
 * Two deliberate steps. Uploading previews; nothing is written until the
 * editor has seen the diff and pressed Apply. A single-step import of a
 * 5,000-row file is a decision made blind, and the row that matters is always
 * the one nobody looked at.
 */
final class ImportProducts extends Page
{
    protected string $view = 'filament.pages.import-products';

    protected static ?string $title = 'Import products';

    protected static ?string $navigationLabel = 'Import products';

    protected static ?int $navigationSort = 90;

    /** @var array<string, mixed> */
    public array $data = [];

    public ?ImportReport $report = null;

    public ?string $uploadedPath = null;

    public static function canAccess(): bool
    {
        // Importing is a bulk catalogue write, so it follows the catalogue
        // create permission rather than being open to anyone who can log in.
        return auth()->user()?->can('create', Product::class) ?? false;
    }

    public function mount(): void
    {
        $this->getSchema('form')?->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Upload a CSV')
                    ->description('Nothing is saved until you review the preview and press Apply.')
                    ->schema([
                        FileUpload::make('file')
                            ->label('CSV file')
                            ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv'])
                            ->disk('local')
                            ->directory('imports')
                            ->required(),
                    ]),
            ])
            ->statePath('data');
    }

    public function preview(): void
    {
        $path = $this->resolveUpload();

        if ($path === null) {
            return;
        }

        $this->uploadedPath = $path;
        $this->report = app(ProductCsvImporter::class)->preview($path);

        Notification::make()
            ->title($this->report->summary())
            ->status($this->report->hasErrors() ? 'warning' : 'success')
            ->send();
    }

    public function apply(): void
    {
        if ($this->uploadedPath === null) {
            Notification::make()->title('Preview the file first.')->warning()->send();

            return;
        }

        $this->report = app(ProductCsvImporter::class)->apply($this->uploadedPath);

        Notification::make()
            ->title($this->report->summary())
            ->status($this->report->hasErrors() ? 'warning' : 'success')
            ->send();
    }

    private function resolveUpload(): ?string
    {
        $file = $this->data['file'] ?? null;

        if (is_array($file)) {
            $file = reset($file);
        }

        if (! is_string($file) || $file === '') {
            Notification::make()->title('Choose a file first.')->warning()->send();

            return null;
        }

        $path = Storage::disk('local')->path($file);

        if (! is_file($path)) {
            Notification::make()->title('The uploaded file could not be found.')->danger()->send();

            return null;
        }

        return $path;
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('preview')
                ->label('Preview changes')
                ->action('preview')
                ->color('gray'),

            Action::make('apply')
                ->label('Apply import')
                ->action('apply')
                ->requiresConfirmation()
                ->modalHeading('Apply this import?')
                ->modalDescription(fn (): string => $this->report?->summary() ?? 'Preview the file first.')
                ->disabled(fn (): bool => $this->report === null || $this->report->hasErrors()),
        ];
    }

    public function getSubheading(): string
    {
        return 'Columns: '.implode(', ', ProductCsvImporter::COLUMNS)
            .'. Only "name" is required. An empty cell leaves the existing value alone.';
    }
}
