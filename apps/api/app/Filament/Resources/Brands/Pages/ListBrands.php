<?php

namespace App\Filament\Resources\Brands\Pages;

use App\Filament\Resources\Brands\BrandResource;
use App\Jobs\GenerateBrandLogo;
use App\Models\Brand;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListBrands extends ListRecords
{
    protected static string $resource = BrandResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generateLogos')
                ->label('Generate missing logos')
                ->icon('heroicon-o-sparkles')
                ->requiresConfirmation()
                ->modalDescription('Every brand without a logo gets one in the background — a real Icecat logo where available, a generated monogram otherwise. Brands that already have a logo are left alone.')
                ->action(function (): void {
                    $brands = Brand::query()->whereNull('logo_media_id')->pluck('id');

                    foreach ($brands as $id) {
                        GenerateBrandLogo::dispatch($id);
                    }

                    Notification::make()
                        ->title($brands->isEmpty() ? 'Every brand already has a logo' : 'Generating '.$brands->count().' logo(s)')
                        ->body($brands->isEmpty() ? null : 'They are building in the background and will appear shortly.')
                        ->success()
                        ->send();
                }),
            CreateAction::make(),
        ];
    }
}
