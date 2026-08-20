<?php

namespace App\Filament\Resources\SearchSynonyms\Pages;

use App\Filament\Resources\SearchSynonyms\SearchSynonymResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSearchSynonyms extends ListRecords
{
    protected static string $resource = SearchSynonymResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
