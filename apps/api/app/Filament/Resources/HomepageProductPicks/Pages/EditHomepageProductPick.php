<?php

declare(strict_types=1);

namespace App\Filament\Resources\HomepageProductPicks\Pages;

use App\Filament\Resources\HomepageProductPicks\HomepageProductPickResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditHomepageProductPick extends EditRecord
{
    protected static string $resource = HomepageProductPickResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
