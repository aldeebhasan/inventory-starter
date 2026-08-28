<?php

namespace App\Jobs;

use Aldeebhasan\Inventorix\DTOs\StockOperationDto;
use Aldeebhasan\Inventorix\Enums\TransactionType;
use Aldeebhasan\Inventorix\Models\Transaction;
use App\Enums\TransferOrderItemStatus;
use App\Models\TransferOrder;
use App\Models\TransferOrderItem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ReceiveTransferItemsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(public TransferOrder $order) {}

    public function handle(): void
    {
        $items = TransferOrderItem::query()
            ->where('transfer_order_id', $this->order->id)
            ->where('item_status', TransferOrderItemStatus::Sent)
            ->get();

        foreach ($items as $item) {
            $this->receiveItem($item);
        }

        $this->order->syncStatusFromItems('receive');
    }

    private function receiveItem(TransferOrderItem $item): void
    {
        $item->update(['item_status' => TransferOrderItemStatus::Receiving]);

        try {
            $transaction = Transaction::query()->where('causable_type', TransferOrderItem::class)
                ->where('causable_id', $item->id)
                ->where('type', TransactionType::Transfer)
                ->firstOrFail();

            $dto = new StockOperationDto(
                transactionType: TransactionType::Transfer,
                causable: $item,
                reference: $this->order,
                cost: $item->product->cost,
                note: "TO #{$this->order->order_number}: receive at {$this->order->toLocation->name}",
                createdBy: $this->order->created_by,
            );

            $item->product->receiveStock($transaction, $item->quantity, $this->order->to_location_id, $dto);

            $item->update(['item_status' => TransferOrderItemStatus::Received]);
        } catch (Throwable $e) {
            $item->update([
                'item_status' => TransferOrderItemStatus::Failed,
                'failure_reason' => $e->getMessage(),
            ]);
        }
    }
}
