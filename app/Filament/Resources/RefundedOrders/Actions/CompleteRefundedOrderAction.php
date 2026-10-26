<?php

namespace App\Filament\Resources\RefundedOrders\Actions;

use Aldeebhasan\Inventorix\DTOs\StockOperationDto;
use Aldeebhasan\Inventorix\Enums\TransactionType;
use Aldeebhasan\Inventorix\Facades\Inventorix;
use Aldeebhasan\Inventorix\Models\Transaction;
use App\Enums\ReturnOrderStatus;
use App\Models\ReturnOrder;
use Filament\Actions\Action;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CompleteRefundedOrderAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'complete';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->visible(fn (ReturnOrder $record) => $record->status === ReturnOrderStatus::Draft)
            ->requiresConfirmation()
            ->action(function (ReturnOrder $record) {
                DB::transaction(function () use ($record) {
                    $baseDto = new StockOperationDto(
                        transactionType: TransactionType::Sale,
                        causable: $record,
                        note: "CRT #{$record->order_number}: refund stock return",
                        createdBy: Auth::id(),
                    );
                    Inventorix::bulk(function (Transaction $tx) use ($record) {
                        foreach ($record->items as $item) {
                            $dto = new StockOperationDto(
                                transaction: $tx,
                                causable: $record,
                                reference: $item,
                                // Returned goods go back at what they cost us, not at the price they were sold for.
                                cost: $item->product->cost,
                                note: "CRT #{$record->order_number}: refund stock return",
                                createdBy: Auth::id(),
                            );
                            if ($item->product->isInventory()) {
                                $item->product->addStock($item->quantity, $record->location_id, $dto);
                            }
                        }
                    }, $baseDto);
                    $record->update(['status' => ReturnOrderStatus::Completed]);
                });
            });
    }
}
