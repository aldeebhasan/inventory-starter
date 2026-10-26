<?php

namespace App\Filament\Exports;

use Aldeebhasan\Inventorix\Models\Stock;
use App\Filament\Pages\DeadStockReport;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Number;

/**
 * Exports the Dead Stock report. The export query is the page's filtered table query, which already
 * selects `last_deduct_at` as a subquery, so no per-row queries are needed.
 */
class DeadStockReportExporter extends Exporter
{
    protected static ?string $model = Stock::class;

    public static function getColumns(): array
    {
        return [
            // stockable is a morphTo, so read through it in state() (eager-loaded in modifyQuery()).
            ExportColumn::make('product')
                ->label('Product')
                ->state(fn (Stock $record): ?string => $record->stockable?->getAttribute('name')),
            ExportColumn::make('brand_name')->label('Brand'),
            ExportColumn::make('location.name')->label('Location'),
            ExportColumn::make('quantity')->label('Qty On Hand'),
            ExportColumn::make('last_movement')
                ->label('Last Movement Date')
                ->state(fn (Stock $record): string => DeadStockReport::formatLastMovement($record)),
            ExportColumn::make('days_inactive')
                ->label('Days Since Last Movement')
                ->state(fn (Stock $record): string => DeadStockReport::formatDaysInactive($record)),
            ExportColumn::make('stock_value')
                ->label('Stock Value')
                ->state(fn (Stock $record): float => DeadStockReport::stockValue($record)),
        ];
    }

    public static function modifyQuery(Builder $query): Builder
    {
        return $query->with(['stockable', 'location']);
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your dead stock export has completed and '.Number::format($export->successful_rows).' '.str('row')->plural($export->successful_rows).' exported.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.Number::format($failedRowsCount).' '.str('row')->plural($failedRowsCount).' failed to export.';
        }

        return $body;
    }
}
