<?php

namespace App\Filament\Resources\RefundedOrders\Actions;

use Aldeebhasan\Inventorix\DTOs\StockOperationDto;
use Aldeebhasan\Inventorix\Enums\TransactionType;
use Aldeebhasan\Inventorix\Facades\Inventorix;
use Aldeebhasan\Inventorix\Models\Transaction;
use App\Enums\ReturnOrderStatus;
use App\Models\ReturnOrder;
use App\Models\SaleOrder;
use App\Models\SaleOrderItem;
use Filament\Actions\Action;
use Illuminate\Database\Eloquent\Collection;
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
                    $saleOrderItems = $this->loadSaleOrderItems($record);

                    Inventorix::bulk(function (Transaction $tx) use ($record, $saleOrderItems) {
                        foreach ($record->items as $item) {
                            $saleItem = $saleOrderItems?->firstWhere('product_id', $item->product_id);
                            $cost = $saleItem?->unit_price ?? $item->product->cost;

                            $dto = new StockOperationDto(
                                transaction: $tx,
                                transactionType: TransactionType::Sale,
                                causable: $record,
                                reference: $item,
                                cost: $cost,
                                createdBy: Auth::id(),
                            );
                            if ($item->product->isInventory()) {
                                $item->product->addStock($item->quantity, $record->location_id, $dto);
                            }
                        }
                    });
                    $record->update(['status' => ReturnOrderStatus::Completed]);
                });
            });
    }

    private function loadSaleOrderItems(ReturnOrder $record): ?Collection
    {
        if ($record->original_order_type !== SaleOrder::class || ! $record->original_order_id) {
            return null;
        }

        return SaleOrderItem::query()
            ->where('sale_order_id', $record->original_order_id)
            ->get();
    }
}
