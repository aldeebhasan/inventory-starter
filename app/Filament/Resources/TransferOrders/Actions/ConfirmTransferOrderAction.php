<?php

namespace App\Filament\Resources\TransferOrders\Actions;

use App\Enums\TransferOrderItemStatus;
use App\Enums\TransferOrderStatus;
use App\Models\TransferOrder;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

class ConfirmTransferOrderAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'confirm';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->visible(fn (TransferOrder $record) => $record->status === TransferOrderStatus::Draft)
            ->requiresConfirmation()
            ->action(function (TransferOrder $record, Action $action) {
                $errors = [];

                foreach ($record->items as $item) {
                    $stock = $item->product->stockAt($record->from_location_id)?->quantity ?? 0;
                    if ($stock < $item->quantity) {
                        $errors[] = "{$item->product->name}: insufficient stock ({$stock} available, {$item->quantity} requested)";
                    }
                }

                if (! empty($errors)) {
                    Notification::make()
                        ->danger()
                        ->title('Cannot confirm — insufficient stock')
                        ->body(implode("\n", $errors))
                        ->send();
                    $action->halt();

                    return;
                }

                $record->update(['status' => TransferOrderStatus::Confirmed]);
                $record->items()->update(['item_status' => TransferOrderItemStatus::Pending]);

                Notification::make()->success()->title('Transfer order confirmed')->send();
            });
    }
}
