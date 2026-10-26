<?php

namespace App\Filament\Exports;

use Aldeebhasan\Inventorix\Models\Stock;
use App\Filament\Pages\StockVelocityReport;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Number;

/**
 * Exports the Stock Velocity report. The export query is the page's filtered table query, which
 * selects `total_deducted` and `peak_demand_date` as subqueries; the period comes from the
 * `period` export option set by the page.
 */
class StockVelocityReportExporter extends Exporter
{
    protected static ?string $model = Stock::class;

    public static function getColumns(): array
    {
        $period = fn (array $options): int => (int) ($options['period'] ?? 30);

        return [
            ExportColumn::make('product')
                ->label('Product')
                ->state(fn (Stock $record): ?string => $record->stockable?->getAttribute('name')),
            ExportColumn::make('location.name')->label('Location'),
            ExportColumn::make('quantity')->label('On Hand'),
            ExportColumn::make('available')
                ->label('Available')
                ->state(fn (Stock $record): float => StockVelocityReport::available($record)),
            ExportColumn::make('avg_daily_consumption')
                ->label('Avg Daily Consumption')
                ->state(fn (Stock $record, array $options): string => number_format(StockVelocityReport::avgDailyConsumption($record, $period($options)), 2, '.', '')),
            ExportColumn::make('days_of_stock')
                ->label('Days of Stock')
                ->state(fn (Stock $record, array $options): string => StockVelocityReport::formatDaysOfStock(StockVelocityReport::daysOfStock($record, $period($options)))),
            ExportColumn::make('peak_demand_date')->label('Peak Demand Day'),
            ExportColumn::make('velocity_status')
                ->label('Status')
                ->state(fn (Stock $record, array $options): string => StockVelocityReport::status(StockVelocityReport::daysOfStock($record, $period($options)))),
        ];
    }

    public static function modifyQuery(Builder $query): Builder
    {
        return $query->with(['stockable', 'location']);
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your stock velocity export has completed and '.Number::format($export->successful_rows).' '.str('row')->plural($export->successful_rows).' exported.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.Number::format($failedRowsCount).' '.str('row')->plural($failedRowsCount).' failed to export.';
        }

        return $body;
    }
}
