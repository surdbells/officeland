<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Schemas;

use App\Filament\Forms\SeoFields;
use App\Models\Product;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Product form — the Phase 5 gate.
 *
 * The gate is that a NON-TECHNICAL user can complete a create/edit unaided,
 * so this is written for a merchandiser rather than for the schema:
 *
 *   - internal and derived fields (uuid, brand_name_cached, search_text_specs,
 *     icecat_id, product counts) are not editable and not shown
 *   - every field that constrains publishing says so in plain language
 *   - the content floor of section 14.7 is visible while writing, not
 *     discovered at publish time
 *   - "quote only" is preselected because D-002 means there are no prices
 */
final class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make()->tabs([

                // -----------------------------------------------------
                Tab::make('Product')->schema([
                    Section::make()->schema([
                        TextInput::make('name')
                            ->label('Product name')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->helperText('How buyers see it. Lead with the brand, then what it is, then the model code — for example "HP 128A Black LaserJet Toner Cartridge (CE320A)".')
                            ->afterStateUpdated(function (string $operation, $state, Set $set): void {
                                if ($operation === 'create') {
                                    $set('slug', Str::slug(Str::limit((string) $state, 75, '')));
                                }
                            }),

                        TextInput::make('slug')
                            ->required()
                            ->maxLength(75)
                            ->unique(ignoreRecord: true)
                            ->rules(['regex:/^[a-z0-9]+(-[a-z0-9]+)*$/'])
                            ->helperText('The web address: officelandng.com/p/<slug>/. Changing it on a live product creates a redirect automatically, so nothing breaks.'),

                        Select::make('brand_id')
                            ->label('Brand')
                            ->relationship('brand', 'name')
                            ->searchable()
                            ->preload()
                            ->helperText('Leave empty for unbranded stock.'),

                        Select::make('primary_category_id')
                            ->label('Main category')
                            ->relationship('primaryCategory', 'path')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->helperText('Decides the breadcrumb and the page address. A product can sit in several categories, but only this one counts as its home.'),

                        Select::make('categories')
                            ->label('Also appears in')
                            ->relationship('categories', 'path')
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->helperText('Optional. Listing pages a buyer might reasonably expect to find this in.'),
                    ])->columns(2),

                    Section::make('Identifiers')
                        ->description('Buyers search by model code more than by anything else, so fill these in wherever you can.')
                        ->schema([
                            TextInput::make('sku')
                                ->label('SKU')
                                ->unique(ignoreRecord: true)
                                ->helperText('Your own stock code. Optional.'),
                            TextInput::make('mpn')
                                ->label('Manufacturer part number')
                                ->helperText('The manufacturer\'s code, e.g. CE320A. Used to match products on import.'),
                            TextInput::make('model_code')
                                ->label('Model code'),
                            TextInput::make('ean')
                                ->label('Barcode (EAN/GTIN)')
                                ->maxLength(14),
                        ])->columns(2),
                ]),

                // -----------------------------------------------------
                Tab::make('Description')->schema([
                    Section::make()->schema([
                        Textarea::make('short_description')
                            ->label('One-line summary')
                            ->rows(2)
                            ->maxLength(500)
                            ->helperText('Shown on listing pages and in search results.')
                            ->columnSpanFull(),

                        RichEditor::make('description_html')
                            ->label('Full description')
                            ->live(onBlur: true)
                            ->columnSpanFull()
                            // Section 14.7: below the content minimum a product
                            // is not indexable. Showing the count while writing
                            // is the difference between a rule people follow and
                            // a rule they discover at publish time.
                            ->helperText(fn (?string $state): HtmlString => self::wordCount($state)),

                        Textarea::make('warranty_text')
                            ->label('Warranty')
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),
                ]),

                // -----------------------------------------------------
                Tab::make('Availability')->schema([
                    Section::make()->schema([
                        Select::make('availability')
                            ->options([
                                'in_stock' => 'In stock',
                                'limited' => 'Limited stock',
                                'made_to_order' => 'Made to order',
                                'lead_time' => 'Available on lead time',
                                'discontinued' => 'Discontinued',
                            ])
                            ->required()
                            ->default('lead_time')
                            ->live(),

                        TextInput::make('lead_time_days')
                            ->label('Lead time (days)')
                            ->numeric()
                            ->minValue(0)
                            ->visible(fn (Get $get): bool => in_array($get('availability'), ['made_to_order', 'lead_time'], true))
                            ->helperText('Roughly how long from order to delivery. Buyers ask this constantly.'),

                        Select::make('pricing_mode')
                            ->label('Pricing')
                            ->options([
                                'quote_only' => 'Quote only — no price shown',
                                'public' => 'Show a price',
                            ])
                            ->default('quote_only')
                            ->required()
                            // D-002: no prices anywhere yet. The field exists so
                            // the posture can change without a migration.
                            ->helperText('OfficeLand is quote-first, so this stays on "Quote only" until prices are switched on site-wide.'),

                        TextInput::make('min_order_qty')
                            ->label('Minimum order quantity')
                            ->numeric()
                            ->minValue(1)
                            ->default(1)
                            ->required(),

                        TextInput::make('qty_step')
                            ->label('Sold in multiples of')
                            ->numeric()
                            ->minValue(1)
                            ->default(1)
                            ->required()
                            ->helperText('For example 12 if it only ships by the dozen.'),
                    ])->columns(2),

                    Section::make('Variants')
                        ->description('Colourways, sizes, toner yields — anything a buyer picks between without it being a separate product.')
                        ->schema([
                            Repeater::make('variants')
                                ->relationship()
                                ->schema([
                                    TextInput::make('name')->required(),
                                    TextInput::make('sku'),
                                    TextInput::make('mpn'),
                                    Select::make('availability')
                                        ->options([
                                            'in_stock' => 'In stock',
                                            'limited' => 'Limited stock',
                                            'made_to_order' => 'Made to order',
                                            'lead_time' => 'Lead time',
                                            'discontinued' => 'Discontinued',
                                        ])
                                        ->default('lead_time'),
                                ])
                                ->columns(2)
                                // Repeater::reorderable() takes a bool; the
                                // column is named separately. Table::reorderable()
                                // takes the column — the two APIs differ.
                                ->reorderable()
                                ->orderColumn('sort_order')
                                ->collapsible()
                                ->itemLabel(fn (array $state): ?string => $state['name'] ?? null)
                                ->addActionLabel('Add a variant')
                                ->defaultItems(0),
                        ]),
                ]),

                // -----------------------------------------------------
                Tab::make('Publishing')->schema([
                    Section::make()->schema([
                        Select::make('status')
                            ->options([
                                'draft' => 'Draft — not visible on the site',
                                'active' => 'Active — visible on the site',
                                'archived' => 'Archived — hidden, kept for reference',
                            ])
                            ->default('draft')
                            ->required(),

                        Toggle::make('is_indexable')
                            ->label('Allow Google to index this page')
                            ->helperText('Turn on once the description is long enough to be genuinely useful. See the word count on the Description tab.'),
                    ])->columns(2),
                ]),

                // -----------------------------------------------------
                Tab::make('Search appearance')->schema(
                    SeoFields::make('/p/')
                ),
            ])->columnSpanFull(),
        ]);
    }

    private static function wordCount(?string $state): HtmlString
    {
        $words = str_word_count(strip_tags((string) $state));
        $minimum = Product::CONTENT_MINIMUM_WORDS;

        if ($words === 0) {
            return new HtmlString(
                '<span style="color:#6b7280">Aim for at least '.$minimum.' words of original description. '
                .'Do not paste the manufacturer\'s text — every other reseller publishes the same words, and Google has seen them.</span>'
            );
        }

        if ($words < $minimum) {
            return new HtmlString(
                '<span style="color:#b45309;font-weight:600">'.$words.' / '.$minimum.' words. '
                .'Below '.$minimum.' this page should not be indexed.</span>'
            );
        }

        return new HtmlString(
            '<span style="color:#15803d;font-weight:600">'.$words.' words — long enough to index.</span>'
        );
    }
}
