<?php

namespace App\Filament\Resources\SupplierReturns\Actions;

use Aldeebhasan\Inventorix\DTOs\StockOperationDto;
use Aldeebhasan\Inventorix\Enums\TransactionType;
use Aldeebhasan\Inventorix\Facades\Inventorix;
use Aldeebhasan\Inventorix\Models\Transaction;
use App\Enums\ReturnOrderStatus;
use App\Models\ReturnOrder;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CompleteSupplierReturnAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'complete';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->visible(fn (ReturnOrder $record) => $record->status === ReturnOrderStatus::Approved)
            ->requiresConfirmation()
            ->action(function (ReturnOrder $record, Action $action) {
                $record->loadMissing('items.product', 'items.unit');

                $errors = $this->insufficientStockErrors($record);

                if ($errors !== []) {
                    Notification::make()
                        ->danger()
                        ->title('Cannot complete — insufficient stock')
                        ->body(implode("\n", $errors))
                        ->send();
                    $action->halt();

                    return;
                }

                DB::transaction(function () use ($record) {
                    $note = "SRT #{$record->order_number}: return stock to supplier";

                    $baseDto = new StockOperationDto(
                        transactionType: TransactionType::Purchase,
                        causable: $record,
                        note: $note,
                        createdBy: Auth::id(),
                    );
                    Inventorix::bulk(function (Transaction $tx) use ($record, $note) {
                        foreach ($record->items as $item) {
                            if (! $item->product->isInventory()) {
                                continue;
                            }

                            $dto = new StockOperationDto(
                                transaction: $tx,
                                causable: $record,
                                // No cost: Inventorix values outbound stock from the lots it consumes (FIFO), not from the DTO.
                                reference: $item,
                                note: $note,
                                createdBy: Auth::id(),
                            );
                            $item->product->deductStock($item->convertedQuantity(), $record->location_id, $dto);
                        }
                    }, $baseDto);
                    $record->update(['status' => ReturnOrderStatus::Completed]);
                });

                Notification::make()->success()->title('Supplier return completed')->send();
            });
    }

    /**
     * Lines whose available (unreserved) stock at the return's location cannot cover the returned quantity.
     *
     * @return list<string>
     */
    protected function insufficientStockErrors(ReturnOrder $record): array
    {
        $required = [];

        foreach ($record->items as $item) {
            if ($item->product->isInventory()) {
                $required[$item->product_id] = ($required[$item->product_id] ?? 0) + $item->convertedQuantity();
            }
        }

        $errors = [];

        foreach ($required as $productId => $quantity) {
            $product = $record->items->firstWhere('product_id', $productId)->product;
            $stock = $product->stockAt($record->location_id);
            $available = $stock ? $stock->quantity - $stock->reserved_quantity : 0;

            if ($available < $quantity) {
                $errors[] = "{$product->name}: insufficient stock ({$available} available, {$quantity} requested)";
            }
        }

        return $errors;
    }
}
