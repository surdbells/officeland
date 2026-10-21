<?php

declare(strict_types=1);

namespace App\Filament\Resources\ImportRuns\Pages;

use App\Filament\Resources\ImportRuns\ImportRunResource;
use App\Icecat\ImportLauncher;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Str;

class ListImportRuns extends ListRecords
{
    protected static string $resource = ImportRunResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('newImport')
                ->label('New import')
                ->icon('heroicon-o-plus')
                ->modalHeading('Start a product import')
                ->modalDescription('Imports run in the background. You can leave this page — progress is tracked here and keeps going.')
                ->modalSubmitActionLabel('Start import')
                ->schema([
                    Select::make('brands')
                        ->label('Brands')
                        ->multiple()
                        ->options(self::brandOptions())
                        ->required()
                        ->searchable()
                        ->helperText('Pick one or more brands. Select all to import the whole catalogue of supported brands.'),

                    TextInput::make('since')
                        ->label('Only products catalogued since')
                        ->placeholder('e.g. 2022')
                        ->helperText('A year (YYYY) or date. Leave blank to import a brand\'s full history — far more products, including long-obsolete ones.'),

                    TextInput::make('catids')
                        ->label('Category IDs (optional)')
                        ->helperText('Comma-separated Icecat category ids to restrict to. Leave blank for all categories.'),

                    TextInput::make('limit')
                        ->label('Limit')
                        ->numeric()
                        ->default(0)
                        ->helperText('Maximum products to import this run. 0 = no limit.'),

                    TextInput::make('max_images')
                        ->label('Images per product')
                        ->numeric()
                        ->default((int) config('icecat.max_images', 3))
                        ->minValue(1)
                        ->maxValue(10),
                ])
                ->action(function (array $data): void {
                    $run = app(ImportLauncher::class)->launch(
                        brands: $data['brands'],
                        options: [
                            'since' => (string) ($data['since'] ?? ''),
                            'catids' => (string) ($data['catids'] ?? ''),
                            'limit' => (int) ($data['limit'] ?? 0),
                            'max_images' => (int) ($data['max_images'] ?? config('icecat.max_images', 3)),
                        ],
                        userId: auth()->id(),
                    );

                    Notification::make()
                        ->title('Import started')
                        ->body('“'.$run->label.'” is preparing in the background. Watch its progress below.')
                        ->success()
                        ->send();
                }),
        ];
    }

    /** @return array<string, string> */
    private static function brandOptions(): array
    {
        $options = [];

        foreach (array_keys((array) config('icecat.suppliers')) as $key) {
            $options[$key] = Str::title(str_replace('-', ' ', (string) $key));
        }

        ksort($options);

        return $options;
    }
}
