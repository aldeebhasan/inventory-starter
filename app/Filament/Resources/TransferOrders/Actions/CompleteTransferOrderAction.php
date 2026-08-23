<?php

namespace App\Filament\Resources\TransferOrders\Actions;

use App\Enums\TransferOrderStatus;
use App\Jobs\DirectTransferItemsJob;
use App\Models\TransferOrder;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

class CompleteTransferOrderAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'complete';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label('Direct Transfer')
            ->icon('heroicon-o-bolt')
            ->visible(fn (TransferOrder $record) => $record->status === TransferOrderStatus::Confirmed)
            ->requiresConfirmation()
            ->modalDescription('This will transfer all items from source to destination without the send/receive workflow.')
            ->action(function (TransferOrder $record) {
                $record->update(['status' => TransferOrderStatus::Sending]);

                DirectTransferItemsJob::dispatch($record->fresh())->onQueue('inventory');

                Notification::make()->success()->title('Direct transfer queued for processing')->send();
            });
    }
}
