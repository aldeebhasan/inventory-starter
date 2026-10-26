<?php

namespace App\Filament\Exports;

use Aldeebhasan\Inventorix\Models\Stock;
use App\Filament\Pages\OverstockAlerts;
use App\Models\Product;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Number;

/**
 * Exports the Overstock Alerts page. Max threshold comes from the subquery the page's table query selects.
 */
class OverstockAlertsExporter extends Exporter
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
            ExportColumn::make('max_threshold_quantity')->label('Max Threshold'),
            ExportColumn::make('surplus')
                ->label('Surplus')
                ->state(fn (Stock $record): ?float => OverstockAlerts::surplus($record)),
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
        $body = 'Your overstock alerts export has completed and '.Number::format($export->successful_rows).' '.str('row')->plural($export->successful_rows).' exported.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.Number::format($failedRowsCount).' '.str('row')->plural($failedRowsCount).' failed to export.';
        }

        return $body;
    }
}
