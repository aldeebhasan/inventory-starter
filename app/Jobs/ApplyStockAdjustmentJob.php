<?php

namespace App\Jobs;

use Aldeebhasan\Inventorix\DTOs\StockOperationDto;
use Aldeebhasan\Inventorix\Enums\TransactionType;
use App\Enums\StockAdjustmentItemStatus;
use App\Enums\StockAdjustmentOperation;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentItem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ApplyStockAdjustmentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(public StockAdjustment $order) {}

    public function handle(): void
    {
        $items = $this->order->items()
            ->where('item_status', StockAdjustmentItemStatus::Pending)
            ->get();

        foreach ($items as $item) {
            $this->applyItem($item);
        }

        $this->order->syncStatusFromItems();
    }

    private function applyItem(StockAdjustmentItem $item): void
    {
        $item->update(['item_status' => StockAdjustmentItemStatus::Processing]);

        try {
            $dto = new StockOperationDto(
                transactionType: TransactionType::Adjustment,
                causable: $this->order,
                reference: $item,
                note: "ADJ #{$this->order->order_number}: {$this->order->reason}",
                createdBy: $this->order->created_by,
            );

            match ($item->operation) {
                StockAdjustmentOperation::Increase => $item->product->addStock($item->quantity, $this->order->location_id, $dto),
                StockAdjustmentOperation::Decrease => $item->product->deductStock($item->quantity, $this->order->location_id, $dto),
                StockAdjustmentOperation::Adjust => $item->product->adjustStock($item->quantity, $this->order->location_id, $dto),
            };

            $item->update(['item_status' => StockAdjustmentItemStatus::Applied]);
        } catch (Throwable $e) {
            $item->update([
                'item_status' => StockAdjustmentItemStatus::Failed,
                'failure_reason' => $e->getMessage(),
            ]);
        }
    }
}
