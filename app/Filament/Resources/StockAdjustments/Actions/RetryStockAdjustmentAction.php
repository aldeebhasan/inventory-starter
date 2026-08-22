<?php

namespace App\Filament\Resources\StockAdjustments\Actions;

use App\Enums\StockAdjustmentItemStatus;
use App\Enums\StockAdjustmentStatus;
use App\Jobs\ApplyStockAdjustmentJob;
use App\Models\StockAdjustment;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

class RetryStockAdjustmentAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'retry';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->visible(fn (StockAdjustment $record) => $record->status === StockAdjustmentStatus::PartiallyApplied)
            ->color('warning')
            ->requiresConfirmation()
            ->action(function (StockAdjustment $record) {
                $record->items()
                    ->where('item_status', StockAdjustmentItemStatus::Failed)
                    ->update([
                        'item_status' => StockAdjustmentItemStatus::Pending,
                        'failure_reason' => null,
                    ]);

                $record->update(['status' => StockAdjustmentStatus::Processing]);

                ApplyStockAdjustmentJob::dispatch($record->fresh())->onQueue('inventory');

                Notification::make()
                    ->success()
                    ->title('Failed lines re-queued for processing')
                    ->send();
            });
    }
}
