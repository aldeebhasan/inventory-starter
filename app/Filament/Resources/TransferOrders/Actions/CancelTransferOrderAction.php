<?php

namespace App\Filament\Resources\TransferOrders\Actions;

use App\Enums\TransferOrderStatus;
use App\Models\TransferOrder;
use Filament\Actions\Action;

class CancelTransferOrderAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'cancel';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->color('danger')
            ->visible(fn (TransferOrder $record) => in_array($record->status, [
                TransferOrderStatus::Draft,
                TransferOrderStatus::Confirmed,
            ]))
            ->requiresConfirmation()
            ->action(fn (TransferOrder $record) => $record->update(['status' => TransferOrderStatus::Cancelled]));
    }
}
