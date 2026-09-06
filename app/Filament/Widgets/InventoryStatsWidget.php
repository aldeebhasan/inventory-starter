<?php

namespace App\Filament\Widgets;

use Aldeebhasan\Inventorix\Facades\Inventorix;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SaleOrderStatus;
use App\Filament\Pages\LowStockAlerts;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\SaleOrder;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class InventoryStatsWidget extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $lowStockCount = Inventorix::lowStockItems(stockableType: Product::class)->count();

        $pendingOrders = PurchaseOrder::where('status', PurchaseOrderStatus::Confirmed)->count()
            + SaleOrder::where('status', SaleOrderStatus::Confirmed)->count();

        return [
            Stat::make('Total Products', Product::count()),

            Stat::make('Low Stock Items', $lowStockCount)
                ->color('danger')
                ->url(LowStockAlerts::getUrl()),

            Stat::make('Inventory Value', number_format(Inventorix::totalValuation(), 2))
                ->color('success'),

            Stat::make('Pending Orders', $pendingOrders)
                ->color('warning'),
        ];
    }
}
