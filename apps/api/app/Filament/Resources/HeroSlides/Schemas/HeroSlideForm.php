<?php

declare(strict_types=1);

namespace App\Filament\Resources\HeroSlides\Schemas;

use App\Models\HeroSlide;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

/**
 * Hero slide form.
 *
 * The image is handled like every other admin upload: a FileUpload writes a
 * temporary file, and the Create/Edit page's mutate hook runs it through the
 * content-addressed pipeline (ImageUploads::mutate) so the storefront's
 * <picture> gets a real srcset. `image_upload` and `image_alt` are virtual -
 * they never reach the model, which stores only the resulting media_id.
 */
final class HeroSlideForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Copy')->schema([
                TextInput::make('headline')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),

                TextInput::make('subheadline')
                    ->maxLength(255)
                    ->columnSpanFull(),

                TextInput::make('cta_label')
                    ->label('Button text')
                    ->maxLength(255)
                    ->helperText('e.g. "Shop laptops". Leave blank for a slide with no button.'),

                TextInput::make('cta_url')
                    ->label('Button link')
                    ->maxLength(255)
                    ->helperText('e.g. /c/it-products/computers/laptops/'),

                TextInput::make('secondary_cta_label')
                    ->label('Second button text')
                    ->maxLength(255),

                TextInput::make('secondary_cta_url')
                    ->label('Second button link')
                    ->maxLength(255),
            ])->columns(2),

            Section::make('Image')->schema([
                Placeholder::make('current_image')
                    ->hiddenLabel()
                    ->content(fn (?HeroSlide $record): HtmlString => self::preview($record))
                    ->visible(fn (?HeroSlide $record): bool => $record?->media !== null),

                FileUpload::make('image_upload')
                    ->label('Upload image')
                    ->image()
                    ->disk('local')
                    ->directory('tmp-admin-uploads')
                    ->dehydrated()
                    ->helperText('A wide, high-resolution photograph. Replacing it on an existing slide swaps the image; leaving it blank keeps the current one.'),

                TextInput::make('image_alt')
                    ->label('Image alt text')
                    ->maxLength(255)
                    ->helperText('Describes the image for screen readers and SEO. Defaults to the headline if left blank.'),
            ])->columns(1),

            Section::make('Visibility')->schema([
                Toggle::make('is_active')
                    ->label('Active')
                    ->helperText('Only active slides appear in the carousel.'),

                TextInput::make('sort_order')
                    ->label('Position')
                    ->numeric()
                    ->default(0)
                    ->helperText('Lower numbers appear first.'),
            ])->columns(2),
        ]);
    }

    private static function preview(?HeroSlide $record): HtmlString
    {
        $media = $record?->media;

        if ($media === null) {
            return new HtmlString('<span style="color:#6b7280">No image yet.</span>');
        }

        return new HtmlString(
            '<img src="'.e($media->previewUrl()).'" alt="" '
            .'style="max-height:160px;max-width:480px;object-fit:cover;border:1px solid #e5e7eb;border-radius:8px">'
        );
    }
}
