<?php

namespace App\Filament\Resources\TransferOrders\Actions;

use App\Enums\TransferOrderItemStatus;
use App\Enums\TransferOrderStatus;
use App\Jobs\SendTransferItemsJob;
use App\Models\TransferOrder;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

class RetrySendTransferOrderAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'retry_send';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label('Retry Failed')
            ->color('warning')
            ->visible(fn (TransferOrder $record) => $record->status === TransferOrderStatus::PartiallySent)
            ->requiresConfirmation()
            ->action(function (TransferOrder $record) {
                $record->items()
                    ->where('item_status', TransferOrderItemStatus::Failed)
                    ->update(['item_status' => TransferOrderItemStatus::Pending, 'failure_reason' => null]);

                $record->update(['status' => TransferOrderStatus::Sending]);

                SendTransferItemsJob::dispatch($record->fresh())->onQueue('inventory');

                Notification::make()->success()->title('Failed items re-queued for sending')->send();
            });
    }
}
