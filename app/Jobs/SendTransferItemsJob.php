<?php

namespace App\Jobs;

use Aldeebhasan\Inventorix\DTOs\StockOperationDto;
use Aldeebhasan\Inventorix\Enums\TransactionType;
use App\Enums\TransferOrderItemStatus;
use App\Models\TransferOrder;
use App\Models\TransferOrderItem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SendTransferItemsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(public TransferOrder $order) {}

    public function handle(): void
    {
        $items = TransferOrderItem::query()
            ->where('transfer_order_id', $this->order->id)
            ->where('item_status', TransferOrderItemStatus::Pending)
            ->get();

        foreach ($items as $item) {
            $this->sendItem($item);
        }

        $this->order->syncStatusFromItems('send');
    }

    private function sendItem(TransferOrderItem $item): void
    {
        $item->update(['item_status' => TransferOrderItemStatus::Sending]);

        try {
            $dto = new StockOperationDto(
                transactionType: TransactionType::Transfer,
                causable: $item,
                reference: $this->order,
                cost: $item->product->cost,
                note: "TO #{$this->order->order_number}: send from {$this->order->fromLocation->name}",
                createdBy: $this->order->created_by,
            );

            $item->product->sendStock($item->quantity, $this->order->from_location_id, $dto);

            $item->update(['item_status' => TransferOrderItemStatus::Sent]);
        } catch (Throwable $e) {
            $item->update([
                'item_status' => TransferOrderItemStatus::Failed,
                'failure_reason' => $e->getMessage(),
            ]);
        }
    }
}
