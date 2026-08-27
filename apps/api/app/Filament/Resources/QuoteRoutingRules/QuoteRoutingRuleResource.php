<?php

declare(strict_types=1);

namespace App\Filament\Resources\QuoteRoutingRules;

use App\Filament\Resources\QuoteRoutingRules\Pages\ListQuoteRoutingRules;
use App\Models\Category;
use App\Models\QuoteRoutingRule;
use App\Models\User;
use App\Quotes\QuoteRouting;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Who receives which leads (§11d).
 *
 * ── What this screen is really preventing ────────────────────────────────
 * One shared inbox works until it doesn't. When everyone receives every lead,
 * the ones nobody owns are the ones nobody answers - diffusion of
 * responsibility, not volume, is the failure mode. Routing furniture enquiries
 * to the furniture team makes ownership the default rather than an act of
 * initiative.
 *
 * ── A rule can only narrow, never drop ───────────────────────────────────
 * There is no "send nowhere" option and no way to delete the fallback. An
 * unroutable lead goes to the default inbox. A configuration screen for a
 * feature whose purpose is "make leads more likely to be answered" must not
 * offer a way to make a lead vanish.
 */
final class QuoteRoutingRuleResource extends Resource
{
    protected static ?string $model = QuoteRoutingRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Quotes';

    protected static ?string $navigationLabel = 'Notification routing';

    protected static ?string $modelLabel = 'routing rule';

    protected static ?int $navigationSort = 20;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->role->managesQuotes();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('category.name')
                    ->label('Category')
                    ->searchable()
                    // The path is what actually matches, so it is shown rather
                    // than left implicit - two categories can share a name.
                    ->description(fn (QuoteRoutingRule $record): string => (string) $record->category?->path),

                TextColumn::make('recipient')
                    ->label('Goes to')
                    ->searchable()
                    ->copyable(),

                IconColumn::make('is_active')->label('Active')->boolean(),

                TextColumn::make('note')
                    ->label('Note')
                    ->placeholder('-')
                    ->wrap()
                    ->toggleable(),
            ])
            ->defaultSort('category_id')
            ->emptyStateHeading('Everything goes to the default inbox')
            ->emptyStateDescription(
                'Add a rule to send one department\'s enquiries somewhere else. '.
                'Anything without a rule still reaches '.app(QuoteRouting::class)->default().'.'
            );
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('category_id')
                ->label('Category')
                ->options(fn (): array => Category::query()
                    ->orderBy('path')
                    ->pluck('path', 'id')
                    ->all())
                ->searchable()
                ->required()
                // One rule per category: two would be resolved by insertion
                // order, which is nobody's decision.
                ->unique(ignoreRecord: true)
                ->helperText('Matched on the primary category of the first line, and on everything beneath it.'),

            TextInput::make('recipient')
                ->label('Send to')
                ->email()
                ->required()
                ->helperText('A shared team inbox survives holidays and resignations; a personal address does not.'),

            Toggle::make('is_active')
                ->label('Active')
                ->default(true)
                ->helperText('Turn off to pause a rule without losing it - leads fall back to the default inbox.'),

            TextInput::make('note')
                ->label('Note')
                ->helperText('Who owns this, or why it exists.'),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListQuoteRoutingRules::route('/'),
        ];
    }
}
