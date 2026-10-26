<?php

namespace App\Filament\Exports;

use App\Filament\Pages\CustomerReturnsAnalysis;
use App\Models\Product;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Support\Number;

/**
 * Exports the Customer Returns Analysis report rows (one aggregated row per product).
 */
class CustomerReturnsAnalysisExporter extends Exporter
{
    protected static ?string $model = Product::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('name')->label('Product'),
            ExportColumn::make('qty_returned')->label('Qty Returned'),
            ExportColumn::make('qty_sold')->label('Qty Sold (Period)'),
            ExportColumn::make('return_rate')
                ->label('Return Rate %')
                ->state(fn (Product $record): ?float => ($rate = CustomerReturnsAnalysis::returnRate($record)) === null ? null : round($rate, 1)),
            ExportColumn::make('refund_amount')->label('Refund Amount'),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your customer returns analysis export has completed and '.Number::format($export->successful_rows).' '.str('row')->plural($export->successful_rows).' exported.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.Number::format($failedRowsCount).' '.str('row')->plural($failedRowsCount).' failed to export.';
        }

        return $body;
    }
}
