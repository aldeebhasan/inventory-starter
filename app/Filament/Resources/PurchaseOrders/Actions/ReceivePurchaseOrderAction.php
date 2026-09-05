<?php

namespace App\Filament\Resources\PurchaseOrders\Actions;

use Aldeebhasan\Inventorix\DTOs\StockOperationDto;
use Aldeebhasan\Inventorix\Enums\TransactionType;
use Aldeebhasan\Inventorix\Facades\Inventorix;
use Aldeebhasan\Inventorix\Models\Transaction;
use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use Filament\Actions\Action;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ReceivePurchaseOrderAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'receive';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->visible(fn (PurchaseOrder $record) => $record->status === PurchaseOrderStatus::Confirmed)
            ->requiresConfirmation()
            ->action(function (PurchaseOrder $record) {
                DB::transaction(function () use ($record) {
                    Inventorix::bulk(function (Transaction $tx) use ($record) {
                        foreach ($record->items as $item) {
                            $dto = new StockOperationDto(
                                transaction: $tx,
                                transactionType: TransactionType::Purchase,
                                causable: $record,
                                reference: $item,
                                cost: $item->unit_cost,
                                note: "PO #{$record->order_number}: receive stock",
                                createdBy: Auth::id(),
                            );
                            $convertedQty = $item->convertedQuantity();
                            if ($item->product->isInventory()) {
                                $item->product->addStock($convertedQty, $record->location_id, $dto);
                            }
                            $item->update(['received_quantity' => $convertedQty]);
                        }
                    });
                    $record->update(['status' => PurchaseOrderStatus::Received, 'received_at' => now()]);
                });
            });
    }
}
