<?php

declare(strict_types=1);

namespace App\Filament\Resources\NotFoundLog\Pages;

use App\Filament\Resources\NotFoundLog\NotFoundLogResource;
use Filament\Resources\Pages\ListRecords;

final class ListNotFoundLog extends ListRecords
{
    protected static string $resource = NotFoundLogResource::class;
}
