<?php

namespace App\Filament\Resources\TransferOrders\Actions;

use App\Enums\TransferOrderStatus;
use App\Jobs\SendTransferItemsJob;
use App\Models\TransferOrder;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

class SendTransferOrderAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'send';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->visible(fn (TransferOrder $record) => $record->status === TransferOrderStatus::Confirmed)
            ->requiresConfirmation()
            ->action(function (TransferOrder $record) {
                $record->update(['status' => TransferOrderStatus::Sending]);

                SendTransferItemsJob::dispatch($record->fresh())->onQueue('inventory');

                Notification::make()->success()->title('Sending stock from source location')->send();
            });
    }
}
