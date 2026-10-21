<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Quote;
use App\Models\User;
use Filament\Widgets\ChartWidget;

/**
 * Quote leads received per day over the last 30 days.
 *
 * Turns the single "quotes this month" number into a shape - whether demand is
 * steady, spiking or drying up. Quote-managing roles only.
 */
final class QuotesTrendChart extends ChartWidget
{
    protected static ?int $sort = 2;

    protected ?string $heading = 'Quote leads · last 30 days';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->role->managesQuotes();
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $days = 30;
        $from = now()->subDays($days - 1)->startOfDay();

        $counts = Quote::query()
            ->where('created_at', '>=', $from)
            ->selectRaw('date(created_at) as d, count(*) as c')
            ->groupBy('d')
            ->pluck('c', 'd');

        $labels = [];
        $values = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $day = now()->subDays($i);
            $labels[] = $day->format('j M');
            $values[] = (int) ($counts[$day->toDateString()] ?? 0);
        }

        return [
            'datasets' => [[
                'label' => 'Leads',
                'data' => $values,
                'borderColor' => '#0046BF',
                'backgroundColor' => 'rgba(0, 70, 191, 0.12)',
                'fill' => true,
                'tension' => 0.3,
            ]],
            'labels' => $labels,
        ];
    }
}
