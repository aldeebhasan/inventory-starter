<?php

namespace App\Filament\Resources\RefundedOrders\Actions;

use Aldeebhasan\Inventorix\DTOs\StockOperationDto;
use Aldeebhasan\Inventorix\Enums\TransactionType;
use App\Enums\ReturnOrderStatus;
use App\Models\ReturnOrder;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CancelRefundedOrderAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'cancel';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->visible(fn (ReturnOrder $record) => in_array($record->status, [
                ReturnOrderStatus::Draft,
                ReturnOrderStatus::Completed,
            ]))
            ->color('danger')
            ->requiresConfirmation()
            ->schema([
                Textarea::make('reason')->label('Cancellation Reason'),
            ])
            ->action(function (ReturnOrder $record, array $data) {
                DB::transaction(function () use ($record, $data) {
                    if ($record->status === ReturnOrderStatus::Completed) {
                        foreach ($record->items as $item) {
                            $dto = new StockOperationDto(
                                transactionType: TransactionType::Reversal,
                                causable: $record,
                                reference: $item,
                                cost: $item->product->cost,
                                note: "Cancel CRT #{$record->order_number}",
                                createdBy: Auth::id(),
                            );
                            if ($item->product->isInventory()) {
                                $item->product->deductStock($item->quantity, $record->location_id, $dto);
                            }
                        }
                    }

                    $record->logStatusChange($record->status, ReturnOrderStatus::Cancelled, reason: $data['reason'] ?? null);
                    $record->updateQuietly(['status' => ReturnOrderStatus::Cancelled]);
                });
            });
    }
}
