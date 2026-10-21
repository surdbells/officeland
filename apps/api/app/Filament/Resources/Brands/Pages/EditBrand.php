<?php

namespace App\Filament\Resources\Brands\Pages;

use App\Filament\Resources\Brands\BrandResource;
use App\Filament\Support\ImageUploads;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\EditRecord;

class EditBrand extends EditRecord
{
    protected static string $resource = BrandResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('setLogo')
                ->label('Set logo')
                ->icon('heroicon-o-photo')
                ->schema([
                    FileUpload::make('file')->label('Logo')->image()->required()
                        ->disk('local')->directory('tmp-admin-uploads'),
                    TextInput::make('alt_text')->label('Alt text')->required()->maxLength(255),
                ])
                ->action(function (array $data): void {
                    $media = ImageUploads::ingest($data);
                    $this->getRecord()->update(['logo_media_id' => $media->id]);
                }),
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
