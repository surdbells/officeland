<?php

declare(strict_types=1);

namespace App\Filament\Resources\HomepageCategoryPicks\Pages;

use App\Filament\Resources\HomepageCategoryPicks\HomepageCategoryPickResource;
use App\Filament\Support\ImageUploads;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditHomepageCategoryPick extends EditRecord
{
    protected static string $resource = HomepageCategoryPickResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /** @param array<string, mixed> $data */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return ImageUploads::mutate($data, altFallback: (string) ($data['label'] ?? 'Category tile'));
    }
}
