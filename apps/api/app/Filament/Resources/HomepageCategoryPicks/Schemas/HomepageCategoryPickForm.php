<?php

declare(strict_types=1);

namespace App\Filament\Resources\HomepageCategoryPicks\Schemas;

use App\Models\HomepageCategoryPick;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

/**
 * Category-pick form.
 *
 * The tile's count comes from the category itself and its image defaults to the
 * category's hero, so the only required choice is which category. A label and a
 * dedicated tile image are optional overrides. The image, when uploaded, runs
 * through the content-addressed pipeline via the page's mutate hook.
 */
final class HomepageCategoryPickForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->schema([
                Select::make('category_id')
                    ->label('Category')
                    ->relationship('category', 'path')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->helperText('The tile links here, and shows this category\'s product count.'),

                TextInput::make('label')
                    ->label('Tile label (optional)')
                    ->maxLength(255)
                    ->helperText('Overrides the category name on the tile, e.g. "Ink & Toner". Leave blank to use the category name.'),

                Toggle::make('is_active')
                    ->label('Active')
                    ->default(true)
                    ->helperText('Inactive tiles are hidden from the grid.'),

                TextInput::make('sort_order')
                    ->label('Position')
                    ->numeric()
                    ->default(0)
                    ->helperText('Lower numbers appear first.'),
            ])->columns(2),

            Section::make('Tile image (optional)')
                ->description('Leave blank to use the category\'s own hero image.')
                ->schema([
                    Placeholder::make('current_image')
                        ->hiddenLabel()
                        ->content(fn (?HomepageCategoryPick $record): HtmlString => self::preview($record))
                        ->visible(fn (?HomepageCategoryPick $record): bool => $record?->media !== null),

                    FileUpload::make('image_upload')
                        ->label('Upload tile image')
                        ->image()
                        ->disk('local')
                        ->directory('tmp-admin-uploads')
                        ->dehydrated(),

                    TextInput::make('image_alt')
                        ->label('Image alt text')
                        ->maxLength(255)
                        ->helperText('Defaults to the tile label or category name if left blank.'),
                ])->columns(1),
        ]);
    }

    private static function preview(?HomepageCategoryPick $record): HtmlString
    {
        $media = $record?->media;

        if ($media === null) {
            return new HtmlString('<span style="color:#6b7280">No override image; using the category hero.</span>');
        }

        return new HtmlString(
            '<img src="'.e($media->previewUrl()).'" alt="" '
            .'style="max-height:120px;max-width:200px;object-fit:cover;border:1px solid #e5e7eb;border-radius:8px">'
        );
    }
}
