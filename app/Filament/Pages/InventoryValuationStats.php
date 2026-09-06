<?php

namespace App\Filament\Pages;

use Aldeebhasan\Inventorix\Facades\Inventorix;
use Aldeebhasan\Inventorix\Models\Stock;
use App\Models\Product;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class InventoryValuationStats extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $totalValuation = Inventorix::totalValuation();

        $productsTracked = Stock::query()
            ->whereHasMorph('stockable', [Product::class])
            ->where('quantity', '>', 0)
            ->distinct('stockable_id')
            ->count('stockable_id');

        $locations = Stock::query()
            ->whereHasMorph('stockable', [Product::class])
            ->where('quantity', '>', 0)
            ->distinct('location_id')
            ->count('location_id');

        return [
            Stat::make('Total Inventory Value', number_format($totalValuation, 2))
                ->color('success'),
            Stat::make('Products Tracked', $productsTracked),
            Stat::make('Locations', $locations),
        ];
    }
}
