<?php

namespace App\Filament\Resources\SupplierReturns\Actions;

use Aldeebhasan\Inventorix\DTOs\StockOperationDto;
use Aldeebhasan\Inventorix\Enums\TransactionType;
use Aldeebhasan\Inventorix\Facades\Inventorix;
use Aldeebhasan\Inventorix\Models\Transaction;
use App\Enums\ReturnOrderStatus;
use App\Models\ReturnOrder;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CancelSupplierReturnAction extends Action
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
                ReturnOrderStatus::Approved,
                ReturnOrderStatus::Completed,
            ]))
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription(fn (ReturnOrder $record) => $record->status === ReturnOrderStatus::Completed
                ? 'The returned stock will be added back to the warehouse.'
                : null)
            ->schema([
                Textarea::make('reason')->label('Cancellation Reason'),
            ])
            ->action(function (ReturnOrder $record, array $data) {
                $record->loadMissing('items.product', 'items.unit');

                DB::transaction(function () use ($record, $data) {
                    // Stock only leaves on Complete, so only a Completed return has stock to put back.
                    if ($record->status === ReturnOrderStatus::Completed) {
                        $this->restoreStock($record);
                    }

                    $record->logStatusChange($record->status, ReturnOrderStatus::Cancelled, reason: $data['reason'] ?? null);
                    $record->updateQuietly(['status' => ReturnOrderStatus::Cancelled]);
                });
            });
    }

    protected function restoreStock(ReturnOrder $record): void
    {
        $note = "Cancel SRT #{$record->order_number}: restore returned stock";

        $baseDto = new StockOperationDto(
            transactionType: TransactionType::Reversal,
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
                    reference: $item,
                    // Restored stock is valued at product cost, the same basis as refunded stock.
                    cost: $item->product->cost,
                    note: $note,
                    createdBy: Auth::id(),
                );
                $item->product->addStock($item->convertedQuantity(), $record->location_id, $dto);
            }
        }, $baseDto);
    }
}
