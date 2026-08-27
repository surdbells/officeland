<?php

declare(strict_types=1);

namespace App\Filament\Resources\Quotes\Pages;

use App\Filament\Resources\Quotes\QuoteResource;
use App\Models\Quote;
use App\Models\User;
use App\Quotes\ResponseClock;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

/**
 * The queue (§11a).
 *
 * ── Tabs rather than a default filter ────────────────────────────────────
 * The four questions a salesperson actually opens this screen to ask are
 * "what needs answering", "what is late", "what is mine" and "where is that
 * one from last week". Tabs answer all four in one click and, unlike a
 * pre-applied filter, make it obvious that a narrowed view IS narrowed -
 * a hidden default filter is how somebody concludes a lead was never received.
 *
 * The filters underneath still compose with whichever tab is open.
 */
final class ListQuotes extends ListRecords
{
    protected static string $resource = QuoteResource::class;

    public function getTabs(): array
    {
        return [
            'waiting' => Tab::make('Waiting')
                ->icon(Heroicon::OutlinedInboxArrowDown)
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->whereNull('first_response_at')
                    ->whereNotIn('status', ['won', 'lost', 'spam']))
                ->badge(fn (): ?int => $this->countWaiting() ?: null)
                ->badgeColor('warning'),

            'overdue' => Tab::make('Late')
                ->icon(Heroicon::OutlinedExclamationTriangle)
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->whereIn('id', $this->overdueIds()))
                ->badge(fn (): ?int => count($this->overdueIds()) ?: null)
                ->badgeColor('danger'),

            'mine' => Tab::make('Mine')
                ->icon(Heroicon::OutlinedUser)
                ->modifyQueryUsing(function (Builder $query): Builder {
                    $user = auth()->user();

                    return $query->where(
                        'assigned_to_user_id',
                        $user instanceof User ? $user->id : null,
                    );
                }),

            'all' => Tab::make('Everything')
                ->icon(Heroicon::OutlinedArchiveBox),
        ];
    }

    private function countWaiting(): int
    {
        return Quote::query()
            ->whereNull('first_response_at')
            ->whereNotIn('status', ['won', 'lost', 'spam'])
            ->count();
    }

    /**
     * Overdue is not expressible in SQL - see the filter in QuoteResource.
     *
     * @return array<int, int>
     */
    private function overdueIds(): array
    {
        $clock = app(ResponseClock::class);

        /** @var array<int, int> $ids */
        $ids = Quote::query()
            ->whereNull('first_response_at')
            ->whereNotIn('status', ['won', 'lost', 'spam'])
            ->get()
            ->filter(fn (Quote $quote): bool => $clock->isOverdue($quote))
            ->pluck('id')
            ->all();

        return $ids;
    }
}
