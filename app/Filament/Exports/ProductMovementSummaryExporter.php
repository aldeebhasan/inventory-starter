<?php

namespace App\Filament\Exports;

use App\Filament\Pages\ProductMovementSummary;
use App\Models\Product;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Number;

/**
 * Exports the Product Movement Summary. The export query is the page's filtered table query, which
 * selects `total_added`, `total_deducted` and `current_stock` as subqueries, so no per-row queries.
 */
class ProductMovementSummaryExporter extends Exporter
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
            ExportColumn::make('total_added')
                ->label('Total Added')
                ->state(fn (Product $record): float => (float) $record->getAttribute('total_added')),
            ExportColumn::make('total_deducted')
                ->label('Total Deducted')
                ->state(fn (Product $record): float => (float) $record->getAttribute('total_deducted')),
            ExportColumn::make('net_change')
                ->label('Net Change')
                ->state(fn (Product $record): float => ProductMovementSummary::netChange($record)),
            ExportColumn::make('current_stock')
                ->label('Current Stock')
                ->state(fn (Product $record): float => (float) $record->getAttribute('current_stock')),
            ExportColumn::make('turnover_rate')
                ->label('Turnover Rate')
                ->state(fn (Product $record): string => ProductMovementSummary::turnoverRate($record)),
        ];
    }

    public static function modifyQuery(Builder $query): Builder
    {
        return $query->with(['brand', 'categories']);
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your product movement summary export has completed and '.Number::format($export->successful_rows).' '.str('row')->plural($export->successful_rows).' exported.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.Number::format($failedRowsCount).' '.str('row')->plural($failedRowsCount).' failed to export.';
        }

        return $body;
    }
}
