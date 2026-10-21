<?php

declare(strict_types=1);

namespace App\Filament\Resources\HomepageProductPicks\Pages;

use App\Filament\Resources\HomepageProductPicks\HomepageProductPickResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListHomepageProductPicks extends ListRecords
{
    protected static string $resource = HomepageProductPickResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
