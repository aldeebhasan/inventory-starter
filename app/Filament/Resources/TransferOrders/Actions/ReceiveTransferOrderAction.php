<?php

namespace App\Filament\Resources\TransferOrders\Actions;

use App\Enums\TransferOrderStatus;
use App\Jobs\ReceiveTransferItemsJob;
use App\Models\TransferOrder;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

class ReceiveTransferOrderAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'receive';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->visible(fn (TransferOrder $record) => $record->status === TransferOrderStatus::InTransit)
            ->requiresConfirmation()
            ->action(function (TransferOrder $record) {
                $record->update(['status' => TransferOrderStatus::Receiving]);

                ReceiveTransferItemsJob::dispatch($record->fresh())->onQueue('inventory');

                Notification::make()->success()->title('Receiving stock at destination location')->send();
            });
    }
}
