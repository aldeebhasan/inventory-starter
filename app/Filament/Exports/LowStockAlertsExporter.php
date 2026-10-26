<?php

namespace App\Filament\Exports;

use Aldeebhasan\Inventorix\Models\Stock;
use App\Filament\Pages\LowStockAlerts;
use App\Models\Product;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Number;

/**
 * Exports the Low Stock Alerts page. Min threshold comes from the subquery the page's table query selects.
 */
class LowStockAlertsExporter extends Exporter
{
    protected static ?string $model = Stock::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('stockable.name')->label('Product')->preventFormulaInjection(),
            ExportColumn::make('stockable.brand.name')->label('Brand')->preventFormulaInjection(),
            ExportColumn::make('location.name')->label('Location'),
            ExportColumn::make('quantity')->label('On Hand'),
            ExportColumn::make('reserved_quantity')->label('Reserved'),
            ExportColumn::make('available')
                ->label('Available')
                ->state(fn (Stock $record): float => $record->quantity - $record->reserved_quantity),
            ExportColumn::make('min_threshold_quantity')->label('Min Threshold'),
            ExportColumn::make('deficit')
                ->label('Deficit')
                ->state(fn (Stock $record): ?float => LowStockAlerts::deficit($record)),
        ];
    }

    public static function modifyQuery(Builder $query): Builder
    {
        return $query->with([
            'location',
            'stockable' => fn (Relation $relation) => $relation instanceof MorphTo ? $relation->morphWith([Product::class => ['brand']]) : $relation,
        ]);
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your low stock alerts export has completed and '.Number::format($export->successful_rows).' '.str('row')->plural($export->successful_rows).' exported.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.Number::format($failedRowsCount).' '.str('row')->plural($failedRowsCount).' failed to export.';
        }

        return $body;
    }
}
