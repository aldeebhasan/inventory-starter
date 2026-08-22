<?php

namespace App\Filament\Resources\StockAdjustments\Actions;

use App\Enums\StockAdjustmentStatus;
use App\Models\StockAdjustment;
use Filament\Actions\Action;

class CancelStockAdjustmentAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'cancel';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->visible(fn (StockAdjustment $record) => $record->status === StockAdjustmentStatus::Draft)
            ->color('danger')
            ->requiresConfirmation()
            ->action(fn (StockAdjustment $record) => $record->update(['status' => StockAdjustmentStatus::Cancelled]));
    }
}
