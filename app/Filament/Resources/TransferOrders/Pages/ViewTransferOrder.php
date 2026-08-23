<?php

namespace App\Filament\Resources\TransferOrders\Pages;

use App\Enums\TransferOrderStatus;
use App\Filament\Resources\TransferOrders\Actions\CancelTransferOrderAction;
use App\Filament\Resources\TransferOrders\Actions\CompleteTransferOrderAction;
use App\Filament\Resources\TransferOrders\Actions\ConfirmTransferOrderAction;
use App\Filament\Resources\TransferOrders\Actions\ReceiveTransferOrderAction;
use App\Filament\Resources\TransferOrders\Actions\RetryReceiveTransferOrderAction;
use App\Filament\Resources\TransferOrders\Actions\RetrySendTransferOrderAction;
use App\Filament\Resources\TransferOrders\Actions\SendTransferOrderAction;
use App\Filament\Resources\TransferOrders\TransferOrderResource;
use App\Models\TransferOrder;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewTransferOrder extends ViewRecord
{
    protected static string $resource = TransferOrderResource::class;

    protected function getHeaderActions(): array
    {
        /** @var TransferOrder $record */
        $record = $this->record;

        return [
            EditAction::make()->visible($record->status === TransferOrderStatus::Draft),
            ConfirmTransferOrderAction::make(),
            CompleteTransferOrderAction::make(),
            SendTransferOrderAction::make(),
            ReceiveTransferOrderAction::make(),
            RetrySendTransferOrderAction::make(),
            RetryReceiveTransferOrderAction::make(),
            CancelTransferOrderAction::make(),
        ];
    }

    public function getPollingInterval(): ?string
    {
        /** @var TransferOrder $record */
        $record = $this->record;

        return ! in_array($record->status, [TransferOrderStatus::Draft, TransferOrderStatus::Confirmed, TransferOrderStatus::Cancelled]) ? '3s' : null;
    }
}
