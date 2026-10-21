<?php

declare(strict_types=1);

namespace App\Filament\Resources\Announcements\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class AnnouncementForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->schema([
                TextInput::make('message')
                    ->required()
                    ->maxLength(255)
                    ->helperText('Shown in the bar above the header. Keep it to one short line.')
                    ->columnSpanFull(),

                TextInput::make('url')
                    ->label('Link (optional)')
                    ->maxLength(255)
                    ->helperText('Where the bar links to, e.g. /c/printers-consumables/ or a full URL. Leave blank for a notice with no link.'),

                TextInput::make('link_label')
                    ->label('Link text')
                    ->maxLength(255)
                    ->helperText('The clickable words, e.g. "Shop the sale". Defaults to the whole bar being clickable.'),

                Select::make('variant')
                    ->options([
                        'info' => 'Info (neutral)',
                        'promo' => 'Promo (brand colour)',
                        'warning' => 'Warning (amber)',
                    ])
                    ->default('info')
                    ->required(),
            ])->columns(2),

            Section::make('Visibility')->schema([
                Toggle::make('is_active')
                    ->label('Active')
                    ->helperText('Only active announcements can show. The first active one in position order, inside its date window, is the one visitors see.'),

                TextInput::make('sort_order')
                    ->label('Position')
                    ->numeric()
                    ->default(0)
                    ->helperText('Lower numbers win when more than one is active.'),

                DateTimePicker::make('starts_at')
                    ->label('Show from (optional)')
                    ->helperText('Leave blank to show immediately once active.'),

                DateTimePicker::make('ends_at')
                    ->label('Hide after (optional)')
                    ->helperText('Leave blank to show until deactivated.'),
            ])->columns(2),
        ]);
    }
}
