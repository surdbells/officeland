<?php

declare(strict_types=1);

namespace App\Filament\Resources\Redirects\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

final class RedirectForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->schema([
                TextInput::make('from_path')
                    ->label('Old address')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->rules(['regex:/^\//'])
                    ->helperText('Starts with a slash, e.g. /product-category/hp/printers/'),

                Select::make('status_code')
                    ->label('Type')
                    ->options([
                        301 => '301 — moved permanently (use this normally)',
                        302 => '302 — moved temporarily',
                        410 => '410 — gone for good, no replacement',
                    ])
                    ->default(301)
                    ->required()
                    ->live(),

                TextInput::make('to_path')
                    ->label('New address')
                    // A 410 with a destination is a contradiction and the
                    // database rejects it, so the form stops it earlier and
                    // with a better message than a constraint violation.
                    ->visible(fn (Get $get): bool => (int) $get('status_code') !== 410)
                    ->requiredIf('status_code', [301, 302])
                    ->rules(['nullable', 'regex:/^\//'])
                    ->helperText('Where it should go instead.'),

                Textarea::make('notes')->rows(2)->columnSpanFull(),
            ])->columns(2),
        ]);
    }
}
