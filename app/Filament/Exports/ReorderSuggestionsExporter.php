<?php

namespace App\Filament\Exports;

use Aldeebhasan\Inventorix\Models\Stock;
use App\Filament\Pages\ReorderSuggestions;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Support\Number;

/**
 * Exports the Reorder Suggestions report rows (one row per low-stock product/location).
 * Relies on the page query's selected threshold and velocity columns.
 */
class ReorderSuggestionsExporter extends Exporter
{
    protected static ?string $model = Stock::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('stockable.name')->label('Product'),
            ExportColumn::make('location.name')->label('Location'),
            ExportColumn::make('available')
                ->label('Available')
                ->state(fn (Stock $record): float => ReorderSuggestions::getAvailable($record)),
            ExportColumn::make('min_quantity')->label('Min Threshold'),
            ExportColumn::make('avg_daily')
                ->label('Avg Daily Consumption')
                ->state(fn (Stock $record): float => round(ReorderSuggestions::getAvgDailyConsumption($record), 2)),
            ExportColumn::make('days_until_stockout')
                ->label('Days Until Stockout')
                ->state(fn (Stock $record): ?int => ($days = ReorderSuggestions::getDaysUntilStockout($record)) === null ? null : (int) $days),
            ExportColumn::make('suggested_qty')
                ->label('Suggested Reorder Qty')
                ->state(fn (Stock $record): int => ReorderSuggestions::getSuggestedQty($record)),
            ExportColumn::make('preferred_supplier')
                ->label('Preferred Supplier')
                ->state(fn (Stock $record): ?string => ReorderSuggestions::getPreferredSupplier($record)?->name),
            ExportColumn::make('supplier_cost')
                ->label('Supplier Unit Cost')
                ->state(fn (Stock $record): float => ReorderSuggestions::getUnitCost($record)),
            ExportColumn::make('est_cost')
                ->label('Est. Reorder Cost')
                ->state(fn (Stock $record): float => round(ReorderSuggestions::getSuggestedQty($record) * ReorderSuggestions::getUnitCost($record), 2)),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your reorder suggestions export has completed and '.Number::format($export->successful_rows).' '.str('row')->plural($export->successful_rows).' exported.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.Number::format($failedRowsCount).' '.str('row')->plural($failedRowsCount).' failed to export.';
        }

        return $body;
    }
}
