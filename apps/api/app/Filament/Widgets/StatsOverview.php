<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Quote;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The dashboard at a glance: what needs a person, and the shape of the catalogue.
 *
 * "New quotes" is the one number that is a to-do list - a lead nobody has
 * answered yet - so it leads, carries a colour, and shows a 14-day sparkline of
 * incoming leads. The rest orient rather than prompt action.
 */
final class StatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 0;

    protected function getStats(): array
    {
        $trend = $this->quotesPerDay(14);
        $newQuotes = Quote::query()->where('status', 'new')->count();
        $last30 = Quote::query()->where('created_at', '>=', now()->subDays(30))->count();

        $activeProducts = Product::query()->where('status', 'active')->count();
        $draftProducts = Product::query()->where('status', 'draft')->count();

        return [
            Stat::make('New quotes', (string) $newQuotes)
                ->description($newQuotes === 0 ? 'Nothing waiting' : 'Awaiting a response')
                ->descriptionIcon($newQuotes > 0 ? 'heroicon-m-inbox-arrow-down' : 'heroicon-m-check-circle')
                ->color($newQuotes > 0 ? 'warning' : 'success')
                ->chart($trend),

            Stat::make('Quotes (30 days)', (string) $last30)
                ->description('Leads received this month')
                ->descriptionIcon('heroicon-m-chart-bar')
                ->color('primary')
                ->chart($trend),

            Stat::make('Active products', number_format($activeProducts))
                ->description($draftProducts > 0 ? number_format($draftProducts).' drafts awaiting publish' : 'Live on the storefront')
                ->descriptionIcon('heroicon-m-cube')
                ->color($draftProducts > 0 ? 'warning' : 'success'),

            Stat::make('Categories', number_format(Category::query()->count()))
                ->description('Across the taxonomy')
                ->descriptionIcon('heroicon-m-rectangle-stack'),

            Stat::make('Brands', number_format(Brand::query()->count()))
                ->description('In the catalogue')
                ->descriptionIcon('heroicon-m-tag'),
        ];
    }

    /**
     * Leads per day for the last $days days, oldest first, zero-filled - the
     * shape a Stat sparkline wants.
     *
     * @return list<int>
     */
    private function quotesPerDay(int $days): array
    {
        $from = now()->subDays($days - 1)->startOfDay();

        $counts = Quote::query()
            ->where('created_at', '>=', $from)
            ->selectRaw('date(created_at) as d, count(*) as c')
            ->groupBy('d')
            ->pluck('c', 'd');

        $series = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $day = now()->subDays($i)->toDateString();
            $series[] = (int) ($counts[$day] ?? 0);
        }

        return $series;
    }
}
