<?php

declare(strict_types=1);

namespace App\Filament\Resources\SearchQueries;

use App\Filament\Resources\SearchQueries\Pages\ListSearchQueries;
use App\Models\SearchQueryLog;
use App\Models\SearchSynonym;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * What buyers searched for, and whether they found anything (section 14.10).
 *
 * The zero-result list is the point. It is literally a ranked list of things
 * customers asked for and did not get, and every entry is one of three
 * actions: stock the product, add a synonym, or write the category page.
 *
 * The "add synonym" action closes that loop in place, because a report that
 * requires retyping the term into another screen is a report nobody works
 * through twice.
 */
final class SearchQueryResource extends Resource
{
    protected static ?string $model = SearchQueryLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMagnifyingGlass;

    protected static string|UnitEnum|null $navigationGroup = 'Search';

    protected static ?string $navigationLabel = 'What buyers searched for';

    protected static ?string $modelLabel = 'search';

    protected static ?int $navigationSort = 20;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && ($user->role->managesCatalogue() || $user->role->managesContent());
    }

    /** Draws attention to the number that matters without opening the page. */
    public static function getNavigationBadge(): ?string
    {
        $count = SearchQueryLog::query()->zeroResult()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('query_raw')->label('Searched for')->searchable()->limit(60),
                TextColumn::make('occurrences')->label('Times')->numeric()->sortable(),
                TextColumn::make('result_count')
                    ->label('Results')
                    ->numeric()
                    ->sortable()
                    ->badge()
                    ->color(fn (int $state): string => $state === 0 ? 'danger' : 'success'),
                TextColumn::make('clickthroughs')->label('Clicks')->numeric()->toggleable(),
                TextColumn::make('last_seen_at')->label('Last searched')->dateTime('d M Y')->sortable(),
            ])
            ->filters([
                Filter::make('zero_result')
                    ->label('Found nothing')
                    ->default()
                    ->query(fn (Builder $query): Builder => $query->where('result_count', 0)),

                // Searched repeatedly and still nothing: the strongest signal
                // in the whole report.
                Filter::make('repeated_failure')
                    ->label('Asked for more than once and still nothing')
                    ->query(fn (Builder $query): Builder => $query
                        ->where('result_count', 0)
                        ->where('occurrences', '>', 1)),

                // Results returned but nobody clicked - usually means the
                // results were wrong rather than absent, which is a ranking
                // problem rather than a stocking one.
                Filter::make('no_clicks')
                    ->label('Results shown but not clicked')
                    ->query(fn (Builder $query): Builder => $query
                        ->where('result_count', '>', 0)
                        ->where('clickthroughs', 0)),
            ])
            ->recordActions([
                Action::make('addSynonym')
                    ->label('Add synonym')
                    ->icon(Heroicon::OutlinedArrowsRightLeft)
                    ->visible(fn (SearchQueryLog $record): bool => $record->result_count === 0)
                    ->schema([
                        TextInput::make('term')
                            ->label('Buyers typed')
                            ->disabled()
                            ->default(fn (SearchQueryLog $record): string => $record->query_normalised),

                        TextInput::make('synonym')
                            ->label('Search for this instead')
                            ->required()
                            ->helperText('The word the catalogue actually uses.'),
                    ])
                    ->action(function (SearchQueryLog $record, array $data): void {
                        $term = mb_strtolower(trim($record->query_normalised));
                        $synonym = mb_strtolower(trim((string) $data['synonym']));

                        if ($term === $synonym) {
                            Notification::make()
                                ->title('A term cannot be a synonym of itself.')
                                ->warning()
                                ->send();

                            return;
                        }

                        SearchSynonym::updateOrCreate(
                            ['term' => $term, 'synonym' => $synonym],
                            ['type' => 'usage', 'bidirectional' => true, 'is_active' => true],
                        );

                        Notification::make()
                            ->title("Buyers searching “{$term}” will now find “{$synonym}”.")
                            ->success()
                            ->send();
                    }),
            ])
            ->defaultSort('occurrences', 'desc');
    }

    /** @return array<string, mixed> */
    public static function getPages(): array
    {
        return ['index' => ListSearchQueries::route('/')];
    }
}
