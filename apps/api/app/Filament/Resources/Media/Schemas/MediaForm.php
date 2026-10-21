<?php

declare(strict_types=1);

namespace App\Filament\Resources\Media\Schemas;

use App\Models\Media;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

final class MediaForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->schema([
                Placeholder::make('preview')
                    ->label('Image')
                    ->content(fn (Media $record): HtmlString => new HtmlString(
                        '<img src="'.e($record->previewUrl()).'" alt="" '.
                        'style="max-height:220px;max-width:100%;border-radius:8px;border:1px solid #e5e7eb" />'
                    )),

                // Alt text is the one field that gates attachment (Media::
                // assertAttachable), so it is the point of editing here.
                TextInput::make('alt_text')
                    ->label('Alt text')
                    ->helperText('Describes the image for screen readers and SEO. Required before it can be attached to a product.')
                    ->maxLength(255),

                TextInput::make('credit')
                    ->label('Credit')
                    ->maxLength(255),
            ]),

            Section::make('Details')
                ->schema([
                    TextInput::make('original_filename')->label('File name')->disabled(),
                    TextInput::make('mime')->label('Type')->disabled(),
                    Placeholder::make('dimensions')
                        ->content(fn (Media $record): string => $record->width.' × '.$record->height.' px'),
                    Placeholder::make('size')
                        ->content(fn (Media $record): string => number_format((int) $record->bytes / 1024).' KB'),
                    TextInput::make('source')->disabled(),
                    TextInput::make('source_url')->label('Source URL')->disabled(),
                ])
                ->columns(2),
        ]);
    }
}
