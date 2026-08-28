<?php

namespace App\Jobs;

use Aldeebhasan\Inventorix\DTOs\StockOperationDto;
use Aldeebhasan\Inventorix\Enums\TransactionType;
use App\Enums\TransferOrderItemStatus;
use App\Enums\TransferOrderStatus;
use App\Models\TransferOrder;
use App\Models\TransferOrderItem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class DirectTransferItemsJob implements ShouldQueue
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
            $this->transferItem($item);
        }

        $this->syncStatus();
    }

    private function transferItem(TransferOrderItem $item): void
    {
        $item->update(['item_status' => TransferOrderItemStatus::Sending]);

        try {
            $dto = new StockOperationDto(
                transactionType: TransactionType::Transfer,
                causable: $item,
                reference: $this->order,
                cost: $item->product->cost,
                note: "TO #{$this->order->order_number}: direct transfer",
                createdBy: $this->order->created_by,
            );

            $item->product->transferStock(
                $item->quantity,
                $this->order->from_location_id,
                $this->order->to_location_id,
                $dto,
            );

            $item->update(['item_status' => TransferOrderItemStatus::Received]);
        } catch (Throwable $e) {
            $item->update([
                'item_status' => TransferOrderItemStatus::Failed,
                'failure_reason' => $e->getMessage(),
            ]);
        }
    }

    private function syncStatus(): void
    {
        $total = $this->order->items()->count();
        $received = $this->order->items()->where('item_status', TransferOrderItemStatus::Received)->count();
        $failed = $this->order->items()->where('item_status', TransferOrderItemStatus::Failed)->count();

        $this->order->update([
            'status' => match (true) {
                $received === $total => TransferOrderStatus::Completed,
                $failed > 0 => TransferOrderStatus::PartiallySent,
                default => TransferOrderStatus::Sending,
            },
        ]);
    }
}
