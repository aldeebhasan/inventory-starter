<?php

namespace App\Filament\Exports;

use App\Models\Product;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Support\Number;

/**
 * Exports the Sales Summary report rows (one aggregated row per product).
 */
class SalesSummaryExporter extends Exporter
{
    protected static ?string $model = Product::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('name')->label('Product'),
            ExportColumn::make('brand.name')->label('Brand'),
            ExportColumn::make('category')
                ->label('Category')
                ->state(fn (Product $record): ?string => $record->categories->first()?->name),
            ExportColumn::make('qty_sold')->label('Qty Sold'),
            ExportColumn::make('avg_unit_price')->label('Avg Unit Price'),
            ExportColumn::make('total_revenue')->label('Total Revenue'),
            ExportColumn::make('total_cost')->label('Total Cost'),
            ExportColumn::make('gross_margin')
                ->label('Gross Margin')
                ->state(fn (Product $record): float => round((float) $record->getAttribute('total_revenue') - (float) $record->getAttribute('total_cost'), 2)),
            ExportColumn::make('margin_pct')
                ->label('Margin %')
                ->state(function (Product $record): float {
                    $revenue = (float) $record->getAttribute('total_revenue');

                    return $revenue <= 0 ? 0.0 : round((($revenue - (float) $record->getAttribute('total_cost')) / $revenue) * 100, 1);
                }),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your sales summary export has completed and '.Number::format($export->successful_rows).' '.str('row')->plural($export->successful_rows).' exported.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.Number::format($failedRowsCount).' '.str('row')->plural($failedRowsCount).' failed to export.';
        }

        return $body;
    }
}
