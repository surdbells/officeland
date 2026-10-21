<?php

declare(strict_types=1);

namespace App\Filament\Resources\Media\Pages;

use App\Filament\Resources\Media\MediaResource;
use Filament\Resources\Pages\ListRecords;

class ListMedia extends ListRecords
{
    protected static string $resource = MediaResource::class;

    // No create action: media come from the ingestion pipeline, not a form.
    protected function getHeaderActions(): array
    {
        return [];
    }
}
