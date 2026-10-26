<?php

namespace App\Filament\Exports;

use App\Models\Supplier;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Support\Number;

/**
 * Exports the Supplier Performance report rows (one row per supplier with PO metrics).
 */
class SupplierPerformanceExporter extends Exporter
{
    protected static ?string $model = Supplier::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('name')->label('Supplier'),
            ExportColumn::make('total_pos')->label('Total POs'),
            ExportColumn::make('received_pos')->label('Received'),
            ExportColumn::make('cancelled_pos')->label('Cancelled'),
            ExportColumn::make('fulfillment_rate')
                ->label('Fulfillment Rate %')
                ->state(function (Supplier $record): float {
                    $total = (int) $record->getAttribute('total_pos');

                    return $total === 0 ? 0.0 : round(((int) $record->getAttribute('received_pos') / $total) * 100, 1);
                }),
            ExportColumn::make('avg_lead_time')
                ->label('Avg Lead Time (days)')
                ->state(fn (Supplier $record): ?float => $record->getAttribute('avg_lead_time') === null ? null : round((float) $record->getAttribute('avg_lead_time'), 1)),
            ExportColumn::make('total_items')
                ->label('Total Items')
                ->state(fn (Supplier $record): float => (float) ($record->getAttribute('total_items') ?? 0)),
            ExportColumn::make('total_spend')
                ->label('Total Spend')
                ->state(fn (Supplier $record): float => round((float) ($record->getAttribute('total_spend') ?? 0), 2)),
            ExportColumn::make('avg_unit_cost')
                ->label('Avg Unit Cost')
                ->state(function (Supplier $record): ?float {
                    $items = (float) ($record->getAttribute('total_items') ?? 0);

                    return $items <= 0 ? null : round((float) $record->getAttribute('total_spend') / $items, 3);
                }),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your supplier performance export has completed and '.Number::format($export->successful_rows).' '.str('row')->plural($export->successful_rows).' exported.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.Number::format($failedRowsCount).' '.str('row')->plural($failedRowsCount).' failed to export.';
        }

        return $body;
    }
}
