<?php

declare(strict_types=1);

namespace App\Filament\Resources\SearchSynonyms\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

/**
 * Synonym editor (MASTER BUILD PROMPT section 16).
 *
 * Written for a merchandiser working through the zero-result report, not for
 * someone who knows what a tsquery is. The helper text explains the one thing
 * that genuinely matters and is easy to get wrong: which direction the
 * synonym runs.
 */
final class SearchSynonymForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->schema([
                TextInput::make('term')
                    ->label('What buyers type')
                    ->required()
                    ->maxLength(255)
                    // Matching is case-insensitive, and storing mixed case
                    // just makes duplicates that look identical in the table.
                    ->dehydrateStateUsing(fn (?string $state): string => mb_strtolower(trim((string) $state)))
                    ->helperText('e.g. biro, pendrive, ce320a'),

                TextInput::make('synonym')
                    ->label('What the catalogue calls it')
                    ->required()
                    ->maxLength(255)
                    ->dehydrateStateUsing(fn (?string $state): string => mb_strtolower(trim((string) $state)))
                    ->helperText('e.g. pen, flash drive, 128a'),

                Select::make('type')
                    ->options([
                        'usage' => 'Everyday wording - biro / pen',
                        'model_code' => 'Model code - CE320A / 128A',
                        'brand' => 'Brand name variant',
                        'typo' => 'Common misspelling',
                    ])
                    ->default('usage')
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (?string $state, Set $set): void {
                        // A misspelling should find the correct term, but the
                        // correct term must not drag in the misspelling's
                        // matches - so typos default to one-way.
                        $set('bidirectional', $state !== 'typo');
                    }),

                Toggle::make('bidirectional')
                    ->label('Works both ways')
                    ->helperText(fn (Get $get): string => $get('type') === 'typo'
                        ? 'Usually off for misspellings: the wrong spelling should find the right product, but not the other way round.'
                        : 'On means searching either word finds both. Leave on unless one of them is a mistake.'),

                Toggle::make('is_active')
                    ->label('Active')
                    ->default(true)
                    ->helperText('Turn off to disable without deleting, so you can tell whether it was helping.'),
            ])->columns(2),
        ]);
    }
}
