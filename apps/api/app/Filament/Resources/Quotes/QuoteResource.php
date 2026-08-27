<?php

declare(strict_types=1);

namespace App\Filament\Resources\Quotes;

use App\Filament\Resources\Quotes\Pages\ListQuotes;
use App\Filament\Resources\Quotes\Pages\ViewQuote;
use App\Models\Quote;
use App\Models\User;
use App\Quotes\ResponseClock;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * The lead queue (§11a, D-005).
 *
 * D-005 removed the priced-quote cycle: sales prices and responds outside this
 * system. So this is not a CRM - it is a queue whose only job is to make sure
 * every lead is seen, owned, and answered within the promise the site makes on
 * every page.
 *
 * ── The default view is deliberately narrow ──────────────────────────────
 * Waiting first, oldest first. A queue that opens on "everything ever
 * submitted" is a queue that gets scrolled past; one that opens on "what needs
 * you now" gets worked. Everything else is one tab away (see ListQuotes).
 *
 * ── Why there is no create form ──────────────────────────────────────────
 * A quote is a record of what a customer asked for. Typing one by hand would
 * produce a lead with a reference, a snapshot and an audit trail that nobody
 * actually submitted - and it would be indistinguishable from a real one
 * afterwards. Phone enquiries belong in whatever sales already uses.
 */
final class QuoteResource extends Resource
{
    protected static ?string $model = Quote::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static string|UnitEnum|null $navigationGroup = 'Quotes';

    protected static ?string $navigationLabel = 'Lead queue';

    protected static ?string $modelLabel = 'quote';

    protected static ?int $navigationSort = 10;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->role->managesQuotes();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * The count of leads that need someone, shown on the navigation item.
     *
     * The badge is the point of the whole screen: a salesperson should know
     * there is work without opening it.
     */
    public static function getNavigationBadge(): ?string
    {
        $waiting = Quote::query()
            ->whereNull('first_response_at')
            ->whereNotIn('status', ['won', 'lost', 'spam'])
            ->count();

        return $waiting > 0 ? (string) $waiting : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        $clock = app(ResponseClock::class);

        $overdue = Quote::query()
            ->whereNull('first_response_at')
            ->whereNotIn('status', ['won', 'lost', 'spam'])
            ->get()
            ->contains(fn (Quote $quote): bool => $clock->isOverdue($quote));

        return $overdue ? 'danger' : 'warning';
    }

    public static function table(Table $table): Table
    {
        $clock = app(ResponseClock::class);

        return $table
            ->columns([
                TextColumn::make('reference')
                    ->label('Ref')
                    ->searchable()
                    ->copyable()
                    ->weight('bold'),

                TextColumn::make('email')
                    ->label('Customer')
                    ->searchable()
                    ->description(fn (Quote $record): string => (string) $record->phone),

                TextColumn::make('items_count')
                    ->label('Lines')
                    ->counts('items')
                    ->alignEnd(),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'new' => 'warning',
                        'acknowledged', 'contacted' => 'info',
                        'won' => 'success',
                        'lost' => 'gray',
                        'spam' => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('assignedTo.name')
                    ->label('Owner')
                    // The state this screen exists to eliminate. An unassigned
                    // lead is one nobody has taken responsibility for.
                    ->placeholder('Unassigned')
                    ->sortable(),

                /*
                 * §11c. Working hours, not elapsed hours: a Friday-evening
                 * lead answered Monday morning took one working hour, and a
                 * queue where every weekend row is red is a queue nobody
                 * reads - which hides the one that is genuinely late.
                 */
                TextColumn::make('waiting')
                    ->label('Waiting')
                    ->state(fn (Quote $record): string => $clock->describe($record))
                    ->badge()
                    ->color(fn (Quote $record): string => match (true) {
                        $record->first_response_at !== null => 'success',
                        $clock->isOverdue($record) => 'danger',
                        ($clock->waitingHours($record) ?? 0) > ResponseClock::TARGET_WORKING_HOURS / 2 => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('submitted_at')
                    ->label('Submitted')
                    ->dateTime('j M, H:i')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('source')
                    ->badge()
                    ->color('gray')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            /*
             * Oldest first, which is the opposite of most admin tables and
             * correct here: the oldest unanswered lead is the most urgent, and
             * newest-first buries it further with every new submission.
             */
            ->defaultSort('submitted_at', 'asc')
            ->filters([
                SelectFilter::make('status')->options([
                    'new' => 'New',
                    'acknowledged' => 'Acknowledged',
                    'contacted' => 'Contacted',
                    'won' => 'Won',
                    'lost' => 'Lost',
                    'spam' => 'Spam',
                ])->multiple(),

                SelectFilter::make('assigned_to_user_id')
                    ->label('Owner')
                    ->relationship('assignedTo', 'name')
                    ->searchable(),

                SelectFilter::make('source')->options([
                    'web' => 'Website',
                    'phone' => 'Phone',
                    'walk_in' => 'Walk-in',
                    'email' => 'Email',
                ]),

                Filter::make('unassigned')
                    ->label('Nobody has taken it')
                    ->query(fn (Builder $query): Builder => $query->whereNull('assigned_to_user_id')),
            ])
            ->emptyStateHeading('Nothing waiting')
            ->emptyStateDescription('Every lead has had a first reply.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListQuotes::route('/'),
            'view' => ViewQuote::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['assignedTo']);
    }
}
