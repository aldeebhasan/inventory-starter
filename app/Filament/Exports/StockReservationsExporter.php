<?php

namespace App\Filament\Exports;

use Aldeebhasan\Inventorix\Models\Reservation;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Number;

class StockReservationsExporter extends Exporter
{
    protected static ?string $model = Reservation::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('stockable.name')->label('Product')->preventFormulaInjection(),
            ExportColumn::make('location.name')->label('Location'),
            ExportColumn::make('quantity')->label('Qty'),
            ExportColumn::make('status')
                ->label('Status'),
            ExportColumn::make('note')->label('Note')->preventFormulaInjection(),
            ExportColumn::make('expires_at')->label('Expires At'),
            ExportColumn::make('created_at')->label('Date'),
        ];
    }

    public static function modifyQuery(Builder $query): Builder
    {
        return $query->with(['stockable', 'location']);
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your stock reservations export has completed and '.Number::format($export->successful_rows).' '.str('row')->plural($export->successful_rows).' exported.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.Number::format($failedRowsCount).' '.str('row')->plural($failedRowsCount).' failed to export.';
        }

        return $body;
    }
}
