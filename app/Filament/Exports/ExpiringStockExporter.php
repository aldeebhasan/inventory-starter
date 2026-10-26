<?php

namespace App\Filament\Exports;

use Aldeebhasan\Inventorix\Models\Movement;
use App\Filament\Pages\ExpiringStock;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Number;

/**
 * Exports the Expiring Stock report (one row per lot). The "Expiring Soon" threshold comes from the
 * `days_threshold` export option set by the page.
 */
class ExpiringStockExporter extends Exporter
{
    protected static ?string $model = Movement::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('product')
                ->label('Product')
                ->state(fn (Movement $record): ?string => $record->stockable?->getAttribute('name')),
            ExportColumn::make('location.name')->label('Location'),
            ExportColumn::make('lot_reference')->label('Lot Reference'),
            ExportColumn::make('qty_remaining')
                ->label('Qty Remaining')
                ->state(fn (Movement $record): float => ExpiringStock::qtyRemaining($record)),
            ExportColumn::make('cost_per_unit')->label('Cost/Unit'),
            ExportColumn::make('expires_at')
                ->label('Expires At')
                ->state(fn (Movement $record): ?string => $record->expires_at?->toDateString()),
            ExportColumn::make('days_until_expiry')
                ->label('Days Until Expiry')
                ->state(fn (Movement $record): int => ExpiringStock::daysUntilExpiry($record)),
            ExportColumn::make('expiry_status')
                ->label('Status')
                ->state(fn (Movement $record, array $options): string => ExpiringStock::status($record, (int) ($options['days_threshold'] ?? 30))),
        ];
    }

    public static function modifyQuery(Builder $query): Builder
    {
        return $query->with(['stockable', 'location']);
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your expiring stock export has completed and '.Number::format($export->successful_rows).' '.str('row')->plural($export->successful_rows).' exported.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.Number::format($failedRowsCount).' '.str('row')->plural($failedRowsCount).' failed to export.';
        }

        return $body;
    }
}
