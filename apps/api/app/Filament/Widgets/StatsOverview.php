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
 * The dashboard at a glance: what is live, what needs a person.
 *
 * "New quotes" is the one number that is a to-do list - a lead nobody has
 * answered yet - so it leads and carries a colour. The rest are the size of the
 * catalogue, for orientation rather than action.
 */
final class StatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 0;

    protected function getStats(): array
    {
        $newQuotes = Quote::query()->where('status', 'new')->count();

        return [
            Stat::make('New quotes', (string) $newQuotes)
                ->description($newQuotes === 0 ? 'Nothing waiting' : 'Awaiting a response')
                ->color($newQuotes > 0 ? 'warning' : 'success'),

            Stat::make('Quotes (all time)', (string) Quote::query()->count())
                ->description('Every lead received'),

            Stat::make('Active products', (string) Product::query()->where('status', 'active')->count())
                ->description('Live on the storefront'),

            Stat::make('Categories', (string) Category::query()->count()),

            Stat::make('Brands', (string) Brand::query()->count()),
        ];
    }
}
