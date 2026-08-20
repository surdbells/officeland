<?php

namespace App\Filament\Resources\SearchSynonyms\Pages;

use App\Filament\Resources\SearchSynonyms\SearchSynonymResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSearchSynonym extends EditRecord
{
    protected static string $resource = SearchSynonymResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
