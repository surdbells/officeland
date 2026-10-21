<?php

declare(strict_types=1);

namespace App\Filament\Resources\HomepageCategoryPicks\Pages;

use App\Filament\Resources\HomepageCategoryPicks\HomepageCategoryPickResource;
use App\Filament\Support\ImageUploads;
use Filament\Resources\Pages\CreateRecord;

class CreateHomepageCategoryPick extends CreateRecord
{
    protected static string $resource = HomepageCategoryPickResource::class;

    /** @param array<string, mixed> $data */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return ImageUploads::mutate($data, altFallback: (string) ($data['label'] ?? 'Category tile'));
    }
}
