<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        // The policy forbids deleting yourself and deleting the last actor from
        // the audit trail; DeleteAction honours UserPolicy::delete, so it is
        // hidden on your own row.
        return [
            DeleteAction::make(),
        ];
    }
}
