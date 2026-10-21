<?php

declare(strict_types=1);

namespace App\Filament\Resources\HomepageProductPicks\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class HomepageProductPickForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->schema([
                Select::make('product_id')
                    ->label('Product')
                    ->relationship('product', 'name')
                    ->searchable()
                    ->required()
                    // The table carries a unique index; surface it as a form
                    // error rather than a 500 on save.
                    ->unique(table: 'homepage_product_picks', column: 'product_id', ignoreRecord: true)
                    ->helperText('Type to search the catalogue. A product can appear in the band only once.'),

                Toggle::make('is_active')
                    ->label('Active')
                    ->default(true)
                    ->helperText('Inactive picks are hidden. An archived or deleted product drops out of the band automatically.'),

                TextInput::make('sort_order')
                    ->label('Position')
                    ->numeric()
                    ->default(0)
                    ->helperText('Lower numbers appear first.'),
            ])->columns(2),
        ]);
    }
}
