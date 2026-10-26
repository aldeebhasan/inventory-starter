<?php

namespace App\Filament\Exports;

use Aldeebhasan\Inventorix\Models\Stock;
use App\Filament\Pages\Inventories;
use App\Models\Product;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Number;

/**
 * Exports the Stocks page. Threshold columns come from the subqueries the page's table query selects.
 */
class InventoriesExporter extends Exporter
{
    protected static ?string $model = Stock::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('stockable.name')->label('Product')->preventFormulaInjection(),
            ExportColumn::make('stockable.brand.name')->label('Brand')->preventFormulaInjection(),
            ExportColumn::make('categories')
                ->label('Categories')
                ->state(fn (Stock $record): string => $record->stockable?->getAttribute('categories')?->pluck('name')->join(', ') ?? '')
                ->preventFormulaInjection(),
            ExportColumn::make('location.name')->label('Location'),
            ExportColumn::make('quantity')->label('On Hand'),
            ExportColumn::make('reserved_quantity')->label('Reserved'),
            ExportColumn::make('available_quantity')
                ->label('Available')
                ->state(fn (Stock $record): float => $record->quantity - $record->reserved_quantity),
            ExportColumn::make('valuation')
                ->label('Valuation')
                ->state(fn (Stock $record): float => round(($record->quantity - $record->reserved_quantity) * (float) ($record->stockable?->getAttribute('cost') ?? 0), 2)),
            ExportColumn::make('threshold_min_quantity')->label('Min Threshold'),
            ExportColumn::make('threshold_max_quantity')->label('Max Threshold'),
            ExportColumn::make('stock_status')
                ->label('Status')
                ->state(fn (Stock $record): string => Inventories::stockStatus($record)),
        ];
    }

    public static function modifyQuery(Builder $query): Builder
    {
        return $query->with([
            'location',
            'stockable' => fn (Relation $relation) => $relation instanceof MorphTo ? $relation->morphWith([Product::class => ['brand', 'categories']]) : $relation,
        ]);
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your stocks export has completed and '.Number::format($export->successful_rows).' '.str('row')->plural($export->successful_rows).' exported.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.Number::format($failedRowsCount).' '.str('row')->plural($failedRowsCount).' failed to export.';
        }

        return $body;
    }
}
