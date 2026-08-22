<?php

namespace App\Filament\Resources\StockAdjustments\Pages;

use App\Enums\StockAdjustmentStatus;
use App\Filament\Resources\StockAdjustments\Actions\CancelStockAdjustmentAction;
use App\Filament\Resources\StockAdjustments\Actions\ConfirmStockAdjustmentAction;
use App\Filament\Resources\StockAdjustments\Actions\RetryStockAdjustmentAction;
use App\Filament\Resources\StockAdjustments\StockAdjustmentResource;
use App\Models\StockAdjustment;
use Filament\Resources\Pages\ViewRecord;

class ViewStockAdjustment extends ViewRecord
{
    protected static string $resource = StockAdjustmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ConfirmStockAdjustmentAction::make(),
            RetryStockAdjustmentAction::make(),
            CancelStockAdjustmentAction::make(),
        ];
    }

    public function getPollingInterval(): ?string
    {
        /** @var StockAdjustment $record */
        $record = $this->record;

        return $record->status === StockAdjustmentStatus::Processing ? '3s' : null;
    }
}
