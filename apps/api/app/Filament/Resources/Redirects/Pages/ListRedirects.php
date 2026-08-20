<?php

declare(strict_types=1);

namespace App\Filament\Resources\Redirects\Pages;

use App\Filament\Resources\Redirects\RedirectResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListRedirects extends ListRecords
{
    protected static string $resource = RedirectResource::class;

    /** @return array<int, mixed> */
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
