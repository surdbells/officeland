<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\Quotes\QuoteResource;
use App\Models\Quote;
use App\Models\User;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * The newest quote leads, newest first, as a one-click-to-open list.
 *
 * The dashboard's job for a quote-first business is to put the unanswered
 * leads in front of whoever opens it. Only roles that work quotes see it.
 */
final class LatestLeads extends TableWidget
{
    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Latest leads';

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->role->managesQuotes();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Quote::query()
                    ->whereNotIn('status', ['won', 'lost', 'spam'])
                    ->latest('created_at'),
            )
            ->columns([
                TextColumn::make('reference')->label('Reference')->searchable(),
                TextColumn::make('email')->searchable()->limit(32),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'new' ? 'warning' : 'gray'),
                TextColumn::make('source')->badge()->toggleable(),
                TextColumn::make('created_at')->label('Received')->since()->sortable(),
            ])
            ->recordUrl(fn (Quote $record): string => QuoteResource::getUrl('view', ['record' => $record]))
            ->defaultPaginationPageOption(5)
            ->paginationPageOptions([5, 10, 25])
            ->emptyStateHeading('No open leads')
            ->emptyStateDescription('New quote requests will appear here.');
    }
}
