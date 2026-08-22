<?php

namespace App\Filament\Resources\StockAdjustments\Actions;

use App\Enums\StockAdjustmentItemStatus;
use App\Enums\StockAdjustmentOperation;
use App\Enums\StockAdjustmentStatus;
use App\Jobs\ApplyStockAdjustmentJob;
use App\Models\StockAdjustment;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

class ConfirmStockAdjustmentAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'confirm';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->visible(fn (StockAdjustment $record) => $record->status === StockAdjustmentStatus::Draft)
            ->requiresConfirmation()
            ->action(function (StockAdjustment $record, Action $action) {
                $errors = [];

                foreach ($record->items as $item) {
                    $stock = $item->product->stockAt($record->location_id)?->quantity ?? 0;
                    $item->update(['current_stock' => $stock]);

                    $valid = match ($item->operation) {
                        StockAdjustmentOperation::Increase => $item->quantity > 0,
                        StockAdjustmentOperation::Decrease => $item->quantity > 0 && $stock >= $item->quantity,
                        StockAdjustmentOperation::Adjust => $item->quantity >= 0,
                    };

                    if (! $valid) {
                        $errors[] = "{$item->product->name}: insufficient stock ({$stock} available, {$item->quantity} requested)";
                    }
                }

                if (! empty($errors)) {
                    return;
                }

                $record->update(['status' => StockAdjustmentStatus::Processing]);
                $record->items()->update(['item_status' => StockAdjustmentItemStatus::Pending]);

                if (! empty($errors)) {
                    Notification::make()
                        ->danger()
                        ->title('Cannot confirm — validation failed')
                        ->body(implode("\n", $errors))
                        ->send();

                    $action->halt();

                    return;
                }

                ApplyStockAdjustmentJob::dispatch($record->fresh())->onQueue('inventory');

                Notification::make()
                    ->success()
                    ->title('Order confirmed and queued for processing')
                    ->send();
            });
    }
}
