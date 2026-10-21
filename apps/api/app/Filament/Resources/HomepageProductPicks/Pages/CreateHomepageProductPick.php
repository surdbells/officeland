<?php

declare(strict_types=1);

namespace App\Filament\Resources\HomepageProductPicks\Pages;

use App\Filament\Resources\HomepageProductPicks\HomepageProductPickResource;
use Filament\Resources\Pages\CreateRecord;

class CreateHomepageProductPick extends CreateRecord
{
    protected static string $resource = HomepageProductPickResource::class;
}
