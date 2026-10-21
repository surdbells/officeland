<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\RelationManagers;

use App\Media\DerivativeGenerator;
use App\Media\MediaIngestor;
use App\Models\Media;
use Filament\Actions\Action;
use Filament\Actions\DetachAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;

/**
 * Manage a product's images from its edit page: upload (through the content-
 * addressed pipeline so they render), set roles and order, and detach.
 *
 * Uploads do NOT go to a plain disk - they are ingested by MediaIngestor
 * (dedup + metadata strip + content-address) and have derivatives generated,
 * exactly as the CSV and Icecat paths do, then attached to product_media with a
 * role and sort order. A raw Filament upload would store a file the storefront's
 * <picture> cannot build a srcset from.
 */
class ImagesRelationManager extends RelationManager
{
    protected static string $relationship = 'media';

    protected static ?string $title = 'Images';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('preview')
                    ->label('')
                    ->getStateUsing(fn (Media $record): string => $record->previewUrl())
                    ->height(56),
                TextColumn::make('pivot.role')->label('Role')->badge(),
                TextColumn::make('pivot.sort_order')->label('Order')->sortable(),
                TextColumn::make('alt_text')->label('Alt text')->limit(40)->placeholder('— none —'),
            ])
            ->headerActions([
                Action::make('upload')
                    ->label('Upload image')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->schema([
                        FileUpload::make('file')
                            ->label('Image')
                            ->image()
                            ->required()
                            ->disk('local')
                            ->directory('tmp-admin-uploads'),
                        TextInput::make('alt_text')
                            ->label('Alt text')
                            ->required()
                            ->helperText('Describes the image for screen readers and SEO.')
                            ->maxLength(255),
                    ])
                    ->action(fn (array $data) => $this->uploadImage($data)),
            ])
            ->recordActions([
                Action::make('details')
                    ->label('Edit')
                    ->icon('heroicon-o-pencil-square')
                    ->fillForm(fn (Media $record): array => [
                        'role' => $record->getAttribute('pivot')?->role ?? 'gallery',
                        'sort_order' => $record->getAttribute('pivot')?->sort_order ?? 0,
                        'alt_text' => $record->alt_text,
                    ])
                    ->schema([
                        Select::make('role')
                            ->options([
                                'primary' => 'Primary',
                                'gallery' => 'Gallery',
                                'lifestyle' => 'Lifestyle',
                                'spec_sheet' => 'Spec sheet',
                            ])
                            ->required(),
                        TextInput::make('sort_order')->numeric()->default(0),
                        TextInput::make('alt_text')->required()->maxLength(255),
                    ])
                    ->action(function (Media $record, array $data): void {
                        $this->getOwnerRecord()->media()->updateExistingPivot($record->id, [
                            'role' => $data['role'],
                            'sort_order' => (int) $data['sort_order'],
                        ]);
                        $record->update(['alt_text' => $data['alt_text']]);
                    }),

                Action::make('makePrimary')
                    ->label('Make primary')
                    ->icon('heroicon-o-star')
                    ->visible(fn (Media $record): bool => ($record->getAttribute('pivot')?->role) !== 'primary')
                    ->action(fn (Media $record) => $this->makePrimary($record)),

                DetachAction::make(),
            ]);
    }

    /** @param array<string, mixed> $data */
    private function uploadImage(array $data): void
    {
        $relative = (string) $data['file'];
        $path = Storage::disk('local')->path($relative);

        try {
            $media = app(MediaIngestor::class)->ingest($path, [
                'alt_text' => $data['alt_text'],
                'source' => 'manual',
            ]);

            app(DerivativeGenerator::class)->generate($media);

            $product = $this->getOwnerRecord();
            $hasImages = $product->media()->exists();
            $nextSort = (int) $product->media()->max('product_media.sort_order') + 1;

            $product->media()->syncWithoutDetaching([
                $media->id => [
                    'role' => $hasImages ? 'gallery' : 'primary',
                    'sort_order' => $nextSort,
                ],
            ]);
        } finally {
            Storage::disk('local')->delete($relative);
        }
    }

    private function makePrimary(Media $record): void
    {
        $product = $this->getOwnerRecord();

        foreach ($product->media as $media) {
            $product->media()->updateExistingPivot($media->id, [
                'role' => $media->id === $record->id ? 'primary' : 'gallery',
            ]);
        }
    }
}
