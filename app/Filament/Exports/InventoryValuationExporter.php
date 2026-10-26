<?php

namespace App\Filament\Exports;

use Aldeebhasan\Inventorix\Models\Stock;
use App\Filament\Pages\InventoryValuation;
use App\Models\Product;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Number;

class InventoryValuationExporter extends Exporter
{
    protected static ?string $model = Stock::class;

    /** Grand total cached per exporter instance (one SQL aggregate per export chunk). */
    protected ?float $grandTotal = null;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('stockable.name')->label('Product')->preventFormulaInjection(),
            ExportColumn::make('stockable.brand.name')->label('Brand')->preventFormulaInjection(),
            ExportColumn::make('category')
                ->label('Category')
                ->state(fn (Stock $record): string => $record->stockable?->getAttribute('categories')?->first()->name ?? '')
                ->preventFormulaInjection(),
            ExportColumn::make('location.name')->label('Location'),
            ExportColumn::make('quantity')->label('Qty On Hand'),
            ExportColumn::make('available')
                ->label('Available')
                ->state(fn (Stock $record): float => $record->quantity - $record->reserved_quantity),
            ExportColumn::make('avg_cost')
                ->label('Avg Cost/Unit')
                ->state(fn (Stock $record): float => (float) ($record->stockable?->getAttribute('cost') ?? 0)),
            ExportColumn::make('total_value')
                ->label('Total Value')
                ->state(fn (Stock $record): float => static::totalValue($record)),
            ExportColumn::make('pct_of_total')
                ->label('% of Total')
                ->state(function (Stock $record, InventoryValuationExporter $exporter): float {
                    $grandTotal = $exporter->getGrandTotal();

                    return $grandTotal > 0 ? round(static::totalValue($record) / $grandTotal * 100, 2) : 0.0;
                }),
        ];
    }

    public static function totalValue(Stock $record): float
    {
        return round(($record->quantity - $record->reserved_quantity) * (float) ($record->stockable?->getAttribute('cost') ?? 0), 2);
    }

    public function getGrandTotal(): float
    {
        return $this->grandTotal ??= InventoryValuation::grandTotal();
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
        $body = 'Your inventory valuation export has completed and '.Number::format($export->successful_rows).' '.str('row')->plural($export->successful_rows).' exported.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.Number::format($failedRowsCount).' '.str('row')->plural($failedRowsCount).' failed to export.';
        }

        return $body;
    }
}
