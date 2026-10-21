<?php

declare(strict_types=1);

namespace App\Filament\Resources\HomepageCategoryPicks\Pages;

use App\Filament\Resources\HomepageCategoryPicks\HomepageCategoryPickResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListHomepageCategoryPicks extends ListRecords
{
    protected static string $resource = HomepageCategoryPickResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
