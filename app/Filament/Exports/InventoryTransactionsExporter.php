<?php

namespace App\Filament\Exports;

use Aldeebhasan\Inventorix\Models\Movement;
use App\Filament\Pages\InventoryTransactions;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Number;

class InventoryTransactionsExporter extends Exporter
{
    protected static ?string $model = Movement::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('stockable.name')->label('Product')->preventFormulaInjection(),
            ExportColumn::make('location.name')->label('Location'),
            ExportColumn::make('type')
                ->label('Type'),
            ExportColumn::make('quantity')->label('Qty'),
            ExportColumn::make('before_quantity')->label('Before'),
            ExportColumn::make('after_quantity')->label('After'),
            ExportColumn::make('cost_per_unit')->label('Cost / Unit'),
            ExportColumn::make('transaction.type')
                ->label('Transaction Type'),
            ExportColumn::make('source_document')
                ->label('Source Document')
                ->state(fn (Movement $record): ?string => InventoryTransactions::sourceDocument($record)),
            ExportColumn::make('note')->label('Note')->preventFormulaInjection(),
            ExportColumn::make('lot_reference')->label('Lot Ref')->preventFormulaInjection(),
            ExportColumn::make('external_reference')->label('External Ref')->preventFormulaInjection(),
            ExportColumn::make('created_at')->label('Date'),
        ];
    }

    public static function modifyQuery(Builder $query): Builder
    {
        return $query->with(['stockable', 'location', 'transaction.causable']);
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your stock movements export has completed and '.Number::format($export->successful_rows).' '.str('row')->plural($export->successful_rows).' exported.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.Number::format($failedRowsCount).' '.str('row')->plural($failedRowsCount).' failed to export.';
        }

        return $body;
    }
}
