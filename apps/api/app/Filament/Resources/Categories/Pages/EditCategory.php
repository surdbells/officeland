<?php

namespace App\Filament\Resources\Categories\Pages;

use App\Filament\Resources\Categories\CategoryResource;
use App\Filament\Support\ImageUploads;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Cache;

class EditCategory extends EditRecord
{
    protected static string $resource = CategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('setHero')
                ->label('Set hero image')
                ->icon('heroicon-o-photo')
                ->schema([
                    FileUpload::make('file')->label('Hero image')->image()->required()
                        ->disk('local')->directory('tmp-admin-uploads'),
                    TextInput::make('alt_text')->label('Alt text')->required()->maxLength(255),
                ])
                ->action(function (array $data): void {
                    // Ingested through the pipeline, then set as the hero. The
                    // storefront navigation cache is dropped so it shows at once.
                    $media = ImageUploads::ingest($data);
                    $this->getRecord()->update(['hero_media_id' => $media->id]);
                    Cache::forget('api:navigation:v1');
                }),
            Action::make('setCard')
                ->label('Set card image')
                ->icon('heroicon-o-squares-2x2')
                ->schema([
                    FileUpload::make('file')->label('Card image (square)')->image()->required()
                        ->imageCropAspectRatio('1:1')
                        ->helperText('A 1:1 crop for the homepage department cards and the megamenu.')
                        ->disk('local')->directory('tmp-admin-uploads'),
                    TextInput::make('alt_text')->label('Alt text')->required()->maxLength(255),
                ])
                ->action(function (array $data): void {
                    $media = ImageUploads::ingest($data);
                    $this->getRecord()->update(['card_media_id' => $media->id]);
                    Cache::forget('api:navigation:v1');
                }),
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
