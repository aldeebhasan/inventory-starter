<?php

namespace App\Filament\Exports;

use App\Filament\Pages\PurchaseCostAnalysis;
use App\Models\PurchaseOrderItem;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Support\Number;

/**
 * Exports the Purchase Cost Analysis report rows (one row per confirmed/received PO line).
 */
class PurchaseCostAnalysisExporter extends Exporter
{
    protected static ?string $model = PurchaseOrderItem::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('product.name')->label('Product'),
            ExportColumn::make('purchaseOrder.supplier.name')->label('Supplier'),
            ExportColumn::make('purchaseOrder.order_number')->label('PO #'),
            ExportColumn::make('purchaseOrder.ordered_at')
                ->label('Ordered Date')
                ->state(fn (PurchaseOrderItem $record): string => $record->purchaseOrder->ordered_at->toDateString()),
            ExportColumn::make('unit_cost')->label('Unit Cost'),
            ExportColumn::make('quantity')->label('Quantity'),
            ExportColumn::make('total_cost')
                ->label('Total Cost')
                ->state(fn (PurchaseOrderItem $record): float => round((float) $record->unit_cost * (float) $record->quantity, 2)),
            ExportColumn::make('previous_unit_cost')->label('Previous Unit Cost'),
            ExportColumn::make('cost_trend')
                ->label('Trend')
                ->state(fn (PurchaseOrderItem $record): string => PurchaseCostAnalysis::costTrend($record)),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your purchase cost analysis export has completed and '.Number::format($export->successful_rows).' '.str('row')->plural($export->successful_rows).' exported.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.Number::format($failedRowsCount).' '.str('row')->plural($failedRowsCount).' failed to export.';
        }

        return $body;
    }
}
