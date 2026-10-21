<?php

declare(strict_types=1);

namespace App\Filament\Resources\Media\Pages;

use App\Filament\Resources\Media\MediaResource;
use Filament\Resources\Pages\EditRecord;

class EditMedia extends EditRecord
{
    protected static string $resource = MediaResource::class;

    // No delete here: media may be shared across products (deduplicated by
    // checksum) and storage is cleaned by the reap command. This page is for
    // fixing alt text and credit.
    protected function getHeaderActions(): array
    {
        return [];
    }
}
