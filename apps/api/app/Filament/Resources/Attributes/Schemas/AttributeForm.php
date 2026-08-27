<?php

declare(strict_types=1);

namespace App\Filament\Resources\Attributes\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Attribute form.
 *
 * Facet indexability is a data attribute, never a hardcoded list
 * (MASTER BUILD PROMPT section 14.5 rule 4) - which is precisely why it is
 * editable here, and why the helper text explains what turning it on commits
 * you to.
 */
final class AttributeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->schema([
                TextInput::make('name')->required()->maxLength(255),

                TextInput::make('code')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->rules(['regex:/^[a-z0-9_]+$/'])
                    ->helperText('Internal key, lowercase with underscores - e.g. page_yield.'),

                Select::make('group_id')
                    ->label('Specification group')
                    ->relationship('group', 'name')
                    ->searchable()
                    ->preload()
                    ->helperText('Groups the specifications accordion on the product page. Ungrouped attributes appear under "General".'),

                Select::make('data_type')
                    ->options([
                        'string' => 'Text',
                        'numeric' => 'Number - filterable by range',
                        'boolean' => 'Yes / no',
                        'dimension' => 'Dimension',
                    ])
                    ->default('string')
                    ->required(),

                TextInput::make('unit')->helperText('e.g. pages, mm, months.'),
                TextInput::make('unit_symbol')->label('Unit symbol')->maxLength(16),

                TextInput::make('display_order')
                    ->label('Importance')
                    ->numeric()
                    ->default(0)
                    ->helperText('Higher numbers appear first, and are used to pick the key-spec bullets shown above the fold.'),
            ])->columns(2),

            Section::make('Filtering and search')->schema([
                Toggle::make('is_filterable')
                    ->label('Buyers can filter by this')
                    ->helperText('Adds it to the filter rail on listing pages.'),

                Toggle::make('is_key_spec')
                    ->label('Show in the key-spec bullets')
                    ->helperText('The four to six facts shown on the product card and above the fold.'),

                Toggle::make('is_indexable_facet')
                    ->label('Filtered pages for this may be indexed by Google')
                    ->helperText('Leave off unless this combination has proven search demand. Each one you turn on becomes a page that must earn its place with its own copy - start with fewer than thirty across the whole site.'),
            ])->columns(3),
        ]);
    }
}
