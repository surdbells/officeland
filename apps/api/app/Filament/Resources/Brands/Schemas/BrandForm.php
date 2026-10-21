<?php

declare(strict_types=1);

namespace App\Filament\Resources\Brands\Schemas;

use App\Filament\Forms\SeoFields;
use App\Models\Brand;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Brand form.
 *
 * The generated scaffold exposed every column - uuid, logo_media_id,
 * product_count_cached. The Phase 5 gate is that a NON-TECHNICAL user can
 * complete a create/edit unaided, and a form asking someone to supply a UUID
 * fails that on sight. Internal and derived fields are not editable here.
 */
final class BrandForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make()->tabs([

                Tab::make('Details')->schema([
                    Section::make()->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            // The slug is generated but stays editable: it is
                            // part of a public URL, and changing it later
                            // auto-creates a 301 (SEO_SPEC.md section 2).
                            ->afterStateUpdated(function (string $operation, $state, callable $set): void {
                                if ($operation === 'create') {
                                    $set('slug', Str::slug((string) $state));
                                }
                            }),

                        TextInput::make('slug')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true)
                            ->rules(['regex:/^[a-z0-9]+(-[a-z0-9]+)*$/'])
                            ->helperText('Appears in the address bar: officelandng.com/brands/<slug>/. Changing it on a live brand creates a redirect automatically.'),

                        RichEditor::make('description_html')
                            ->label('About this brand')
                            ->helperText('Shown on the brand hub page. Original copy - not the manufacturer’s boilerplate.')
                            ->columnSpanFull(),
                    ])->columns(2),

                    Section::make('Merchandising')->schema([
                        Toggle::make('is_featured')
                            ->label('Show in the main navigation')
                            ->helperText('Featured brands appear in the megamenu and on the homepage brand rail.'),

                        TextInput::make('sort_order')
                            ->label('Position')
                            ->numeric()
                            ->default(0)
                            ->helperText('Lower numbers appear first.'),
                    ])->columns(2),

                    Section::make('Logo')
                        ->description('Use the "Set logo" button at the top of the page to upload or replace it.')
                        ->schema([
                            Placeholder::make('logo_preview')
                                ->hiddenLabel()
                                ->content(fn (?Brand $record): HtmlString => self::logoPreview($record)),
                        ]),

                    Section::make('Product data')->schema([
                        TextInput::make('icecat_supplier_name')
                            ->label('Icecat supplier name')
                            ->helperText('Only if it differs from the brand name above - for example "HP Inc." vs "HP". Used to match products during import.'),
                    ]),
                ]),

                Tab::make('Search appearance')->schema(
                    SeoFields::make('/brands/')
                ),
            ])->columnSpanFull(),
        ]);
    }

    /** Renders the current logo, or a hint when none is set. */
    private static function logoPreview(?Brand $record): HtmlString
    {
        $media = $record?->logoMedia;

        if ($media === null) {
            return new HtmlString('<span style="color:#6b7280">No logo set yet.</span>');
        }

        return new HtmlString(
            '<img src="'.e($media->previewUrl()).'" alt="" '
            .'style="max-height:80px;max-width:240px;object-fit:contain;background:#fff;border:1px solid #e5e7eb;border-radius:6px;padding:6px">'
        );
    }
}
